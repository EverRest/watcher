<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Watcher\Level;
use Watcher\Schedule\SystemClock;
use Watcher\Schedule\Window;

/** Reads a window from Loki: server-side totals, server-filtered forward-paged lines, version labels. */
final class LokiSource implements LogSource, ReadDiagnostics
{
    private const PAGE_LIMIT = 5000;
    private const MIN_PAGE_LIMIT = 100;
    /** Most backward pages the newest-share read may take (A4-03). */
    private const TAIL_PAGES = 20;
    private const VERSION_STEP = 3600;

    private readonly string $baseUrl;
    /** @var \Closure(): array<string, string> request headers incl. the credential: a closure, so var_export shows no value */
    private readonly \Closure $headers;
    private readonly Retry $retry;
    private bool $truncated = false;
    private int $pageLimit = self::PAGE_LIMIT;

    /** Times a page tripped the response cap and was re-read with half the limit during the last lines() call. */
    public int $pagesShrunk = 0;

    /** Entries skipped as unusable (see {@see RemoteValue}) during the last lines() call; totals() and versions() add to it. */
    private int $malformed = 0;
    private LineParser $parser;
    private readonly ClockInterface $clock;

    /**
     * @param string $levelMode how read_min_level is pushed to Loki: `text` = LogQL line filter on level words plus
     *                          stack-trace continuation shapes (misses lines whose level only lives in the stream
     *                          `level` label); `label` =
     *                          stream matcher on the `level` label, no line filter (streams without that label are
     *                          not read; with watch patterns it falls back to `all`, a stream matcher cannot be OR-ed
     *                          with a line filter); `all` = no server-side level restriction, the client decides
     */
    public function __construct(
        private readonly ClientInterface $http,
        string $baseUrl,
        #[\SensitiveParameter] ?string $bearerToken = null,
        ?string $basicUser = null,
        #[\SensitiveParameter] ?string $basicPassword = null,
        #[\SensitiveParameter] ?string $tenant = null,
        ?callable $sleeper = null,
        int $retryBudget = 120,
        private readonly string $levelMode = 'text',
        ?ClockInterface $clock = null,
        private readonly int $pagingBudget = 300,
        private readonly int $maxPages = 1000,
        bool $allowInsecure = false,
        private readonly int $maxResponseBytes = ResponseLimit::MAX_BYTES,
    ) {
        $this->clock = $clock ?? new SystemClock();
        if (($problem = \Watcher\Config\SecureUrl::problem($baseUrl, $allowInsecure)) !== null) {
            throw new InvalidArgumentException('Loki url ' . $problem . '.'); // never the URL itself
        }
        if (!in_array($levelMode, ['text', 'label', 'all'], true)) {
            throw new InvalidArgumentException("Invalid Loki level_mode '{$levelMode}' (text, label or all).");
        }
        $this->retry = new Retry($sleeper, $retryBudget);
        $this->baseUrl = rtrim($baseUrl, '/');
        $headers = ['Accept' => 'application/json'];
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        } elseif ($basicUser !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . ($basicPassword ?? ''));
        }
        if ($tenant !== null && $tenant !== '') {
            $headers['X-Scope-OrgID'] = $tenant;
        }
        $this->headers = static fn (): array => $headers;
        $this->parser = new LineParser();
    }

    public function totals(Window $window, StreamSelector $selector): array
    {
        $seconds = $window->end->getTimestamp() - $window->start->getTimestamp();
        $data = $this->get('/loki/api/v1/query', [
            'query' => sprintf('sum by (level) (count_over_time(%s[%ds]))', LokiQuery::selector($selector), $seconds),
            'time' => LokiQuery::ns($window->end),
        ]);

        $totals = [];
        $bad = 0;
        $results = LokiResponse::results($data);
        foreach ($results as $series) {
            $count = is_array($series) && is_array($series['value'] ?? null) ? RemoteValue::count($series['value'][1] ?? null) : null;
            if ($count === null) {
                ++$bad;
                continue;
            }
            $label = is_array($series['metric'] ?? null) ? ($series['metric']['level'] ?? '') : '';
            $name = strtolower(Level::parse(is_string($label) ? $label : '')->name);
            $totals[$name] = ($totals[$name] ?? 0) + $count;
        }
        $this->malformed += $bad;
        RemoteValue::check($bad, count($results));

        return $totals;
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): Generator
    {
        $this->truncated = false;
        $this->pageLimit = self::PAGE_LIMIT;
        $this->pagesShrunk = 0;
        $this->malformed = 0;
        $this->parser = new LineParser();
        $labelled = $this->levelMode === 'label' && $filter->watchPatterns === [];
        $query = LokiQuery::selector($selector, $labelled ? LokiQuery::levelMatcher($filter) : '') . LokiQuery::lineFilter($filter, $this->levelMode);
        $start = LokiQuery::nsInt($window->start);
        $end = LokiQuery::ns($window->end);
        $seenAtStart = []; // entries already yielded or dropped at exactly $start (Loki's start is inclusive)
        $kept = 0;
        $lastTs = $start;
        $pages = 0;
        $startedAt = $this->clock->now()->getTimestamp();

        while (true) {
            // Unwanted pages never yield, so the run deadline, the page cap and the wall-time budget are checked here (A4-01, A4-04).
            $stop = match (true) {
                $filter->shouldStop() => $filter->truncationReason,
                $pages >= $this->maxPages => 'pages',
                $pages > 0 && $this->clock->now()->getTimestamp() - $startedAt > $this->pagingBudget => 'time',
                default => null,
            };
            if ($stop !== null) {
                if (in_array($stop, ['pages', 'time'], true)) {
                    yield from $this->newest($query, $start, $end, $filter, $stop, $start);

                    return;
                }
                $this->truncated = true;
                $filter->truncationReason ??= $stop;

                return;
            }
            ++$pages;
            $entries = $this->page($query, (string) $start, $end);
            $fresh = 0;
            $freshEntries = [];
            foreach ($entries as [$ts, $line, $labels]) {
                $key = $ts . "\0" . $line . "\0" . json_encode($labels);
                if ($ts === $start && isset($seenAtStart[$key])) {
                    continue;
                }
                ++$fresh;
                if ($filter->oversized($line)) {
                    continue; // never reaches the parser
                }
                $freshEntries[] = [$ts, $line, $labels];
            }
            foreach (LokiResponse::records($freshEntries, $this->parser) as [$ts, $line, $labels]) {
                $event = $this->event($ts, $line, $labels);
                if (!$this->wanted($event, $filter)) {
                    continue;
                }
                if ($kept >= $filter->headCap()) {
                    yield from $this->newest($query, $lastTs, $end, $filter, 'cap', $entries[count($entries) - 1][0]);

                    return;
                }
                ++$kept;
                $lastTs = $ts;
                yield $event;
                if ($filter->shouldStop()) {
                    $this->truncated = true;

                    return;
                }
            }

            // A short page (or one that is all duplicates) means the window is exhausted.
            if (count($entries) < $this->pageLimit || $fresh === 0) {
                return;
            }
            if ($entries[0][0] === $entries[count($entries) - 1][0] || $entries[count($entries) - 1][0] <= $start) {
                // A whole page at one nanosecond, or a backend that ignores `start`: the cursor cannot advance (A4-04).
                $this->truncated = true;
                $filter->truncationReason ??= 'cursor';

                return;
            }
            $start = $entries[count($entries) - 1][0];
            $seenAtStart = [];
            foreach ($entries as [$ts, $line, $labels]) {
                if ($ts === $start) {
                    $seenAtStart[$ts . "\0" . $line . "\0" . json_encode($labels)] = true;
                }
            }
        }
    }

    /**
     * The read was cut short after $after (ns): read the newest part of the window backwards, up to the reserved share
     * of the cap, and yield it oldest to newest. When that reaches back to $after nothing was lost and the read is not
     * reported as truncated (A4-03).
     *
     * @return Generator<int, LogEvent>
     */
    private function newest(string $query, int $after, string $end, ReadFilter $filter, string $reason, int $seen): Generator
    {
        // After a spent time or page budget only two pages more are read: the budget is gone, the share is a courtesy.
        $maxPages = $reason === 'cap' ? self::TAIL_PAGES : 2;
        $want = $filter->tailCap();
        $batches = [];
        $have = 0;
        $complete = false;
        $to = $end;
        for ($i = 0; $want > 0 && $i < $maxPages && !$filter->shouldStop(); ++$i) {
            // ponytail: Loki's end is exclusive, so entries sharing the page's oldest nanosecond beyond the limit are skipped.
            $entries = $this->page($query, (string) ($after + 1), $to, 'backward');
            // Lines up to $seen were already counted by the page the head read (oversized, orphan frames): not again.
            $fresh = [];
            foreach ($entries as $e) {
                $before = $filter->oversizedLines;
                if ($filter->oversized($e[1])) {
                    $filter->oversizedLines = $e[0] <= $seen ? $before : $filter->oversizedLines;
                    continue;
                }
                $fresh[] = $e;
            }
            $overlap = array_values(array_filter($fresh, static fn (array $e): bool => $e[0] <= $seen));
            $orphans = $this->parser->orphanTraceLines;
            if ($overlap !== []) {
                LokiResponse::records($overlap, $this->parser);
                $overlapOrphans = $this->parser->orphanTraceLines - $orphans;
                $this->parser->orphanTraceLines = $orphans;
            }
            $batch = [];
            foreach (LokiResponse::records($fresh, $this->parser) as [$ts, $line, $labels]) {
                $event = $this->event($ts, $line, $labels);
                if ($this->wanted($event, $filter)) {
                    $batch[] = $event;
                }
            }
            $this->parser->orphanTraceLines -= $overlapOrphans ?? 0;
            unset($overlapOrphans);
            $batches[] = $batch;
            $have += count($batch);
            if (count($entries) < $this->pageLimit) {
                $complete = true;
                break;
            }
            if ($have >= $want || $entries[0][0] === $entries[count($entries) - 1][0]) {
                break;
            }
            $to = (string) $entries[0][0];
        }
        $all = $batches === [] ? [] : array_merge(...array_reverse($batches));
        if (!$complete || count($all) > $want) {
            $this->truncated = true;
            $filter->truncationReason ??= $reason;
        }
        yield from $want > 0 ? array_slice($all, -$want) : [];
    }

    /** Bare stack-frame lines dropped by the last lines() call because no record was open for their stream. */
    public function orphanTraceLines(): int
    {
        return $this->parser->orphanTraceLines;
    }

    public function readDiagnostics(): array
    {
        return $this->parser->diagnostics()
            + ($this->pagesShrunk > 0 ? ['pages_shrunk' => $this->pagesShrunk] : [])
            + ($this->malformed > 0 ? ['malformed_entries' => $this->malformed] : []);
    }

    public function lastReadTruncated(): bool
    {
        return $this->truncated;
    }

    public function versions(Window $window, StreamSelector $selector, string $label): array
    {
        $data = $this->get('/loki/api/v1/query_range', [
            'query' => sprintf('count by (%s) (count_over_time(%s[1h]))', LokiQuery::name($label), LokiQuery::selector($selector)),
            'start' => LokiQuery::ns($window->start),
            'end' => LokiQuery::ns($window->end),
            'step' => (string) self::VERSION_STEP,
        ]);

        // ponytail: sample time is the end of the 1h step that first saw the version; exact first-seen needs a log read.
        $versions = [];
        $bad = 0;
        $results = LokiResponse::results($data);
        foreach ($results as $series) {
            $points = is_array($series) ? LokiResponse::points($series['values'] ?? null) : null;
            if ($points === null) {
                ++$bad;
                continue;
            }
            $value = is_array($series['metric'] ?? null) ? ($series['metric'][$label] ?? null) : null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            foreach ($points as [$ts, $count]) {
                if ($count > 0) {
                    $time = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
                    if (!isset($versions[$value]) || $time < $versions[$value]) {
                        $versions[$value] = $time;
                    }
                    break;
                }
            }
        }
        $this->malformed += $bad;
        RemoteValue::check($bad, count($results));
        uasort($versions, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $versions;
    }


    /**
     * One page (forward = oldest first, backward = the newest of the range), merged across streams and ordered by timestamp.
     *
     * @return list<array{int, string, array<string, string>}>
     */
    private function page(string $query, string $start, string $end, string $direction = 'forward'): array
    {
        $data = [];
        while (true) {
            try {
                $data = $this->get('/loki/api/v1/query_range', [
                    'query' => $query,
                    'start' => $start,
                    'end' => $end,
                    'limit' => (string) $this->pageLimit,
                    'direction' => $direction,
                ]);
                break;
            } catch (SourceUnavailable $e) {
                // The reply tripped the size cap: the same cursor with half the page (R-1), kept for the rest of the read.
                if (!$e->tooLarge || $this->pageLimit <= self::MIN_PAGE_LIMIT) {
                    throw $e;
                }
                $this->pageLimit = max(self::MIN_PAGE_LIMIT, intdiv($this->pageLimit, 2));
                ++$this->pagesShrunk;
            }
        }

        $entries = [];
        $bad = 0;
        $total = 0;
        foreach (LokiResponse::results($data) as $stream) {
            // Non-scalar stream labels are dropped: a label is metadata, not an entry.
            $labels = is_array($stream) && is_array($stream['stream'] ?? null) ? array_map('strval', array_filter($stream['stream'], 'is_scalar')) : [];
            $values = is_array($stream) && is_array($stream['values'] ?? null) ? $stream['values'] : null;
            if ($values === null) {
                ++$bad;
                ++$total;
                continue;
            }
            foreach ($values as $pair) {
                ++$total;
                $ts = is_array($pair) ? RemoteValue::nanos($pair[0] ?? null) : null;
                $line = is_array($pair) ? ($pair[1] ?? null) : null;
                if ($ts === null || !is_string($line)) {
                    ++$bad;
                    continue;
                }
                $entries[] = [$ts, $line, $labels];
            }
        }
        $this->malformed += $bad;
        RemoteValue::check($bad, $total);
        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $entries;
    }


    /** @param array<string, string> $labels */
    private function event(int $ts, string $line, array $labels): LogEvent
    {
        $time = (new DateTimeImmutable('@' . intdiv($ts, 1_000_000_000)))->setTimezone(new DateTimeZone('UTC'));
        $parsed = $this->parser->parse($line, $time);
        $level = ($parsed->level === Level::Info || $this->parser->lastParseFailed) && isset($labels['level']) ? Level::parse($labels['level']) : $parsed->level;

        return new LogEvent($parsed->time, $level, $parsed->message, $parsed->errorType, $parsed->frames, $parsed->fields, $labels);
    }

    /** Client-side re-check: the server regex is a coarse net and must never widen what the caller asked for. */
    private function wanted(LogEvent $event, ReadFilter $filter): bool
    {
        if ($event->level->atLeast($filter->minLevel)) {
            return true;
        }

        return $filter->matchesWatch($event->message, $event->errorType);
    }










    /**
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query): array
    {
        // http_errors off: Guzzle's own exception text embeds the full URL, which we never surface.
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        try {
            $response = $this->retry->send(fn (): ResponseInterface => $this->http->request('GET', $this->baseUrl . $path, [
            'query' => $query,
            'headers' => ($this->headers)(),
            'http_errors' => false,
            'allow_redirects' => false, // X-Scope-OrgID would follow a redirect; a 3xx is an error here
            'verify' => true,
        ] + ResponseLimit::options($tripped, $this->maxResponseBytes)), 'loki', true, $tripped);
        } catch (SourceUnavailable $e) {
            throw isset($tripped['large']) ? new SourceUnavailable('loki response too large', true) : $e;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new SourceUnavailable(trim("loki {$status} " . $response->getReasonPhrase()));
        }
        return ResponseLimit::decode($response, 'loki', $this->maxResponseBytes);
    }

    /** @return array<string, never> var_dump and print_r show nothing of what this object holds */
    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): array
    {
        throw new \LogicException(self::class . ' is not serialisable.');
    }
}
