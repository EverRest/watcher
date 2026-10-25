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
    private const LEVEL_WORDS = [
        'debug' => ['debug'],
        'info' => ['info'],
        'notice' => ['notice'],
        'warning' => ['warn', 'warning'],
        'error' => ['err', 'error'],
        'critical' => ['crit', 'critical'],
        'alert' => ['alert'],
        'emergency' => ['emerg', 'emergency', 'fatal'],
    ];
    /** Extra spellings seen in a `level` stream label (not as words in the text). */
    private const LABEL_WORDS = ['info' => ['information'], 'emergency' => ['panic']];

    private readonly string $baseUrl;
    /** @var \Closure(): array<string, string> request headers incl. the credential: a closure, so var_export shows no value */
    private readonly \Closure $headers;
    private readonly Retry $retry;
    private bool $truncated = false;
    private int $pageLimit = self::PAGE_LIMIT;

    /** Times a page tripped the response cap and was re-read with half the limit during the last lines() call. */
    public int $pagesShrunk = 0;
    private LineParser $parser;
    private readonly ClockInterface $clock;

    /**
     * @param string $levelMode how read_min_level is pushed to Loki: `text` = LogQL line filter on level words (misses
     *                          lines whose level only lives in the stream `level` label, and trace lines); `label` =
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
            'query' => sprintf('sum by (level) (count_over_time(%s[%ds]))', $this->selector($selector), $seconds),
            'time' => $this->ns($window->end),
        ]);

        $totals = [];
        foreach ($this->results($data) as $series) {
            $label = $series['metric']['level'] ?? '';
            $name = strtolower(Level::parse(is_string($label) ? $label : '')->name);
            $totals[$name] = ($totals[$name] ?? 0) + (int) ($series['value'][1] ?? 0);
        }

        return $totals;
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): Generator
    {
        $this->truncated = false;
        $this->pageLimit = self::PAGE_LIMIT;
        $this->pagesShrunk = 0;
        $this->parser = new LineParser();
        $labelled = $this->levelMode === 'label' && $filter->watchPatterns === [];
        $query = $this->selector($selector, $labelled ? $this->levelMatcher($filter) : '') . $this->lineFilter($filter);
        $start = $this->nsInt($window->start);
        $end = $this->ns($window->end);
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
            foreach ($this->records($freshEntries) as [$ts, $line, $labels]) {
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
                $this->records($overlap);
                $overlapOrphans = $this->parser->orphanTraceLines - $orphans;
                $this->parser->orphanTraceLines = $orphans;
            }
            $batch = [];
            foreach ($this->records($fresh) as [$ts, $line, $labels]) {
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
        return $this->parser->diagnostics() + ($this->pagesShrunk > 0 ? ['pages_shrunk' => $this->pagesShrunk] : []);
    }

    public function lastReadTruncated(): bool
    {
        return $this->truncated;
    }

    public function versions(Window $window, StreamSelector $selector, string $label): array
    {
        $data = $this->get('/loki/api/v1/query_range', [
            'query' => sprintf('count by (%s) (count_over_time(%s[1h]))', $this->name($label), $this->selector($selector)),
            'start' => $this->ns($window->start),
            'end' => $this->ns($window->end),
            'step' => (string) self::VERSION_STEP,
        ]);

        // ponytail: sample time is the end of the 1h step that first saw the version; exact first-seen needs a log read.
        $versions = [];
        foreach ($this->results($data) as $series) {
            $value = $series['metric'][$label] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            foreach ($series['values'] ?? [] as [$ts, $count]) {
                if ((float) $count > 0) {
                    $time = (new DateTimeImmutable('@' . (int) $ts))->setTimezone(new DateTimeZone('UTC'));
                    if (!isset($versions[$value]) || $time < $versions[$value]) {
                        $versions[$value] = $time;
                    }
                    break;
                }
            }
        }
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
        foreach ($this->results($data) as $stream) {
            $labels = array_map('strval', is_array($stream['stream'] ?? null) ? $stream['stream'] : []);
            foreach ($stream['values'] ?? [] as [$ts, $line]) {
                $entries[] = [(int) $ts, (string) $line, $labels];
            }
        }
        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $entries;
    }

    /**
     * Loki returns one entry per physical line: join continuation lines to the record they follow,
     * per stream, so interleaved streams never mix. A record split by a page edge stays split.
     *
     * @param list<array{int, string, array<string, string>}> $entries time-ordered
     *
     * @return list<array{int, string, array<string, string>}> records ordered by their first line
     */
    private function records(array $entries): array
    {
        $parser = $this->parser;
        $out = [];
        $open = []; // stream key => index into $out
        foreach ($entries as [$ts, $line, $labels]) {
            $stream = json_encode($labels);
            if (!isset($open[$stream]) && $parser->isOrphanTrace($line, isset($labels['level']))) {
                continue;
            }
            if (isset($open[$stream]) && $parser->continues($line, $out[$open[$stream]][1], isset($labels['level']))) {
                [$firstTs, $text, $firstLabels] = $out[$open[$stream]];
                $out[$open[$stream]] = [$firstTs, $text . "\n" . $line, $firstLabels];
                continue;
            }
            $open[$stream] = count($out);
            $out[] = [$ts, $line, $labels];
        }

        return $out;
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

    /** @return list<string> */
    private function words(Level $min, bool $labelSpellings): array
    {
        $words = [];
        foreach (Level::cases() as $level) {
            if ($level->atLeast($min)) {
                $key = strtolower($level->name);
                array_push($words, ...self::LEVEL_WORDS[$key], ...($labelSpellings ? self::LABEL_WORDS[$key] ?? [] : []));
            }
        }

        return $words;
    }

    /** Stream matcher for the `level` label (all case variants), '' when every level is wanted. */
    private function levelMatcher(ReadFilter $filter): string
    {
        return $filter->minLevel === Level::Debug ? '' : 'level=~' . $this->quote('(?i)(?:' . implode('|', $this->words($filter->minLevel, true)) . ')');
    }

    private function lineFilter(ReadFilter $filter): string
    {
        if ($this->levelMode !== 'text') {
            return '';
        }
        $parts = [];
        if ($filter->minLevel !== Level::Debug) {
            $parts[] = '(?:\b(?:' . implode('|', $this->words($filter->minLevel, false)) . ')\b)';
        }
        foreach ($filter->watchPatterns as $re) {
            $parts[] = '(?:' . $re . ')';
        }
        if ($parts === []) {
            return '';
        }

        return ' |~ ' . $this->quote('(?i)' . implode('|', $parts));
    }

    private function selector(StreamSelector $selector, string $extra = ''): string
    {
        $matchers = [];
        foreach ($selector->tags as $key => $value) {
            $matchers[] = $this->name((string) $key) . '=' . $this->quote($value);
        }
        if ($matchers === []) {
            throw new InvalidArgumentException('A Loki query needs at least one stream tag.');
        }
        if ($extra !== '') {
            $matchers[] = $extra;
        }

        return '{' . implode(', ', $matchers) . '}';
    }

    private function name(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Loki label name '{$name}'.");
        }

        return $name;
    }

    private function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    }

    private function ns(DateTimeImmutable $t): string
    {
        return (string) $this->nsInt($t);
    }

    private function nsInt(DateTimeImmutable $t): int
    {
        return $t->getTimestamp() * 1_000_000_000 + (int) $t->format('u') * 1000;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function results(array $data): array
    {
        $result = $data['data']['result'] ?? [];

        return is_array($result) ? array_values($result) : [];
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
        ] + ResponseLimit::options($tripped, $this->maxResponseBytes)), 'loki', true);
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
