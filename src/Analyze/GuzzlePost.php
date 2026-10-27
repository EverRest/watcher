<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use ArrayObject;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Watcher\Source\Retry;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/**
 * One POST for the adapters that talk to a provider over the package's own HTTP client: redirects off, TLS verified, the reply
 * size-bounded. Transient failures (429, 500, 502, 503, 504, a connect or timeout) are retried through {@see Retry}, which honours
 * Retry-After and caps each wait. The whole call (attempts and waits) stays inside `$timeout`: each attempt gets the time left, and
 * a wait that would end past the deadline is not taken. A failure names the exception class or the HTTP status and nothing else:
 * never the URL, the key or the body. Returns the decoded reply with its status.
 */
final class GuzzlePost
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $json
     * @param int                   $retries retries after a transient failure (0 to 3)
     * @param (callable(int): void)|null $sleeper receives whole seconds to wait; defaults to sleep()
     * @param (callable(): float)|null   $now      seconds, monotonic; defaults to hrtime()
     *
     * @return array{0: int, 1: array<mixed>} status and decoded body (empty unless 200)
     */
    public static function json(ClientInterface $http, string $url, array $headers, array $json, float $timeout, string $name, int $retries = 0, ?callable $sleeper = null, ?callable $now = null): array
    {
        /** @var ArrayObject<string, bool> $tripped */
        $tripped = new ArrayObject();
        $clock = $now === null ? static fn (): float => hrtime(true) / 1e9 : Closure::fromCallable($now);
        $deadline = $clock() + $timeout;
        /** @var GuzzleException|null $failure the last transport failure: the original error, named when the call gives up */
        $failure = null;

        $send = static function () use ($http, $url, $headers, $json, $tripped, $clock, $deadline, &$failure): ResponseInterface {
            $left = $deadline - $clock();
            if ($left <= 0) {
                throw new SourceUnavailable('timeout');
            }
            try {
                return $http->request('POST', $url, [
                    'headers' => $headers + ['Accept' => 'application/json'],
                    'json' => $json,
                    'http_errors' => false,
                    'allow_redirects' => false,
                    'verify' => true,
                    'timeout' => $left,
                    'connect_timeout' => 10,
                ] + ResponseLimit::options($tripped)); // a fresh sink per attempt
            } catch (GuzzleException $e) {
                $failure = $e;

                throw $e;
            }
        };
        $wait = static function (int $seconds) use ($clock, $deadline, $sleeper): void {
            if ($clock() + $seconds >= $deadline) {
                throw new SourceUnavailable('timeout'); // no time left for the wait and an attempt after it
            }
            if ($sleeper === null) {
                sleep($seconds);
            } else {
                $sleeper($seconds);
            }
        };

        try {
            $response = (new Retry($wait, (int) ceil($timeout), $retries + 1))->send($send, $name, true, $tripped);
        } catch (Throwable $e) {
            if (isset($tripped['large'])) {
                throw new AnalyzerFailed('provider unavailable (invalid reply)'); // oversized
            }
            if ($failure !== null) {
                throw new AnalyzerFailed('provider unavailable (' . (new \ReflectionClass($failure))->getShortName() . ')'); // not chained: the message carries URL and headers
            }
            if ($e instanceof SourceUnavailable && $e->getMessage() === 'timeout') {
                throw new AnalyzerFailed('provider unavailable (timeout)');
            }
            if ($e instanceof SourceUnavailable) {
                throw new AnalyzerFailed('provider unavailable (invalid reply)'); // oversized or not JSON
            }

            throw new AnalyzerFailed('provider unavailable (' . (new \ReflectionClass($e))->getShortName() . ')'); // not chained: the message carries URL and headers
        }

        $status = $response->getStatusCode();
        try {
            $data = $status === 200 ? ResponseLimit::decode($response, $name) : [];
        } catch (Throwable) {
            throw new AnalyzerFailed('provider unavailable (invalid reply)'); // not JSON, or oversized
        }

        if ($status === 401 || $status === 403) {
            throw new AnalyzerFailed('authentication failed');
        }
        if ($status !== 200) {
            throw new AnalyzerFailed("provider unavailable (HTTP $status)");
        }

        return [$status, $data];
    }
}
