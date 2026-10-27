<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use ArrayObject;
use GuzzleHttp\ClientInterface;
use Throwable;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/**
 * One POST for the adapters that talk to a provider over the package's own HTTP client: redirects off, TLS verified, the reply
 * size-bounded, no client retry (the analyzer's second attempt is the retry). A failure names the exception class or the HTTP
 * status and nothing else: never the URL, the key or the body. Returns the decoded reply with its status.
 */
final class GuzzlePost
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $json
     *
     * @return array{0: int, 1: array<mixed>} status and decoded body (empty unless 200)
     */
    public static function json(ClientInterface $http, string $url, array $headers, array $json, float $timeout, string $name): array
    {
        /** @var ArrayObject<string, bool> $tripped */
        $tripped = new ArrayObject();
        try {
            $response = $http->request('POST', $url, [
                'headers' => $headers + ['Accept' => 'application/json'],
                'json' => $json,
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
                'timeout' => $timeout,
                'connect_timeout' => 10,
            ] + ResponseLimit::options($tripped));
            $status = $response->getStatusCode();
            $data = $status === 200 ? ResponseLimit::decode($response, $name) : [];
        } catch (Throwable $e) {
            if ($e instanceof SourceUnavailable || isset($tripped['large'])) {
                throw new AnalyzerFailed('provider unavailable (invalid reply)'); // oversized or not JSON
            }
            throw new AnalyzerFailed('provider unavailable (' . (new \ReflectionClass($e))->getShortName() . ')'); // not chained: the message carries URL and headers
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
