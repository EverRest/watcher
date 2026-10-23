<?php

declare(strict_types=1);

namespace Watcher\Operate;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use JsonException;
use Throwable;

/** GET /v1/models (free, no tokens) with both auth schemes. Reasons carry a status or a fixed word, never a body, URL or key. */
final class AnthropicModelCatalog implements ModelCatalog
{
    private const TIMEOUT = 5;
    private const MAX_PAGES = 5;

    /** @var list<string>|null */
    private ?array $ids = null;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.anthropic.com',
    ) {
    }

    public function probe(): array
    {
        $result = [];
        $ids = [];
        foreach (['bearer' => ['Authorization' => 'Bearer ' . $this->apiKey], 'x-api-key' => ['x-api-key' => $this->apiKey]] as $scheme => $auth) {
            [$status, $found] = $this->list($auth);
            $result[$scheme] = $status;
            if ($ids === [] && $status === 200) {
                $ids = $found;
            }
        }
        $this->ids = $ids;

        return $result;
    }

    public function modelIds(): array
    {
        if ($this->ids === null) {
            $this->probe();
        }

        return $this->ids ?? [];
    }

    /**
     * @param array<string, string> $auth
     * @return array{0: int|string, 1: list<string>}
     */
    private function list(array $auth): array
    {
        $ids = [];
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            try {
                $response = $this->http->request('GET', rtrim($this->baseUrl, '/') . '/v1/models', [
                    'headers' => $auth + ['anthropic-version' => '2023-06-01', 'Accept' => 'application/json'],
                    'query' => ['limit' => 1000] + ($after === null ? [] : ['after_id' => $after]),
                    'timeout' => self::TIMEOUT,
                    'connect_timeout' => self::TIMEOUT,
                    'http_errors' => false,
                ]);
            } catch (ConnectException) {
                return ['timeout', []];
            } catch (Throwable) {
                return ['error', []];
            }
            if ($response->getStatusCode() !== 200) {
                return [$response->getStatusCode(), []];
            }
            try {
                $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [200, $ids];
            }
            foreach (is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [] as $m) {
                if (is_array($m) && is_string($m['id'] ?? null)) {
                    $ids[] = $m['id'];
                }
            }
            $after = is_array($body) && ($body['has_more'] ?? false) === true && is_string($body['last_id'] ?? null) ? $body['last_id'] : null;
            if ($after === null) {
                break;
            }
        }

        return [200, $ids];
    }
}
