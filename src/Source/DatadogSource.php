<?php

declare(strict_types=1);

namespace Watcher\Source;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Watcher\Level;
use Watcher\Schedule\Window;

/** Reads a window from Datadog Logs (read-only endpoints): aggregate totals, cursor-paged server-filtered lines, version facet. */
final class DatadogSource implements LogSource
{
    private const PAGE_LIMIT = 1000;
    private const MAX_RETRIES = 3;
    private const MAX_WAIT = 60;
    private const FACET_LIMIT = 50;
    private const VERSION_STEP = 3600;

    private readonly string $baseUrl;
    /** @var array<string, string> */
    private readonly array $headers;
    /** @var Closure(int): void */
    private readonly Closure $sleeper;
    private bool $truncated = false;

    /** @param (callable(int): void)|null $sleeper receives whole seconds to wait on a 429; defaults to sleep() */
    public function __construct(
        private readonly ClientInterface $http,
        string $site,
        string $apiKey,
        string $appKey,
        ?callable $sleeper = null,
    ) {
        if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/i', $site) !== 1) {
            throw new InvalidArgumentException('Invalid Datadog site.');
        }
        $this->baseUrl = 'https://api.' . $site;
        $this->headers = ['DD-API-KEY' => $apiKey, 'DD-APPLICATION-KEY' => $appKey, 'Accept' => 'application/json'];
        $this->sleeper = $sleeper === null ? static function (int $s): void {
            sleep($s);
        } : Closure::fromCallable($sleeper);
    }

    public function totals(Window $window, StreamSelector $selector): array
    {
        $data = $this->post('/api/v2/logs/analytics/aggregate', [
            'compute' => [['aggregation' => 'count', 'type' => 'total']],
            'group_by' => [['facet' => 'status', 'limit' => 20]],
            'filter' => $this->filter($window, $this->tagQuery($selector)),
        ]);

        $totals = [];
        foreach ($this->buckets($data) as $bucket) {
            $status = $bucket['by']['status'] ?? '';
            $name = strtolower(Level::parse(is_string($status) ? $status : '')->name);
            $count = $bucket['computes']['c0'] ?? 0;
            $totals[$name] = ($totals[$name] ?? 0) + (is_numeric($count) ? (int) $count : 0);
        }

        return $totals;
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): Generator
    {
        $this->truncated = false;
        $query = $this->tagQuery($selector) . $this->readQuery($filter);
        $cursor = null;
        $kept = 0;

        do {
            $page = ['limit' => self::PAGE_LIMIT] + ($cursor === null ? [] : ['cursor' => $cursor]);
            $data = $this->post('/api/v2/logs/events/search', [
                'filter' => $this->filter($window, $query),
                'sort' => 'timestamp',
                'page' => $page,
            ]);
            foreach ($data['data'] ?? [] as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $event = $this->event($raw);
                if (!$this->wanted($event, $filter)) {
                    continue;
                }
                if ($kept >= $filter->eventCap) {
                    $this->truncated = true;

                    return;
                }
                ++$kept;
                yield $event;
            }
            $next = $data['meta']['page']['after'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
        } while ($cursor !== null);
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
        foreach ($this->buckets($data) as $bucket) {
            $value = $bucket['by'][$label] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $series = $bucket['computes']['c0'] ?? [];
            foreach (is_array($series) ? $series : [] as $point) {
                if (is_array($point) && is_numeric($point['value'] ?? null) && (float) $point['value'] > 0 && is_string($point['time'] ?? null)) {
                    $time = $this->time($point['time'])?->modify('+' . self::VERSION_STEP . ' seconds');
                    if ($time !== null && (!isset($versions[$value]) || $time < $versions[$value])) {
                        $versions[$value] = $time;
                    }
                    break;
                }
            }
        }
        uasort($versions, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $versions;
    }

    /** @param array<mixed> $raw one item of `data` from events/search */
    private function event(array $raw): LogEvent
    {
        $attrs = is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];
        $time = $this->time(is_string($attrs['timestamp'] ?? null) ? $attrs['timestamp'] : '') ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
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

        $parsed = (new LineParser())->parse(is_string($attrs['message'] ?? null) ? $attrs['message'] : '', $time);
        $level = $parsed->level === Level::Info && is_string($attrs['status'] ?? null) ? Level::parse($attrs['status']) : $parsed->level;

        return new LogEvent($time, $level, $parsed->message, $parsed->errorType, $parsed->frames, $parsed->fields, $labels);
    }

    /** Client-side re-check: the server query is a coarse net and must never widen what the caller asked for. */
    private function wanted(LogEvent $event, ReadFilter $filter): bool
    {
        if ($event->level->atLeast($filter->minLevel)) {
            return true;
        }
        foreach ($filter->watchPatterns as $re) {
            if (@preg_match($re, $event->message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * `AND (status:(...) OR "term" ...)`, or '' when it cannot narrow. A watch pattern that is not a plain
     * literal cannot be expressed in Datadog search, so it disables the server filter (client side still applies).
     */
    private function readQuery(ReadFilter $filter): string
    {
        if ($filter->minLevel === Level::Debug) {
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
            $parts[] = $this->quote($literal);
        }

        return ' AND (' . implode(' OR ', $parts) . ')';
    }

    /** The literal text of `/foo bar/` when the pattern has no regex metacharacters, else null. */
    private function literal(string $re): ?string
    {
        $d = $re[0] ?? '';
        $end = strrpos($re, $d);
        if ($d === '' || ctype_alnum($d) || $end === false || $end === 0) {
            return null;
        }
        $body = substr($re, 1, $end - 1);

        return $body !== '' && preg_match('/[\\\\.^$|?*+()\[\]{}]/', $body) !== 1 ? $body : null;
    }

    private function tagQuery(StreamSelector $selector): string
    {
        $terms = [];
        foreach ($selector->tags as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_.@\/-]+$/', (string) $key) !== 1) {
                throw new InvalidArgumentException("Invalid Datadog tag key '{$key}'.");
            }
            $terms[] = $key . ':' . (preg_match('/^[A-Za-z0-9_.\/-]+$/', $value) === 1 ? $value : $this->quote($value));
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
        for ($attempt = 0;; ++$attempt) {
            try {
                // http_errors off: Guzzle's own exception text embeds the full URL, which we never surface.
                $response = $this->http->request('POST', $this->baseUrl . $path, [
                    'json' => $body,
                    'headers' => $this->headers,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                throw new SourceUnavailable('datadog request failed (' . $e::class . ')', 0, $e);
            }

            $status = $response->getStatusCode();
            if ($status === 429 && $attempt < self::MAX_RETRIES) {
                $reset = $response->getHeaderLine('X-RateLimit-Reset');
                ($this->sleeper)(max(1, min(self::MAX_WAIT, is_numeric($reset) ? (int) $reset : 1)));
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new SourceUnavailable(trim("datadog {$status} " . $response->getReasonPhrase()));
            }
            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data)) {
                throw new SourceUnavailable('datadog returned a non-JSON body');
            }

            return $data;
        }
    }
}
