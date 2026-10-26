<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

use ArrayObject;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Watcher\Config\SecureUrl;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/**
 * Reads one OpenAPI JSON document from a local file or an https URL (research R2). Failure is a fixed token in the
 * exception message (never a URL, path, header or body): unreachable, unauthorized, timeout, too_large, bad_format,
 * unsupported_format, unreadable, unsafe_source (URL refused before any request).
 *
 * Remote: Config\SecureUrl (https; plain http only with allow_insecure AND allow_private_network AND a loopback/RFC 1918/cluster-local host; no userinfo; no link-local or metadata host); redirects off; TLS verified. File: `file:` or an absolute canonical path (no `..`, no symlink in any component),
 * below `api_context.allowed_roots` when set, no world-writable ancestor without the sticky bit, an opened regular file that is not world-writable.
 * ponytail: a host NAME is not resolved, so DNS that points at a private address passes; resolve-and-pin if specs ever come from untrusted config.
 */
final class OpenApiSource
{
    public const DEFAULT_MAX_BYTES = 1048576;
    /** 2 MB of small JSON objects decodes to ~144 MB (research R1); 8 MB would be an uncatchable memory fatal. */
    public const MAX_BYTES_CEILING = 2097152;

    private const DEPTH = 64;
    private const CHUNK = 8192;
    private const DEADLINE_SECONDS = 15;

    private readonly int $maxBytes;

    /** The URL or path (a URL may carry a token) and the bearer token, held in closures so no dump or serialization shows them. */
    private readonly Closure $source;

    private readonly Closure $authToken;

    private readonly string $sourceId;

    public function __construct(
        private readonly ClientInterface $http,
        #[\SensitiveParameter] string $source,
        #[\SensitiveParameter] ?string $authToken = null,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $fileSize = null,
        private readonly bool $allowInsecure = false,
        /** @var list<string> config `api_context.allowed_roots`: when set, a file source must lie below one of them */
        private readonly array $allowedRoots = [],
        /** `api_context.allow_private_network`: with allow_insecure, admits plain http to a loopback, RFC 1918 or cluster-local host; alone, admits those hosts over https */
        private readonly bool $allowPrivateNetwork = false,
    ) {
        $this->maxBytes = min($maxBytes, self::MAX_BYTES_CEILING);
        $this->source = static fn (): string => $source;
        $this->authToken = static fn (): ?string => $authToken;
        $this->sourceId = substr(hash('sha256', $source), 0, 12);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('OpenApiSource is not serializable');
    }

    /** Cache key component: a hash, so the URL or path never appears in a key. */
    public function id(): string
    {
        return $this->sourceId;
    }

    /**
     * @return array<mixed>
     *
     * @throws SourceUnavailable
     */
    public function read(): array
    {
        return self::decode(preg_match('~^https?://~i', ($this->source)()) === 1 ? $this->fetch() : $this->readFile());
    }

    private function readFile(): string
    {
        $path = ($this->source)();
        if (preg_match('~^file:(?://)?(/.*)$~is', $path, $m) === 1) {
            $path = $m[1];
        }
        if (str_contains($path, '://') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1 || !str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new SourceUnavailable('unreadable');
        }
        // The path must already be the canonical one: no symlink in ANY component, no `//`, `/./` or trailing `/`.
        $real = realpath($path);
        if ($real === false || $real !== '/' . implode('/', array_filter(explode('/', $path), static fn (string $p): bool => $p !== '' && $p !== '.'))
            || !$this->underAllowedRoot($real) || $this->unsafeAncestor($real) || !is_file($real) // is_file only keeps a FIFO from blocking fopen
        ) {
            throw new SourceUnavailable('unreadable'); // one token for missing, outside the roots, unsafe: no oracle for arbitrary files
        }
        $h = @fopen($real, 'rb');
        if ($h === false) {
            throw new SourceUnavailable('unreadable');
        }
        // Judge the OPENED handle, and that the name still points at it (a swap between realpath and fopen shows as another inode or a link).
        $st = fstat($h);
        $now = @lstat($real);
        if ($st === false || $now === false || ($st['mode'] & 0170000) !== 0100000 || ($st['mode'] & 0002) !== 0 || $st['ino'] !== $now['ino'] || $st['dev'] !== $now['dev'] || ($now['mode'] & 0170000) !== 0100000) {
            fclose($h);
            throw new SourceUnavailable('unreadable');
        }
        $size = $this->fileSize !== null ? ($this->fileSize)($real) : $st['size'];
        if ($size > $this->maxBytes) {
            fclose($h);
            throw new SourceUnavailable('too_large');
        }
        $raw = fread($h, max(1, $this->maxBytes + 1)); // the file may have grown since fstat()
        fclose($h);
        if ($raw === false) {
            throw new SourceUnavailable('unreadable');
        }
        if (strlen($raw) > $this->maxBytes) {
            throw new SourceUnavailable('too_large');
        }

        return $raw;
    }

    /** With no roots configured the operator's own source path is read; with roots it must lie below one (real paths, whole components). */
    private function underAllowedRoot(string $real): bool
    {
        if ($this->allowedRoots === []) {
            return true;
        }
        foreach ($this->allowedRoots as $root) {
            $r = realpath($root);
            if ($r !== false && str_starts_with($real, rtrim($r, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /** A directory above the file that anyone can write to and that has no sticky bit lets another user swap the file or a component. */
    private function unsafeAncestor(string $real): bool
    {
        for ($dir = dirname($real); ; $dir = dirname($dir)) {
            $mode = @fileperms($dir);
            if ($mode === false || (($mode & 0002) !== 0 && ($mode & 01000) === 0)) {
                return true;
            }
            if ($dir === '/') {
                return false;
            }
        }
    }

    private function fetch(): string
    {
        $url = ($this->source)();
        if ($this->unsafe($url)) {
            throw new SourceUnavailable('unsafe_source');
        }
        $tripped = new ArrayObject(['large' => false]);
        $headers = ['Accept' => 'application/json'];
        $token = ($this->authToken)();
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $started = $this->now();
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => $headers,
                'stream' => true,
                'connect_timeout' => 5,
                'timeout' => 15,
                'allow_redirects' => false,
                'verify' => true,
            ] + ResponseLimit::options($tripped));
            if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
                throw new SourceUnavailable(in_array($response->getStatusCode(), [401, 403], true) ? 'unauthorized' : 'unreachable');
            }
            $declared = $response->getHeaderLine('Content-Length');
            if ($declared !== '' && (int) $declared > $this->maxBytes) {
                throw new SourceUnavailable('too_large');
            }
            $body = $response->getBody();
            $body->rewind(); // the sink (ResponseLimit::options) leaves the position at the end of what it wrote
            $raw = '';
            while (!$body->eof() && strlen($raw) <= $this->maxBytes) { // the cap is on the decoded stream
                if ($this->now() - $started > self::DEADLINE_SECONDS) { // a trickling server cannot hold the run
                    throw new SourceUnavailable('timeout');
                }
                $raw .= $body->read(self::CHUNK);
            }
        } catch (SourceUnavailable $e) {
            throw $tripped['large'] ? new SourceUnavailable('too_large') : $e; // the sink's own message is not one of this source's tokens
        } catch (Throwable $e) {
            throw new SourceUnavailable($tripped['large'] ? 'too_large' : self::failure($e));
        }
        if (strlen($raw) > $this->maxBytes) {
            throw new SourceUnavailable('too_large');
        }

        return $raw;
    }

    private function unsafe(#[\SensitiveParameter] string $url): bool
    {
        return self::urlProblem($url, $this->allowInsecure, $this->allowPrivateNetwork) !== null;
    }

    /**
     * The shared classifier (Config\SecureUrl), same rule as observe.http: https; plain http only with allow_insecure AND
     * allow_private_network AND a loopback, RFC 1918 or cluster-local host; a private host over https needs allow_private_network;
     * link-local, CGNAT, metadata and reserved ranges are refused whatever is allowed. Also used by the config validation.
     */
    public static function urlProblem(#[\SensitiveParameter] string $url, bool $allowInsecure, bool $allowPrivateNetwork): ?string
    {
        $problem = SecureUrl::problem($url, $allowInsecure && $allowPrivateNetwork);
        if ($problem !== null) {
            return $problem;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return !is_string($host) || SecureUrl::internalHost($host, $allowPrivateNetwork, $allowPrivateNetwork)
            ? 'must not point at a loopback, link-local, private-network or metadata address'
            : null;
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private static function failure(Throwable $e): string
    {
        // Two Guzzle majors: 7 has RequestException::getResponse() and ConnectException::getHandlerContext(); 8 has neither (timeouts are classes).
        // ponytail: when Guzzle 7 is dropped this is `ResponseException` / `NetworkTimeoutException` / `ConnectTimeoutException` / `ResponseTimeoutException` checks.
        $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
        if ($response instanceof ResponseInterface) {
            return in_array($response->getStatusCode(), [401, 403], true) ? 'unauthorized' : 'unreachable';
        }
        foreach (['NetworkTimeoutException', 'ConnectTimeoutException', 'ResponseTimeoutException'] as $timeout) {
            if (is_a($e, 'GuzzleHttp\\Exception\\' . $timeout)) {
                return 'timeout';
            }
        }
        $errno = method_exists($e, 'getHandlerContext') ? ($e->getHandlerContext()['errno'] ?? 0) : 0;
        if ($e instanceof ConnectException && ($errno === 28 || str_contains($e->getMessage(), 'timed out'))) {
            return 'timeout';
        }

        return 'unreachable';
    }

    /** @return array<mixed> */
    private static function decode(string $raw): array
    {
        $trimmed = ltrim($raw);
        if ($trimmed === '') {
            throw new SourceUnavailable('bad_format');
        }
        if ($trimmed[0] !== '{') {
            throw new SourceUnavailable('unsupported_format');
        }
        try {
            $doc = json_decode($trimmed, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceUnavailable('bad_format');
        }

        return is_array($doc) ? $doc : throw new SourceUnavailable('bad_format');
    }
}
