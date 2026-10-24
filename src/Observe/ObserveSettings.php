<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Watcher\Config\InvalidConfig;

/**
 * The validated `observe` section (specs/013 contracts/config.md). Every sink is off unless its own `enabled` is true.
 * The http URL and token are kept in closures, like Destination: a dump, var_export or json_encode of this object holds neither.
 */
final class ObserveSettings implements \JsonSerializable
{
    private const SINKS = ['log', 'file', 'http', 'prometheus'];

    /** @var \Closure(): array{target: ?HttpTarget, token: ?string} */
    private readonly \Closure $secret;

    /**
     * @param array{enabled: bool, stream: string, include_text: bool} $log
     * @param array{enabled: bool, path: string, rotate: string, max_bytes: int, max_files: int, retention_days: int, include_text: bool} $file
     * @param array{enabled: bool, auth_header: string, auth_scheme: string, batch_size: int, timeout: int, allowed_hosts: list<string>, allow_insecure: bool, allow_private_network: bool, include_text: bool} $http
     * @param array{enabled: bool, dir: string, name: string, file_mode: int} $prometheus
     */
    private function __construct(
        public readonly int $topGroups,
        public readonly array $log,
        public readonly array $file,
        public readonly array $http,
        public readonly array $prometheus,
        #[\SensitiveParameter] ?HttpTarget $target,
        #[\SensitiveParameter] ?string $token,
    ) {
        $this->secret = static fn (): array => ['target' => $target, 'token' => $token];
    }

    public function anyEnabled(): bool
    {
        return $this->log['enabled'] || $this->file['enabled'] || $this->http['enabled'] || $this->prometheus['enabled'];
    }

    /** The validated URL of the http sink, or null when none is configured. Only the sink factory should call this. */
    public function httpTarget(): ?HttpTarget
    {
        return ($this->secret)()['target'];
    }

    public function httpToken(): ?string
    {
        return ($this->secret)()['token'];
    }

    /** @return list<string> the credentials of the sinks (URL, token): masked everywhere by WatchConfig::secrets() */
    public function secrets(): array
    {
        $s = ($this->secret)();

        return array_values(array_filter([$s['target']?->url(), $s['token']], static fn (?string $v): bool => $v !== null && $v !== ''));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secrets' => '[redacted]'];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        throw new \LogicException('ObserveSettings is not serialisable.');
    }

    /**
     * @param mixed $raw the `observe` value of the config
     * @param string $stem default Prometheus file stem source: service[_environment]
     * @param string|null $logSourceHost host (or URL) of the monitored log backend: an http sink must never point at it
     */
    public static function fromArray(#[\SensitiveParameter] mixed $raw, string $stem, ?string $logSourceHost): self
    {
        if (!is_array($raw)) {
            throw new InvalidConfig('observe must be a map');
        }
        if (array_key_exists('otlp', $raw)) {
            throw new InvalidConfig('observe.otlp is not available yet');
        }
        self::only($raw, ['top_groups', ...self::SINKS], 'observe');
        $top = self::int($raw, 'top_groups', 20, 0, 100, 'observe.top_groups');
        $sink = static function (string $name) use ($raw): array {
            $v = $raw[$name] ?? [];
            if (!is_array($v)) {
                throw new InvalidConfig('observe.' . $name . ' must be a map');
            }

            return $v;
        };

        $l = $sink('log');
        self::only($l, ['enabled', 'stream', 'include_text'], 'observe.log');
        $log = ['enabled' => self::bool($l, 'enabled', false, 'observe.log.enabled'), 'stream' => $l['stream'] ?? 'stderr', 'include_text' => self::bool($l, 'include_text', false, 'observe.log.include_text')];
        if (!in_array($log['stream'], ['stderr', 'stdout'], true)) {
            throw new InvalidConfig('observe.log.stream must be stderr or stdout');
        }

        $f = $sink('file');
        self::only($f, ['enabled', 'path', 'rotate', 'max_bytes', 'max_files', 'retention_days', 'include_text'], 'observe.file');
        $file = [
            'enabled' => self::bool($f, 'enabled', false, 'observe.file.enabled'),
            'path' => '',
            'rotate' => $f['rotate'] ?? 'size',
            'max_bytes' => self::int($f, 'max_bytes', 10485760, 1048576, 1073741824, 'observe.file.max_bytes'),
            'max_files' => self::int($f, 'max_files', 10, 1, 100, 'observe.file.max_files'),
            'retention_days' => self::int($f, 'retention_days', 30, 1, 365, 'observe.file.retention_days'),
            'include_text' => self::bool($f, 'include_text', false, 'observe.file.include_text'),
        ];
        if (!in_array($file['rotate'], ['size', 'daily'], true)) {
            throw new InvalidConfig('observe.file.rotate must be size or daily');
        }
        if (isset($f['path']) || $file['enabled']) {
            $path = $f['path'] ?? null;
            if (!is_string($path) || $path === '') {
                throw new InvalidConfig('observe.file.path is required');
            }
            if (self::badPath($path)) {
                throw new InvalidConfig('observe.file.path must be an absolute path without ..');
            }
            if (is_link($path)) {
                throw new InvalidConfig('observe.file.path must not be a symlink');
            }
            $file['path'] = $path;
        }

        // The credentials leave the array first: no validation helper below ever receives them, so a throw cannot put them in a trace.
        $h = $sink('http');
        $url = $h['url'] ?? null;
        $token = $h['auth_token'] ?? null;
        unset($h['url'], $h['auth_token']);
        unset($raw);
        self::only($h, ['enabled', 'url', 'auth_header', 'auth_scheme', 'auth_token', 'batch_size', 'timeout', 'allowed_hosts', 'allow_insecure', 'allow_private_network', 'include_text'], 'observe.http');
        $http = [
            'enabled' => self::bool($h, 'enabled', false, 'observe.http.enabled'),
            'auth_header' => $h['auth_header'] ?? 'Authorization',
            'auth_scheme' => $h['auth_scheme'] ?? 'Bearer',
            'batch_size' => self::int($h, 'batch_size', 100, 1, 1000, 'observe.http.batch_size'),
            'timeout' => self::int($h, 'timeout', 5, 1, 30, 'observe.http.timeout'),
            'allowed_hosts' => [],
            'allow_insecure' => self::bool($h, 'allow_insecure', false, 'observe.http.allow_insecure'),
            'allow_private_network' => self::bool($h, 'allow_private_network', false, 'observe.http.allow_private_network'),
            'include_text' => self::bool($h, 'include_text', false, 'observe.http.include_text'),
        ];
        if (!is_string($http['auth_header']) || preg_match('/^[A-Za-z0-9-]{1,64}$/D', $http['auth_header']) !== 1) {
            throw new InvalidConfig('observe.http.auth_header must be a header name');
        }
        if (!is_string($http['auth_scheme']) || preg_match('/^[A-Za-z0-9._~+-]{0,32}$/D', $http['auth_scheme']) !== 1) {
            throw new InvalidConfig('observe.http.auth_scheme is invalid');
        }
        $hosts = $h['allowed_hosts'] ?? [];
        if (!is_array($hosts) || array_filter($hosts, static fn (mixed $x): bool => !is_string($x) || $x === '') !== []) {
            throw new InvalidConfig('observe.http.allowed_hosts must be a list of host names');
        }
        $http['allowed_hosts'] = array_values(array_map('strtolower', $hosts));
        if ($token !== null && (!is_string($token) || strlen($token) < 8)) {
            throw new InvalidConfig('observe.http.auth_token must be at least 8 characters');
        }
        $target = null;
        if ($url !== null || $http['enabled']) {
            if (!is_string($url) || $url === '') {
                throw new InvalidConfig('observe.http.url is required');
            }
            $target = HttpTarget::parse($url, $http['allowed_hosts'], $http['allow_insecure'], $http['allow_private_network'], $logSourceHost);
        }

        $p = $sink('prometheus');
        self::only($p, ['enabled', 'dir', 'name', 'file_mode'], 'observe.prometheus');
        $prom = ['enabled' => self::bool($p, 'enabled', false, 'observe.prometheus.enabled'), 'dir' => '', 'name' => self::stem($stem), 'file_mode' => 0644];
        if (isset($p['dir']) || $prom['enabled']) {
            $dir = $p['dir'] ?? null;
            if (!is_string($dir) || $dir === '') {
                throw new InvalidConfig('observe.prometheus.dir is required');
            }
            if (self::badPath($dir)) {
                throw new InvalidConfig('observe.prometheus.dir is invalid');
            }
            $prom['dir'] = rtrim($dir, '/');
        }
        if (isset($p['name'])) {
            if (!is_string($p['name']) || preg_match('/^[a-z0-9_]{1,64}$/D', $p['name']) !== 1) {
                throw new InvalidConfig('observe.prometheus.name is invalid');
            }
            $prom['name'] = $p['name'];
        }
        if (isset($p['file_mode'])) {
            $mode = $p['file_mode'];
            if (!is_string($mode) || preg_match('/^0[0-7]{3}$/D', $mode) !== 1 || octdec($mode) < 0600 || octdec($mode) > 0664) {
                throw new InvalidConfig('observe.prometheus.file_mode is invalid');
            }
            $prom['file_mode'] = (int) octdec($mode);
        }

        return new self($top, $log, $file, $http, $prom, $target, $token);
    }

    private static function badPath(string $path): bool
    {
        return $path[0] !== '/' || in_array('..', explode('/', $path), true) || str_contains($path, "\0");
    }

    /** `watcher_<service>[_<environment>]` reduced to [a-z0-9_] and 64 characters. */
    private static function stem(string $stem): string
    {
        return substr('watcher_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($stem)), '_'), 0, 64);
    }

    /**
     * @param array<array-key, mixed> $section
     * @param list<string> $allowed
     */
    private static function only(#[\SensitiveParameter] array $section, array $allowed, string $path): void
    {
        foreach (array_keys($section) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new InvalidConfig('Unknown configuration key: ' . $path . '.' . $key);
            }
        }
    }

    /** @param array<array-key, mixed> $from */
    private static function bool(#[\SensitiveParameter] array $from, string $key, bool $default, string $path): bool
    {
        $v = $from[$key] ?? $default;
        if (!is_bool($v)) {
            throw new InvalidConfig($path . ' must be true or false');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    private static function int(#[\SensitiveParameter] array $from, string $key, int $default, int $min, int $max, string $path): int
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new InvalidConfig($path . ' must be an integer between ' . $min . ' and ' . $max);
        }

        return $v;
    }
}
