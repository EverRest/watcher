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

/**
 * Reads a window from Datadog Logs (read-only endpoints): aggregate totals, cursor-paged server-filtered lines, version facet.
 *
 * level_mode `status` (default) restricts server-side to the Datadog `status` attribute, so a line shipped from stdout
 * with status info whose BODY carries an ERROR level is not read; `all` sends no level restriction (client decides).
 * Paging is oldest first, so an event_cap truncation keeps the OLDEST events; `$newestFirst` sorts `-timestamp` so
 * the cap keeps the NEWEST (events are still yielded oldest to newest). Literal watch patterns are sent as
 * `*term*` wildcards (plain full-text terms match whole tokens, `timeout` would miss `ConnectTimeout`); a literal
 * with punctuation or spaces cannot be a wildcard, so it is client-only: the server filter is dropped.
 */
final class DatadogSource implements LogSource, ReadDiagnostics
{
    private const PAGE_LIMIT = 5000;
    private const MIN_PAGE_LIMIT = 100;
    /** Most pages the newest-share read may take (A4-03). */
    private const TAIL_PAGES = 20;
    private const FACET_LIMIT = 50;
    private const VERSION_STEP = 3600;

    private readonly string $baseUrl;
    /** @var \Closure(): array<string, string> request headers incl. the credential: a closure, so var_export shows no value */
    private readonly \Closure $headers;
    private readonly Retry $retry;
    private readonly ClockInterface $clock;
    private bool $truncated = false;

    /** Times a page tripped the response cap and was re-read with half the limit during the last lines() call. */
    public int $pagesShrunk = 0;

    /** Entries skipped as unusable (see {@see RemoteValue}) during the last lines() call; totals() and versions() add to it. */
    private int $malformed = 0;
    private LineParser $parser;

    /** @param (callable(int): void)|null $sleeper receives whole seconds to wait between retries; defaults to sleep() */
    public function __construct(
        private readonly ClientInterface $http,
        string $site,
        #[\SensitiveParameter] string $apiKey,
        #[\SensitiveParameter] string $appKey,
        ?callable $sleeper = null,
        int $retryBudget = 120,
        private readonly int $pagingBudget = 300,
        ?ClockInterface $clock = null,
        private readonly string $levelMode = 'status',
        private readonly bool $newestFirst = false,
        private readonly int $maxResponseBytes = ResponseLimit::MAX_BYTES,
    ) {
        if (!in_array($levelMode, ['status', 'all'], true)) {
            throw new InvalidArgumentException("Invalid Datadog level_mode '{$levelMode}' (status or all).");
        }
        if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/iD', $site) !== 1) {
            throw new InvalidArgumentException('Invalid Datadog site.');
        }
        $this->baseUrl = 'https://api.' . $site;
        $headers = ['DD-API-KEY' => $apiKey, 'DD-APPLICATION-KEY' => $appKey, 'Accept' => 'application/json'];
        $this->headers = static fn (): array => $headers;
        $this->retry = new Retry($sleeper, $retryBudget);
        $this->clock = $clock ?? new SystemClock();
        $this->parser = new LineParser();
    }

    public function totals(Window $window, StreamSelector $selector): array
    {
        $data = $this->post('/api/v2/logs/analytics/aggregate', [
            'compute' => [['aggregation' => 'count', 'type' => 'total']],
            'group_by' => [['facet' => 'status', 'limit' => 20]],
            'filter' => $this->filter($window, $this->tagQuery($selector)),
        ]);

        $totals = [];
        $bad = 0;
        $buckets = $this->buckets($data);
        foreach ($buckets as $bucket) {
            $status = $bucket['by']['status'] ?? '';
            $name = strtolower(Level::parse(is_string($status) ? $status : '')->name);
            $count = RemoteValue::count($bucket['computes']['c0'] ?? null);
            if ($count === null) {
                ++$bad;
                continue;
            }
            $totals[$name] = ($totals[$name] ?? 0) + $count;
        }
        $this->malformed += $bad;
        RemoteValue::check($bad, count($buckets));

        return $totals;
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): Generator
    {
        $this->truncated = false;
        $this->pagesShrunk = 0;
        $this->malformed = 0;
        $limit = self::PAGE_LIMIT;
        $this->parser = new LineParser();
        $query = $this->tagQuery($selector) . $this->readQuery($filter);
        $cursor = null;
        $kept = 0;
        $buffer = [];
        $lastRaw = $window->start->modify('-1 millisecond'); // backend timestamp of the last event kept, the newest-share read starts after it
        $cap = $this->newestFirst ? $filter->eventCap : $filter->headCap();
        $startedAt = $this->clock->now()->getTimestamp();

        do {
            if ($filter->shouldStop()) {
                $this->truncated = true;
                yield from array_reverse($buffer);

                return;
            }
            $data = $this->search($window, $query, $this->newestFirst ? '-timestamp' : 'timestamp', $limit, $cursor);
            foreach ($data['data'] ?? [] as $raw) {
                if (!is_array($raw) || !$this->usable($raw)) {
                    continue; // counted by screen()
                }
                if ($filter->oversized(is_string($raw['attributes']['message'] ?? null) ? $raw['attributes']['message'] : '')) {
                    continue; // never reaches the parser (A4-01)
                }
                $event = $this->event($raw);
                if ($event === null || !$this->wanted($event, $filter)) {
                    continue;
                }
                if ($kept >= $cap) {
                    yield from array_reverse($buffer);
                    $rawAt = $this->time(is_string($raw['attributes']['timestamp'] ?? null) ? $raw['attributes']['timestamp'] : '');
                    // The event that tripped the cap shares its millisecond with the last one kept: the newest read starts after that millisecond and cannot see it.
                    yield from $this->newest($window, $query, $lastRaw, $filter, 'cap', $limit, $rawAt !== null && $rawAt->format('Uv') <= $lastRaw->format('Uv'));

                    return;
                }
                ++$kept;
                $lastRaw = $this->time(is_string($raw['attributes']['timestamp'] ?? null) ? $raw['attributes']['timestamp'] : '') ?? $lastRaw;
                if ($this->newestFirst) {
                    $buffer[] = $event;
                } else {
                    yield $event;
                    if ($filter->shouldStop()) {
                        $this->truncated = true;

                        return;
                    }
                }
            }
            $next = $data['meta']['page']['after'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
            if ($cursor !== null && $this->clock->now()->getTimestamp() - $startedAt > $this->pagingBudget) {
                yield from array_reverse($buffer);
                yield from $this->newest($window, $query, $lastRaw, $filter, 'time', $limit);

                return;
            }
        } while ($cursor !== null);
        yield from array_reverse($buffer);
    }

    /**
     * One events/search page. A reply over the response cap is retried with half the page (R-1); $limit keeps the smaller size
     * for the rest of the read. Only a page already at the minimum still over the cap is thrown.
     *
     * @return array<mixed>
     */
    private function search(Window $window, string $query, string $sort, int &$limit, ?string $cursor): array
    {
        while (true) {
            try {
                return $this->screen($this->post('/api/v2/logs/events/search', [
                    'filter' => $this->filter($window, $query),
                    'sort' => $sort,
                    'page' => ['limit' => $limit] + ($cursor === null ? [] : ['cursor' => $cursor]),
                ]));
            } catch (SourceUnavailable $e) {
                if (!$e->tooLarge || $limit <= self::MIN_PAGE_LIMIT) {
                    throw $e;
                }
                $limit = max(self::MIN_PAGE_LIMIT, intdiv($limit, 2));
                ++$this->pagesShrunk;
            }
        }
    }

    /**
     * Counts the items of one events/search reply that usable() refuses. A reply where most items are refused fails the read.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     *
     * @throws SourceUnavailable when the reply has no list of items, or most of its items are unusable
     */
    private function screen(array $data): array
    {
        $items = $data['data'] ?? [];
        if (!is_array($items)) {
            throw new SourceUnavailable('malformed');
        }
        $bad = 0;
        foreach ($items as $raw) {
            if (!is_array($raw) || !$this->usable($raw)) {
                ++$bad;
            }
        }
        $this->malformed += $bad;
        RemoteValue::check($bad, count($items));

        return $data;
    }

    /**
     * An item event() can read: its attributes are an object, and its timestamp is absent (the clock stands in) or parses.
     *
     * @param array<mixed> $raw
     */
    private function usable(array $raw): bool
    {
        if (!is_array($raw['attributes'] ?? null)) {
            return false;
        }
        $ts = $raw['attributes']['timestamp'] ?? '';

        return is_string($ts) && ($ts === '' || $this->time($ts) !== null);
    }

    /**
     * The read was cut short after $after: read the newest part of the window (sort -timestamp), up to the reserved
     * share of the cap, and yield it oldest to newest. When that reaches back to $after nothing was lost and the read is
     * not reported as truncated (A4-03). Not used with newest_first, which already keeps the newest.
     *
     * @return Generator<int, LogEvent>
     */
    private function newest(Window $window, string $query, DateTimeImmutable $after, ReadFilter $filter, string $reason, int $limit, bool $seamLoss = false): Generator
    {
        // After a spent time or page budget only two pages more are read: the budget is gone, the share is a courtesy.
        $maxPages = $reason === 'cap' ? self::TAIL_PAGES : 2;
        $want = $this->newestFirst ? 0 : $filter->tailCap();
        $events = [];
        $cursor = null;
        $complete = false;
        // ponytail: Datadog times are milliseconds, so an unread event in the same millisecond as the last kept one is skipped.
        $from = $after->modify('+1 millisecond') < $window->end ? new Window($after->modify('+1 millisecond'), $window->end) : null;
        for ($i = 0; $from !== null && $want > 0 && $i < $maxPages && !$filter->shouldStop(); ++$i) {
            try {
                $data = $this->search($from, $query, '-timestamp', $limit, $cursor);
            } catch (SourceUnavailable $e) {
                if (!$e->tooLarge) {
                    throw $e;
                }
                break; // even the smallest page is over the response cap: keep what the head and this tail read, report the cut
            }
            foreach ($data['data'] ?? [] as $raw) {
                if (!is_array($raw) || !$this->usable($raw) || $filter->oversized(is_string($raw['attributes']['message'] ?? null) ? $raw['attributes']['message'] : '')) {
                    continue;
                }
                $event = $this->event($raw);
                if ($event !== null && $this->wanted($event, $filter)) {
                    $events[] = $event;
                }
            }
            $next = $data['meta']['page']['after'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
            if ($cursor === null) {
                $complete = true;
                break;
            }
            if (count($events) >= $want) {
                break;
            }
        }
        if ($seamLoss || !$complete || count($events) > $want) {
            $this->truncated = true;
            $filter->truncationReason ??= $reason;
        }

        yield from array_reverse(array_slice($events, 0, $want));
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
        $data = $this->post('/api/v2/logs/analytics/aggregate', [
            'compute' => [['aggregation' => 'count', 'type' => 'timeseries', 'interval' => '1h']],
            'group_by' => [['facet' => $label, 'limit' => self::FACET_LIMIT]],
            'filter' => $this->filter($window, $this->tagQuery($selector)),
        ]);

        // ponytail: first-seen is the end of the first 1h bucket with a non-zero count; exact time needs a log read.
        $versions = [];
        $bad = 0;
        $buckets = $this->buckets($data);
        foreach ($buckets as $bucket) {
            $value = $bucket['by'][$label] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $series = $bucket['computes']['c0'] ?? [];
            $unusable = false;
            foreach (is_array($series) ? $series : [] as $point) {
                $count = is_array($point) ? RemoteValue::count($point['value'] ?? null) : null;
                $at = is_array($point) && is_string($point['time'] ?? null) ? $this->time($point['time']) : null;
                if ($count === null || $at === null) {
                    $unusable = true;
                    continue;
                }
                if ($count > 0) {
                    $time = $at->modify('+' . self::VERSION_STEP . ' seconds');
                    if (!isset($versions[$value]) || $time < $versions[$value]) {
                        $versions[$value] = $time;
                    }
                    break;
                }
            }
            $bad += $unusable ? 1 : 0;
        }
        $this->malformed += $bad;
        RemoteValue::check($bad, count($buckets));
        uasort($versions, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $versions;
    }

    /** @param array<mixed> $raw one item of `data` from events/search */
    private function event(array $raw): ?LogEvent
    {
        $attrs = is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];
        $rawTs = is_string($attrs['timestamp'] ?? null) ? $attrs['timestamp'] : '';
        $time = $this->time($rawTs);
        if ($time === null) {
            if ($rawTs !== '') {
                return null;
            }
            $time = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        }
        $labels = [];
        foreach (is_array($attrs['tags'] ?? null) ? $attrs['tags'] : [] as $tag) {
            if (is_string($tag) && str_contains($tag, ':')) {
                [$k, $v] = explode(':', $tag, 2);
                $labels[$k] = $v;
            }
        }
        if (is_string($attrs['service'] ?? null) && $attrs['service'] !== '') {
            $labels['service'] = $attrs['service'];
        }

        $parsed = $this->parser->parse(is_string($attrs['message'] ?? null) ? $attrs['message'] : '', $time);
        $level = ($parsed->level === Level::Info || $this->parser->lastParseFailed) && is_string($attrs['status'] ?? null) ? Level::parse($attrs['status']) : $parsed->level;

        return new LogEvent($time, $level, $parsed->message, $parsed->errorType, $parsed->frames, $parsed->fields, $labels);
    }

    /** Client-side re-check: the server query is a coarse net and must never widen what the caller asked for. */
    private function wanted(LogEvent $event, ReadFilter $filter): bool
    {
        if ($event->level->atLeast($filter->minLevel)) {
            return true;
        }

        return $filter->matchesWatch($event->message, $event->errorType);
    }

    /**
     * `AND (status:(...) OR "term" ...)`, or '' when it cannot narrow. A watch pattern that is not a plain
     * literal cannot be expressed in Datadog search, so it disables the server filter (client side still applies).
     */
    private function readQuery(ReadFilter $filter): string
    {
        if ($filter->minLevel === Level::Debug || $this->levelMode === 'all') {
            return '';
        }
        $statuses = [];
        foreach (Level::cases() as $level) {
            if ($level->atLeast($filter->minLevel)) {
                $statuses[] = $level === Level::Warning ? 'warn' : strtolower($level->name);
            }
        }
        $parts = ['status:(' . implode(' OR ', $statuses) . ')'];
        foreach ($filter->watchPatterns as $re) {
            $literal = $this->literal($re);
            if ($literal === null) {
                return '';
            }
            $parts[] = '*' . $literal . '*';
        }

        return ' AND (' . implode(' OR ', $parts) . ')';
    }

    /** The text of a pattern that is a plain alphanumeric/underscore word (usable as a `*word*` wildcard), else null. */
    private function literal(string $re): ?string
    {
        $body = str_starts_with($re, '(?i)') ? substr($re, 4) : $re;

        return preg_match('/^\w+$/D', $body) === 1 ? $body : null;
    }

    private function tagQuery(StreamSelector $selector): string
    {
        $terms = [];
        foreach ($selector->tags as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_.@\/-]+$/D', (string) $key) !== 1) {
                throw new InvalidArgumentException("Invalid Datadog tag key '{$key}'.");
            }
            $terms[] = $key . ':' . (preg_match('/^[A-Za-z0-9_.\/-]+$/D', $value) === 1 ? $value : $this->quote($value));
        }
        if ($terms === []) {
            throw new InvalidArgumentException('A Datadog query needs at least one stream tag.');
        }

        return implode(' ', $terms);
    }

    private function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }

    /** @return array{from: string, to: string, query: string} */
    private function filter(Window $window, string $query): array
    {
        return ['from' => $window->start->format('Y-m-d\TH:i:s.v\Z'), 'to' => $window->end->format('Y-m-d\TH:i:s.v\Z'), 'query' => $query];
    }

    private function time(string $iso): ?DateTimeImmutable
    {
        try {
            return $iso === '' ? null : (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function buckets(array $data): array
    {
        $buckets = $data['data']['buckets'] ?? [];

        return is_array($buckets) ? array_values(array_filter($buckets, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function post(string $path, array $body): array
    {
        // http_errors off: Guzzle's own exception text embeds the full URL, which we never surface.
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        try {
            $response = $this->retry->send(fn (): ResponseInterface => $this->http->request('POST', $this->baseUrl . $path, [
            'json' => $body,
            'headers' => ($this->headers)(),
            'http_errors' => false,
            'allow_redirects' => false, // DD-API-KEY would follow a redirect; a 3xx is an error here
            'verify' => true,
        ] + ResponseLimit::options($tripped, $this->maxResponseBytes)), 'datadog', false, $tripped);
        } catch (SourceUnavailable $e) {
            throw isset($tripped['large']) ? new SourceUnavailable('datadog response too large', true) : $e;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new SourceUnavailable(trim("datadog {$status} " . $response->getReasonPhrase()));
        }
        return ResponseLimit::decode($response, 'datadog', $this->maxResponseBytes);
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
