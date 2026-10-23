<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Watcher\Level;
use Watcher\Schedule\Window;

/** Reads a window from Loki: server-side totals, server-filtered forward-paged lines, version labels. */
final class LokiSource implements LogSource
{
    private const PAGE_LIMIT = 5000;
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

    private readonly string $baseUrl;
    /** @var array<string, string> */
    private readonly array $headers;
    private readonly Retry $retry;
    private bool $truncated = false;
    private LineParser $parser;

    public function __construct(
        private readonly ClientInterface $http,
        string $baseUrl,
        ?string $bearerToken = null,
        ?string $basicUser = null,
        ?string $basicPassword = null,
        ?string $tenant = null,
        ?callable $sleeper = null,
        int $retryBudget = 120,
    ) {
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
        $this->headers = $headers;
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
        $this->parser = new LineParser();
        $query = $this->selector($selector) . $this->lineFilter($filter);
        $start = $this->nsInt($window->start);
        $end = $this->ns($window->end);
        $seenAtStart = []; // entries already yielded or dropped at exactly $start (Loki's start is inclusive)
        $kept = 0;

        while (true) {
            $entries = $this->page($query, (string) $start, $end);
            $fresh = 0;
            $freshEntries = [];
            foreach ($entries as [$ts, $line, $labels]) {
                $key = $ts . "\0" . $line . "\0" . json_encode($labels);
                if ($ts === $start && isset($seenAtStart[$key])) {
                    continue;
                }
                ++$fresh;
                $freshEntries[] = [$ts, $line, $labels];
            }
            foreach ($this->records($freshEntries) as [$ts, $line, $labels]) {
                $event = $this->event($ts, $line, $labels);
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

            // A short page (or one that is all duplicates) means the window is exhausted.
            if (count($entries) < self::PAGE_LIMIT || $fresh === 0) {
                return;
            }
            if ($entries[0][0] === $entries[count($entries) - 1][0]) {
                // A whole page at one nanosecond: the cursor cannot advance, so the rest of that instant is unreachable.
                $this->truncated = true;

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

    /** Bare stack-frame lines dropped by the last lines() call because no record was open for their stream. */
    public function orphanTraceLines(): int
    {
        return $this->parser->orphanTraceLines;
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
     * One forward page, merged across streams and ordered by timestamp.
     *
     * @return list<array{int, string, array<string, string>}>
     */
    private function page(string $query, string $start, string $end): array
    {
        $data = $this->get('/loki/api/v1/query_range', [
            'query' => $query,
            'start' => $start,
            'end' => $end,
            'limit' => (string) self::PAGE_LIMIT,
            'direction' => 'forward',
        ]);

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
            if (isset($open[$stream]) && $parser->continues($line, $out[$open[$stream]][1])) {
                $out[$open[$stream]][1] .= "\n" . $line;
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
        $parsed = (new LineParser())->parse($line, $time);
        $level = $parsed->level === Level::Info && isset($labels['level']) ? Level::parse($labels['level']) : $parsed->level;

        return new LogEvent($parsed->time, $level, $parsed->message, $parsed->errorType, $parsed->frames, $parsed->fields, $labels);
    }

    /** Client-side re-check: the server regex is a coarse net and must never widen what the caller asked for. */
    private function wanted(LogEvent $event, ReadFilter $filter): bool
    {
        if ($event->level->atLeast($filter->minLevel)) {
            return true;
        }

        return $filter->matchesWatch($event->message);
    }

    private function lineFilter(ReadFilter $filter): string
    {
        $parts = [];
        if ($filter->minLevel !== Level::Debug) {
            $words = [];
            foreach (Level::cases() as $level) {
                if ($level->atLeast($filter->minLevel)) {
                    array_push($words, ...self::LEVEL_WORDS[strtolower($level->name)]);
                }
            }
            $parts[] = '(?:\b(?:' . implode('|', $words) . ')\b)';
        }
        foreach ($filter->watchPatterns as $re) {
            $parts[] = '(?:' . $re . ')';
        }
        if ($parts === []) {
            return '';
        }

        return ' |~ ' . $this->quote('(?i)' . implode('|', $parts));
    }

    private function selector(StreamSelector $selector): string
    {
        $matchers = [];
        foreach ($selector->tags as $key => $value) {
            $matchers[] = $this->name((string) $key) . '=' . $this->quote($value);
        }
        if ($matchers === []) {
            throw new InvalidArgumentException('A Loki query needs at least one stream tag.');
        }

        return '{' . implode(', ', $matchers) . '}';
    }

    private function name(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
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
        $response = $this->retry->send(fn (): ResponseInterface => $this->http->request('GET', $this->baseUrl . $path, [
            'query' => $query,
            'headers' => $this->headers,
            'http_errors' => false,
        ]), 'loki', true);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new SourceUnavailable(trim("loki {$status} " . $response->getReasonPhrase()));
        }
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new SourceUnavailable('loki returned a non-JSON body');
        }

        return $data;
    }
}
