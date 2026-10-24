<?php

declare(strict_types=1);

namespace Watcher\Source;

use Closure;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Bounded retries for read-only HTTP calls: 3 attempts, waits 1 s then 3 s (x3, cap 30 s), a 429 waits what the
 * server asks for (also capped), and the sum of all waits stays inside a budget. Never surfaces URLs or credentials.
 */
final class Retry
{
    private const ATTEMPTS = 3;
    private const MAX_WAIT = 30;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;
    private int $waited = 0;

    /** @param (callable(int): void)|null $sleeper whole seconds to wait; defaults to sleep() */
    public function __construct(?callable $sleeper = null, private readonly int $budget = 120)
    {
        $this->sleeper = $sleeper === null ? static function (int $s): void {
            sleep($s);
        } : Closure::fromCallable($sleeper);
    }

    /**
     * Returns the final response (the caller turns a non-2xx one into its own message) or throws for a network failure.
     *
     * @param Closure(): ResponseInterface $send
     */
    public function send(Closure $send, string $name, bool $retryOn500): ResponseInterface
    {
        $this->waited = 0; // the budget is per call, so one slow page cannot starve later ones
        for ($attempt = 1;; ++$attempt) {
            try {
                $response = $send();
            } catch (GuzzleException $e) {
                if ($attempt >= self::ATTEMPTS || ($e instanceof RequestException && $e->hasResponse()) || !$this->wait($attempt, null)) {
                    throw new SourceUnavailable($name . ' request failed (' . $e::class . ')', 0, $e);
                }
                continue;
            }
            $status = $response->getStatusCode();
            $transient = in_array($status, [429, 502, 503, 504], true) || ($retryOn500 && $status === 500);
            if (!$transient || $attempt >= self::ATTEMPTS || !$this->wait($attempt, $status === 429 ? $response : null)) {
                return $response;
            }
        }
    }

    /**
     * For non-idempotent POSTs: retries only a 429, so a request the server may have accepted is never repeated.
     * The wait is `$hint(response)` seconds (may be fractional), else `Retry-After`, else 1 s. A single wait is capped
     * at 30 s; a wait above what is left of the budget gives up at once. The budget is NOT reset per call: one
     * instance serves all parts of one message.
     *
     * @param Closure(): ResponseInterface $send
     * @param (Closure(ResponseInterface): (int|float|null))|null $hint
     */
    public function sendRateLimited(Closure $send, string $name, ?Closure $hint = null): ResponseInterface
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $response = $send();
            } catch (GuzzleException $e) {
                throw new SourceUnavailable($name . ' request failed (' . $e::class . ')', 0, $e);
            }
            if ($response->getStatusCode() !== 429 || $attempt >= self::ATTEMPTS) {
                return $response;
            }
            $asked = $hint === null ? null : $hint($response);
            $header = $response->getHeaderLine('Retry-After');
            $seconds = max(1, (int) ceil($asked ?? (is_numeric($header) ? (float) $header : 1)));
            if ($this->waited + $seconds > $this->budget) {
                return $response;
            }
            $seconds = min(self::MAX_WAIT, $seconds);
            $this->waited += $seconds;
            ($this->sleeper)($seconds);
        }
    }

    private function wait(int $attempt, ?ResponseInterface $rateLimited): bool
    {
        $seconds = (int) (3 ** ($attempt - 1));
        if ($rateLimited !== null) {
            $hint = $rateLimited->getHeaderLine('Retry-After') ?: $rateLimited->getHeaderLine('X-RateLimit-Reset');
            $seconds = is_numeric($hint) ? (int) $hint : 1;
        }
        $seconds = max(1, min(self::MAX_WAIT, $seconds));
        if ($this->waited + $seconds > $this->budget) {
            return false;
        }
        $this->waited += $seconds;
        ($this->sleeper)($seconds);

        return true;
    }
}
