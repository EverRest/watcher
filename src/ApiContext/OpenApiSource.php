<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

use ArrayObject;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Throwable;
use Watcher\Config\SecureUrl;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/**
 * Reads one OpenAPI JSON document from a local file or an https URL (research R2). Failure is a fixed token in the
 * exception message (never a URL, path, header or body): unreachable, unauthorized, timeout, too_large, bad_format,
 * unsupported_format, unreadable, unsafe_source (URL refused before any request).
 *
 * Remote: Config\SecureUrl (https, no userinfo, loopback only with allow_insecure) plus no private, link-local or metadata
 * address; redirects off; TLS verified. File: `file:` or an absolute canonical path (no `..`, no symlink in any component),
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

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $source,
        private readonly ?string $authToken = null,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $fileSize = null,
        private readonly bool $allowInsecure = false,
        /** @var list<string> config `api_context.allowed_roots`: when set, a file source must lie below one of them */
        private readonly array $allowedRoots = [],
    ) {
        $this->maxBytes = min($maxBytes, self::MAX_BYTES_CEILING);
    }

    /** Cache key component: a hash, so the URL or path never appears in a key. */
    public function id(): string
    {
        return substr(hash('sha256', $this->source), 0, 12);
    }

    /**
     * @return array<mixed>
     *
     * @throws SourceUnavailable
     */
    public function read(): array
    {
        return self::decode(preg_match('~^https?://~i', $this->source) === 1 ? $this->fetch() : $this->readFile());
    }

    private function readFile(): string
    {
        $path = $this->source;
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
        if (self::unsafe($this->source, $this->allowInsecure)) {
            throw new SourceUnavailable('unsafe_source');
        }
        $tripped = new ArrayObject(['large' => false]);
        $headers = ['Accept' => 'application/json'];
        if ($this->authToken !== null && $this->authToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->authToken;
        }
        $started = $this->now();
        try {
            $response = $this->http->request('GET', $this->source, [
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
            $raw = '';
            while (!$body->eof() && strlen($raw) <= $this->maxBytes) { // the cap is on the decoded stream
                if ($this->now() - $started > self::DEADLINE_SECONDS) { // a trickling server cannot hold the run
                    throw new SourceUnavailable('timeout');
                }
                $raw .= $body->read(self::CHUNK);
            }
        } catch (SourceUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SourceUnavailable($tripped['large'] ? 'too_large' : self::failure($e));
        }
        if (strlen($raw) > $this->maxBytes) {
            throw new SourceUnavailable('too_large');
        }

        return $raw;
    }

    /** The shared classifier (Config\SecureUrl): https, no userinfo, and no internal, private, link-local or metadata host. */
    private static function unsafe(string $url, bool $allowInsecure): bool
    {
        return SecureUrl::problem($url, $allowInsecure, true) !== null;
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private static function failure(Throwable $e): string
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return in_array($e->getResponse()->getStatusCode(), [401, 403], true) ? 'unauthorized' : 'unreachable';
        }
        if ($e instanceof ConnectException && (($e->getHandlerContext()['errno'] ?? 0) === 28 || str_contains($e->getMessage(), 'timed out'))) {
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
