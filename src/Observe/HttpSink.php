<?php

declare(strict_types=1);

namespace Watcher\Observe;

use GuzzleHttp\ClientInterface;
use Watcher\Source\ResponseLimit;
use Watcher\Source\Retry;

/**
 * POSTs events as NDJSON. At-least-once: a retried batch may arrive twice, events carry `event_id` for dedupe.
 * Every failure is rethrown as a fixed `SinkFailed` message: Guzzle's exceptions hold the URL (and the response the token's echo).
 */
final class HttpSink implements EventSink, TimeBoundedSink
{
    private readonly \Closure $now;

    private readonly \Closure $sleep;

    /** @var \Closure(): ?string */
    private readonly \Closure $token;

    public function __construct(
        private readonly HttpTarget $target,
        private readonly ClientInterface $client,
        /** @var Retry|\Closure(int, callable(int): void): Retry $retry a Closure builds the Retry from the seconds left and a deadline-guarded sleeper, so no wait or attempt can pass the run deadline */
        private readonly Retry|\Closure $retry,
        private readonly int $batchSize,
        private int $timeout,
        private readonly string $authHeader,
        private readonly string $authScheme,
        #[\SensitiveParameter] ?string $authToken,
        private int $flushBudgetSeconds = 15,
        ?callable $now = null,
        ?callable $sleep = null,
    ) {
        // Held in a closure: var_export and a property dump never show it (same trick as Destination).
        $this->token = static fn (): ?string => $authToken;
        $this->now = $now === null ? static fn (): float => microtime(true) : \Closure::fromCallable($now);
        $this->sleep = $sleep === null ? static function (int $s): void {
            sleep($s);
        } : \Closure::fromCallable($sleep);
    }

    /** The run deadline leaves this many seconds: neither one request nor the whole flush may outlast them. */
    public function within(int $seconds): void
    {
        $seconds = max(1, $seconds);
        $this->timeout = min($this->timeout, $seconds);
        $this->flushBudgetSeconds = min($this->flushBudgetSeconds, $seconds);
    }

    public function emit(array $events): void
    {
        try { // encode every batch before the first POST: an unencodable event sends nothing
            $bodies = array_map(static function (array $batch): string {
                $body = '';
                foreach ($batch as $event) {
                    $body .= json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                }

                return $body;
            }, array_chunk($events, max(1, $this->batchSize)));
        } catch (\Throwable) {
            throw new SinkFailed('http request failed');
        }
        $start = ($this->now)();
        foreach ($bodies as $i => $body) {
            $elapsed = $i > 0 ? ($this->now)() - $start : 0.0;
            if ($i > 0 && $elapsed > $this->flushBudgetSeconds) {
                throw new SinkFailed('http flush budget exceeded');
            }
            $left = max(1, (int) floor($this->flushBudgetSeconds - $elapsed));
            $stop = false;
            // A wait that would end at or after the budget is not slept; the next attempt then sees $stop and never starts.
            $guarded = function (int $seconds) use ($start, &$stop): void {
                if (($this->now)() - $start + $seconds >= $this->flushBudgetSeconds) {
                    $stop = true;

                    return;
                }
                ($this->sleep)($seconds);
            };
            $retry = $this->retry instanceof \Closure ? ($this->retry)($left, $guarded) : $this->retry;
            $headers = ['Content-Type' => 'application/x-ndjson'];
            if (($token = ($this->token)()) !== null) {
                $headers[$this->authHeader] = trim($this->authScheme . ' ' . $token);
            }
            $attempts = 0;
            try {
                $response = $retry->send(function () use ($headers, $body, $start, $left, &$attempts, &$stop) {
                    $timeout = min($this->timeout, $left);
                    if ($attempts++ > 0) {
                        $spent = ($this->now)() - $start;
                        if ($stop || $this->flushBudgetSeconds - $spent < 1) {
                            throw new SinkFailed('http flush budget exceeded'); // not a Guzzle exception: Retry lets it through
                        }
                        $timeout = min($timeout, max(1, (int) floor($this->flushBudgetSeconds - $spent))); // a late attempt may not outlast the budget
                    }

                    return $this->client->request('POST', $this->target->url(), [
                        'headers' => $headers,
                        'body' => $body,
                        'timeout' => $timeout,
                        'allow_redirects' => false,
                        'http_errors' => false,
                    ] + ResponseLimit::options(new \ArrayObject()));
                }, 'observe http', false);
            } catch (\Throwable) {
                throw new SinkFailed('http request failed'); // not chained: the cause carries the URL
            }
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new SinkFailed('http status ' . $status);
            }
        }
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
