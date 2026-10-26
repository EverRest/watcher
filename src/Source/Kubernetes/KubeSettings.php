<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use InvalidArgumentException;
use Watcher\Config\SecureUrl;
use Watcher\Source\LogEvent;

/**
 * Validated `kubernetes` settings (spec 008, contracts/config.md). Built by WatchConfig; every failure is an
 * InvalidArgumentException naming the key path, never a value (the token is a secret).
 */
final class KubeSettings implements \JsonSerializable
{
    public const DEFAULT_KINDS = ['Pod', 'Deployment', 'ReplicaSet', 'StatefulSet', 'DaemonSet', 'Job', 'HorizontalPodAutoscaler'];
    public const DEFAULT_CRITICAL = ['CrashLoopBackOff', 'BackOff', 'OOMKilled', 'ImagePullBackOff', 'ErrImagePull', 'Failed', 'Evicted', 'FailedMount', 'FailedScheduling'];
    public const MAX_TOKEN_BYTES = 16384;
    private const MAX_CA_BYTES = 1048576;
    private const KEYS = ['enabled', 'api', 'namespaces', 'label_selector', 'involved_kinds', 'critical_reasons', 'min_repeat_for_probe_failures',
        'event_cap', 'tolerance', 'event_ttl', 'ledger_ttl', 'allow', 'exporter'];

    /**
     * @param list<string> $namespaces
     * @param list<string> $involvedKinds
     * @param list<string> $criticalReasons
     * @param list<string> $allowRegistries
     * @param list<string> $allowImages
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly bool $inCluster,
        public readonly ?string $server,
        #[\SensitiveParameter]
        ?string $token,
        ?string $tokenFile,
        public readonly ?string $caFile,
        public readonly array $namespaces,
        public readonly ?LabelSelector $labelSelector,
        public readonly array $involvedKinds,
        public readonly array $criticalReasons,
        public readonly int $minRepeatForProbeFailures,
        public readonly int $eventCap,
        public readonly int $tolerance,
        public readonly int $eventTtl,
        public readonly ?int $ledgerTtl,
        public readonly array $allowRegistries,
        public readonly array $allowImages,
        public readonly bool $exporter,
        public readonly bool $events,
        public readonly bool $asSource,
    ) {
        // Held in SecretValue objects, not in strings: no dump, export or cast prints them.
        $this->tokenOf = $token === null ? null : new SecretValue($token);
        $this->tokenFileOf = $tokenFile === null ? null : new SecretValue($tokenFile);
    }

    private readonly ?SecretValue $tokenOf;

    private readonly ?SecretValue $tokenFileOf;

    /** The API bearer token given inline, or null (token_file or in-cluster). */
    public function token(): ?string
    {
        return $this->tokenOf?->reveal();
    }

    /** The path of the token file, or null. */
    public function tokenFile(): ?string
    {
        return $this->tokenFileOf?->reveal();
    }

    /**
     * @param array<string, mixed> $s the section without `enabled`'s sibling concerns (flag read by the caller too)
     * @param bool $events   `events: {driver: kubernetes}` is declared beside `source`
     * @param bool $asSource `source.driver` is `kubernetes`
     * @throws InvalidArgumentException
     */
    public static function fromArray(#[\SensitiveParameter] array $s, bool $enabled, bool $events, bool $asSource): self
    {
        foreach (array_keys($s) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException('Unknown configuration key: kubernetes.' . $key);
            }
        }
        [$inCluster, $server, $token, $tokenFile, $caFile] = self::api($s['api'] ?? []);
        $selector = null;
        if (array_key_exists('label_selector', $s)) {
            if (!is_string($s['label_selector'])) {
                throw new InvalidArgumentException('kubernetes.label_selector is not a valid selector');
            }
            try {
                $selector = LabelSelector::parse($s['label_selector']);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('kubernetes.label_selector is not a valid selector');
            }
        }
        $allow = $s['allow'] ?? [];
        if (!is_array($allow)) {
            throw new InvalidArgumentException('kubernetes.allow must be a map');
        }
        foreach (array_keys($allow) as $key) {
            if (!in_array($key, ['registries', 'images'], true)) {
                throw new InvalidArgumentException('Unknown configuration key: kubernetes.allow.' . $key);
            }
        }

        $eventTtl = self::int($s, 'event_ttl', 3600, 1);
        $ledgerTtl = array_key_exists('ledger_ttl', $s) ? self::int($s, 'ledger_ttl', 0, 1) : null;
        if ($ledgerTtl !== null && $ledgerTtl < $eventTtl + 3600) {
            throw new InvalidArgumentException('kubernetes.ledger_ttl must be at least event_ttl + 3600 seconds, or the record expires between hourly triggers');
        }

        return new self(
            $enabled,
            $inCluster,
            $server,
            $token,
            $tokenFile,
            $caFile,
            self::namespaces($s['namespaces'] ?? null),
            $selector,
            self::words($s['involved_kinds'] ?? self::DEFAULT_KINDS, 'kubernetes.involved_kinds', '/^[A-Z][A-Za-z]{1,40}$/', LogEvent::WORKLOAD_KINDS),
            self::words($s['critical_reasons'] ?? self::DEFAULT_CRITICAL, 'kubernetes.critical_reasons', '/^[A-Za-z][A-Za-z0-9]{0,62}$/'),
            self::int($s, 'min_repeat_for_probe_failures', 3, 1),
            self::int($s, 'event_cap', 5000, 1),
            self::int($s, 'tolerance', 120, 0),
            $eventTtl,
            $ledgerTtl,
            self::words($allow['registries'] ?? [], 'kubernetes.allow.registries', '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/'),
            self::words($allow['images'] ?? [], 'kubernetes.allow.images', '#^[a-z0-9]([a-z0-9._/-]*[a-z0-9])?$#'),
            self::bool($s, 'exporter', 'kubernetes.exporter'),
            $events,
            $asSource,
        );
    }

    /** @return array<string, mixed> var_dump and print_r show the shape, never the token or where the token file lives */
    public function __debugInfo(): array
    {
        return ['enabled' => $this->enabled, 'inCluster' => $this->inCluster, 'namespaces' => $this->namespaces, 'credentials' => '<redacted>'];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('KubeSettings holds a credential and is not serialisable');
    }

    /**
     * Values that must never reach a failure reason, a notice or the heartbeat: the token, the CURRENT content of token_file or of the
     * in-cluster service-account token file (default path, or the given directory)
     * (it rotates, so it is read when asked). Never throws: a file that cannot be read masks nothing.
     *
     * @return list<string>
     */
    public function maskValues(?string $serviceAccountDir = null): array
    {
        if ($this->inCluster) {
            $raw = @file_get_contents(($serviceAccountDir ?? '/var/run/secrets/kubernetes.io/serviceaccount') . '/token', false, null, 0, self::MAX_TOKEN_BYTES);
            $token = is_string($raw) ? trim($raw) : '';

            return $token === '' ? [] : [$token];
        }
        if ($this->token() !== null) {
            return [(string) $this->token()];
        }
        $real = $this->tokenFile() === null ? null : self::resolveTokenFile((string) $this->tokenFile());
        if ($real === null) {
            return [];
        }
        $raw = @file_get_contents($real, false, null, 0, self::MAX_TOKEN_BYTES);
        $token = is_string($raw) ? trim($raw) : '';

        return $token === '' ? [] : [$token];
    }

    /** Same cluster and same credentials: the key under which a fleet shares one client. */
    public function credentialKey(): string
    {
        return hash('sha256', implode("\0", [$this->inCluster ? 'in-cluster' : (string) $this->server, (string) $this->token(), (string) $this->tokenFile(), (string) $this->caFile]));
    }

    /** @return array{bool, ?string, ?string, ?string, ?string} in_cluster, server, token, token_file, ca_file */
    private static function api(mixed $api): array
    {
        if (!is_array($api)) {
            throw new InvalidArgumentException('kubernetes.api must be a map');
        }
        foreach (array_keys($api) as $key) {
            if (!in_array($key, ['in_cluster', 'server', 'token', 'token_file', 'ca_file', 'allow_insecure'], true)) {
                throw new InvalidArgumentException('Unknown configuration key: kubernetes.api.' . $key);
            }
        }
        $server = $api['server'] ?? null;
        if ($server === null) {
            if (($api['in_cluster'] ?? true) !== true || isset($api['token']) || isset($api['token_file']) || isset($api['ca_file'])) {
                throw new InvalidArgumentException('kubernetes.api.server is required when in_cluster is not used');
            }

            return [true, null, null, null, null];
        }
        if (isset($api['in_cluster']) && $api['in_cluster'] === true) {
            throw new InvalidArgumentException('kubernetes.api: use either in_cluster or server, not both');
        }
        $allowInsecure = $api['allow_insecure'] ?? false;
        if (!is_bool($allowInsecure)) {
            throw new InvalidArgumentException('kubernetes.api.allow_insecure must be true or false');
        }
        // The same rule as every other credentialed URL (SecureUrl): https; http only for a loopback, private or cluster-local
        // host AND only with allow_insecure. A loopback API server (kubectl proxy) is a trust decision too, so it needs the flag.
        $parts = is_string($server) ? parse_url($server) : false;
        if ($parts === false || ($parts['host'] ?? '') === '' || isset($parts['query']) || isset($parts['fragment']) || SecureUrl::problem((string) $server, $allowInsecure) !== null) {
            throw new InvalidArgumentException('kubernetes.api.server must be an https URL without credentials (http only for a loopback, private-network or cluster-local host with kubernetes.api.allow_insecure: true)');
        }
        $host = trim(strtolower((string) $parts['host']), '[]');
        if (str_starts_with($host, '169.254.') || $host === 'fd00:ec2::254') {
            throw new InvalidArgumentException('kubernetes.api.server must be the cluster API server, not a metadata address');
        }
        if (!$allowInsecure && ($host === 'localhost' || $host === '::1' || str_starts_with($host, '127.'))) {
            throw new InvalidArgumentException('kubernetes.api.server must be the cluster API server, not a loopback address (unless kubernetes.api.allow_insecure: true)');
        }
        if (isset($api['token']) && isset($api['token_file'])) {
            throw new InvalidArgumentException('kubernetes.api: use either token or token_file, not both');
        }
        $tokenFile = null;
        if (isset($api['token_file'])) {
            $tokenFile = self::validatedTokenFile($api['token_file']);
        } elseif (!is_string($api['token'] ?? null) || $api['token'] === '') {
            throw new InvalidArgumentException('kubernetes.api.token (or token_file) is required with server');
        }

        return [false, rtrim((string) $server, '/'), $tokenFile === null ? (string) $api['token'] : null, $tokenFile, isset($api['ca_file']) ? self::caFile($api['ca_file']) : null];
    }

    /** Messages name the key and never the path: a path can say where the secrets live. */
    private static function validatedTokenFile(mixed $path): string
    {
        $fail = new InvalidArgumentException('kubernetes.api.token_file must be an absolute path to a readable regular file of at most 16 KB that is not group- or world-writable, owned by this user or root, and (when a symlink, as in a Secret or projected volume) resolving to a file inside its own directory');
        if (!is_string($path) || self::resolveTokenFile($path) === null) {
            throw $fail;
        }

        return $path;
    }

    /**
     * The one rule for a token file, used when the config loads and again when the token is read, so the two never disagree.
     * A symlink is fine in the standard Secret / projected-volume layout (`token -> ..data/token`, `..data -> ..<timestamp>`): the
     * REAL target must be a regular file below the directory of the configured path (no escape through `..` or an absolute link),
     * owned by this user or root, not group- or world-writable and at most 16 KB. Returns the real path, or null.
     */
    public static function resolveTokenFile(string $path): ?string
    {
        if (!str_starts_with($path, '/')) {
            return null;
        }
        // PHP caches stat() and realpath() results (the realpath cache for 120 s by default): a symlink swapped or a mode changed
        // after an earlier check would be judged on the old answer. Every check starts from the file system, not from the cache.
        clearstatcache(true);
        $dir = realpath(dirname($path));
        $real = realpath($path);
        if ($dir === false || $dir === '/' || $real === false || !str_starts_with($real, $dir . '/')) {
            return null;
        }
        // The directories the token lives in must not be changeable by anyone else (unless sticky, as /tmp is).
        foreach ([$dir, dirname($real)] as $d) {
            $ds = @stat($d);
            if ($ds === false || ($ds['mode'] & 0170000) !== 0040000 || (($ds['mode'] & 0022) !== 0 && ($ds['mode'] & 01000) === 0)) {
                return null;
            }
        }
        $st = @stat($real);
        if ($st === false || !self::safeTokenStat($st, self::effectiveUser())) {
            return null;
        }

        return $real;
    }

    /** The user watcher runs as, or null when ext-posix is missing: then only root-owned files are trusted (fail closed). */
    public static function effectiveUser(): ?int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    /** @param array<int|string, int> $st */
    public static function safeTokenStat(array $st, ?int $euid): bool
    {
        return ($st['mode'] & 0170000) === 0100000 && ($st['mode'] & 0022) === 0 && $st['size'] <= self::MAX_TOKEN_BYTES
            && ($st['uid'] === 0 || ($euid !== null && $st['uid'] === $euid));
    }

    private static function caFile(mixed $path): string
    {
        if (!is_string($path) || !self::certificateBundle($path)) {
            throw new InvalidArgumentException('kubernetes.api.ca_file must be an absolute path to a readable, non-empty PEM certificate bundle');
        }

        return $path;
    }

    /** True for an absolute path to a readable regular file that holds at least one PEM certificate. Also used at run time. */
    public static function certificateBundle(string $path): bool
    {
        if (!str_starts_with($path, '/') || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_CA_BYTES) {
            return false;
        }
        $pem = @file_get_contents($path);

        return is_string($pem) && str_contains($pem, '-----BEGIN CERTIFICATE-----');
    }

    /** @return list<string> */
    private static function namespaces(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v) || $v === []) {
            throw new InvalidArgumentException('kubernetes.namespaces must be a non-empty list of namespace names');
        }
        foreach ($v as $ns) {
            if (!is_string($ns) || preg_match('/^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/', $ns) !== 1) {
                throw new InvalidArgumentException('kubernetes.namespaces must be a non-empty list of namespace names');
            }
        }

        return array_values(array_unique($v));
    }

    /**
     * @param list<string>|null $closed
     * @return list<string>
     */
    private static function words(mixed $v, string $path, string $shape, ?array $closed = null): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new InvalidArgumentException($path . ' must be a list of strings');
        }
        foreach ($v as $w) {
            if (!is_string($w) || preg_match($shape, $w) !== 1 || ($closed !== null && !in_array($w, $closed, true))) {
                throw new InvalidArgumentException($path . ' has an entry that is not allowed');
            }
        }

        return array_values(array_unique($v));
    }

    /** @param array<string, mixed> $s */
    private static function int(array $s, string $key, int $default, int $min): int
    {
        $v = $s[$key] ?? $default;
        if (!is_int($v) || $v < $min) {
            throw new InvalidArgumentException('kubernetes.' . $key . ($min > 0 ? ' must be a positive integer' : ' must be a whole number of seconds, zero or more'));
        }

        return $v;
    }

    /** @param array<string, mixed> $s */
    private static function bool(array $s, string $key, string $path): bool
    {
        $v = $s[$key] ?? false;
        if (!is_bool($v)) {
            throw new InvalidArgumentException($path . ' must be true or false');
        }

        return $v;
    }
}
