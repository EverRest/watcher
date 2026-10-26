<?php

declare(strict_types=1);

namespace Watcher\Source;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * One guarded read for the metrics and Sentry sources, the same envelope LokiSource has: no redirects (a 3xx is a
 * `bad_response`, the Authorization header never travels), TLS verified, a response capped by {@see ResponseLimit}
 * (headers and decoded body, JSON depth), a wall-clock budget per call plus an optional stop hook (`RunGuard::reason()`),
 * both checked before every attempt, so neither retries nor paging can outlive the run. Failures carry a fixed token,
 * never a URL, token, query or body.
 */
final class GuardedRequest
{
    private float $started;
    /** @var Closure(): float */
    private readonly Closure $now;

    /**
     * @param (Closure(): ?string)|null $stop returns a reason once the run must wind down (wire `RunGuard::reason()`)
     * @param (callable(): float)|null  $now  seconds, monotonic; defaults to hrtime
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly Retry $retry,
        private readonly string $name,
        private readonly int $budgetSeconds = 60,
        private readonly ?Closure $stop = null,
        ?callable $now = null,
    ) {
        $this->now = $now === null ? static fn (): float => hrtime(true) / 1e9 : Closure::fromCallable($now);
        $this->started = ($this->now)();
    }

    /** Starts the wall-clock budget of one source call. */
    public function begin(): void
    {
        $this->started = ($this->now)();
    }

    /**
     * @param array<string, mixed> $options Guzzle options (query, form_params, headers)
     *
     * @return array{0: array<mixed>, 1: ResponseInterface} decoded 2xx body and the response (for headers)
     *
     * @throws SourceUnavailable with one of the fixed tokens
     */
    public function send(string $method, string $url, array $options): array
    {
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        $options = ['http_errors' => false, 'allow_redirects' => false, 'verify' => true] + $options;
        try {
            $response = $this->retry->send(function () use ($method, $url, $options, $tripped): ResponseInterface {
                $this->checkpoint();
                try {
                    return $this->http->request($method, $url, $options + ResponseLimit::options($tripped)); // a fresh sink per attempt
                } catch (GuzzleException $e) {
                    if (preg_match('/timed out|error 28|timeout/i', $e->getMessage()) === 1) { // read for classification only
                        $tripped['timeout'] = true;
                    }
                    throw $e;
                }
            }, $this->name, true);
        } catch (SourceUnavailable $e) {
            throw match (true) {
                $e->getMessage() === 'timeout' => $e,
                isset($tripped['large']) => new SourceUnavailable('too_large'),
                default => new SourceUnavailable(isset($tripped['timeout']) ? 'timeout' : 'unreachable'),
            };
        }

        $status = $response->getStatusCode();
        $failure = match (true) {
            $status === 401 || $status === 403 => 'unauthorized',
            $status === 429 => 'rate_limited',
            $status === 413 || $status === 414 => 'too_large',
            $status >= 500 => 'unreachable',
            $status < 200 || $status >= 300 => 'bad_response', // includes every 3xx: redirects are not followed
            default => null,
        };
        if ($failure !== null) {
            throw new SourceUnavailable($failure);
        }
        try {
            return [ResponseLimit::decode($response, $this->name), $response];
        } catch (SourceUnavailable $e) {
            throw new SourceUnavailable(str_contains($e->getMessage(), 'too large') ? 'too_large' : 'bad_response');
        }
    }

    private function checkpoint(): void
    {
        if (($this->stop !== null && ($this->stop)() !== null) || ($this->now)() - $this->started > $this->budgetSeconds) {
            throw new SourceUnavailable('timeout');
        }
    }
}
