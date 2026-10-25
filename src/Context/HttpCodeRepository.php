<?php

declare(strict_types=1);

namespace Watcher\Context;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class HttpCodeRepository implements CodeRepository
{
    /** @param array{provider: string, web_url: string, path_map: array<mixed>, api_token: string} $repo */
    public function __construct(
        private readonly array $repo,
        private readonly ClientInterface $http,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function readFile(string $commit, string $path, int $maxBytes): string
    {
        $url = $this->rawUrl($commit, $path);
        try {
            $res = $this->http->request('GET', $url, [
                'headers' => $this->headers(),
                'timeout' => $this->timeoutSeconds,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new CodeRepositoryException('repository read failed', 0, $e);
        }
        if ($res->getStatusCode() !== 200) {
            throw new CodeRepositoryException('repository read returned HTTP ' . $res->getStatusCode());
        }
        $body = (string) $res->getBody();
        if (strlen($body) > $maxBytes) {
            throw new CodeRepositoryException('repository file exceeds size cap');
        }

        return $body;
    }

    private function rawUrl(string $commit, string $path): string
    {
        $web = rtrim((string) $this->repo['web_url'], '/');
        $segments = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $encCommit = rawurlencode($commit);

        return match ($this->repo['provider']) {
            'gitlab' => $web . '/-/raw/' . $encCommit . '/' . $segments,
            'github' => $this->githubRaw($web, $encCommit, $segments),
            default => throw new CodeRepositoryException('unsupported repository provider'),
        };
    }

    private function githubRaw(string $web, string $commit, string $segments): string
    {
        $basePath = trim(parse_url($web)['path'] ?? '', '/');
        if ($basePath === '') {
            throw new CodeRepositoryException('repo.web_url must include owner and repository');
        }

        return 'https://raw.githubusercontent.com/' . $basePath . '/' . $commit . '/' . $segments;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $token = $this->repo['api_token'];

        return match ($this->repo['provider']) {
            'gitlab' => ['PRIVATE-TOKEN' => $token],
            'github' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github.raw'],
            default => [],
        };
    }
}
