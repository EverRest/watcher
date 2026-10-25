<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use Closure;
use InvalidArgumentException;
use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;
use Watcher\Source\Slug;

/**
 * The validated `metrics` section (spec 009, contracts/config.md). Errors name the key path only. The bearer token leaves the
 * array first and lives in a closure, so no dump of this object (print_r, var_dump, var_export, serialize, json_encode) holds it.
 */
final class MetricsSettings implements \JsonSerializable
{
    private const KEYS = ['enabled', 'source', 'label_allowlist', 'max_anomalies', 'max_points', 'vars', 'queries'];
    private const SOURCE_KEYS = ['type', 'base_url', 'datasource_uid', 'auth', 'allow_insecure', 'allow_private_network'];
    private const QUERY_KEYS = ['query', 'aggregate', 'unit', 'mode', 'threshold'];

    /** @var Closure(): ?string */
    private readonly Closure $token;

    /**
     * @param list<string>           $labelAllowlist
     * @param list<MetricDefinition> $definitions
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $type,
        public readonly string $baseUrl,
        public readonly ?string $datasourceUid,
        public readonly bool $allowInsecure,
        public readonly bool $allowPrivateNetwork,
        public readonly array $labelAllowlist,
        public readonly int $maxAnomalies,
        public readonly int $maxPoints,
        public readonly array $definitions,
        ?string $token,
    ) {
        $this->token = static fn (): ?string => $token;
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(#[\SensitiveParameter] array $raw, string $service): self
    {
        $source = $raw['source'] ?? [];
        if (!is_array($source)) {
            throw new InvalidConfig('metrics.source must be a map');
        }
        // The credential leaves the array first: no helper below receives it, so a throw cannot put it in a trace.
        $auth = $source['auth'] ?? null;
        unset($source['auth'], $raw['source']);
        $token = null;
        if ($auth !== null) {
            if (!is_array($auth) || ($auth['type'] ?? null) !== 'bearer' || !is_string($auth['token'] ?? null) || $auth['token'] === '') {
                throw new InvalidConfig('metrics.source.auth must be {type: bearer, token: <non-empty string>}');
            }
            $token = $auth['token'];
        }
        unset($auth);
        self::only($raw, self::KEYS, 'metrics');
        self::only($source, self::SOURCE_KEYS, 'metrics.source');

        $enabled = self::bool($raw, 'enabled', 'metrics.enabled', true);
        $type = $source['type'] ?? 'prometheus';
        if (!in_array($type, ['prometheus', 'grafana'], true)) {
            throw new InvalidConfig('metrics.source.type must be prometheus or grafana');
        }
        $allowInsecure = self::bool($source, 'allow_insecure', 'metrics.source.allow_insecure', false);
        $allowPrivate = self::bool($source, 'allow_private_network', 'metrics.source.allow_private_network', false);
        $url = $source['base_url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new InvalidConfig('metrics.source.base_url is required');
        }
        if (($problem = SecureUrl::problem($url, $allowInsecure, true, $allowPrivate)) !== null) {
            throw new InvalidConfig('metrics.source.base_url ' . $problem);
        }
        $uid = $source['datasource_uid'] ?? null;
        if ($type === 'grafana' && $uid === null) {
            throw new InvalidConfig('metrics.source.datasource_uid is required for type grafana');
        }
        if ($uid !== null && (!is_string($uid) || !Slug::valid($uid))) {
            throw new InvalidConfig('metrics.source.datasource_uid must be letters, digits, _ . - and not only dots');
        }

        $labels = $raw['label_allowlist'] ?? [];
        if (!is_array($labels) || !array_is_list($labels) || array_filter($labels, static fn (mixed $l): bool => !is_string($l) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $l) !== 1) !== []) {
            throw new InvalidConfig('metrics.label_allowlist must be a list of label names');
        }
        $maxAnomalies = self::int($raw, 'max_anomalies', 3, 0, 10, 'metrics.max_anomalies');
        $maxPoints = self::int($raw, 'max_points', 500, 10, 11000, 'metrics.max_points');
        $vars = $raw['vars'] ?? [];
        if (!is_array($vars)) {
            throw new InvalidConfig('metrics.vars must be a map');
        }
        foreach ($vars as $k => $v) {
            if (!is_string($k) || !is_string($v) || preg_match('/^[a-z][a-z0-9_]*$/D', $k) !== 1) {
                throw new InvalidConfig('metrics.vars must map placeholder names to string values');
            }
        }

        return new self($enabled, $type, $url, $uid, $allowInsecure, $allowPrivate, $labels, $maxAnomalies, $maxPoints, self::definitions($raw['queries'] ?? null, $service, $vars), $token);
    }

    public function token(): ?string
    {
        return ($this->token)();
    }

    /** @return list<string> the credential, masked everywhere by WatchConfig::secrets() */
    public function secrets(): array
    {
        $t = $this->token();

        return $t === null ? [] : [$t];
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

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('MetricsSettings is not serialisable.');
    }

    /**
     * @param array<string, string> $vars
     *
     * @return list<MetricDefinition>
     */
    private static function definitions(mixed $queries, string $service, array $vars): array
    {
        if (!is_array($queries) || $queries === []) {
            throw new InvalidConfig('metrics.queries must declare at least one metric');
        }
        if (count($queries) > 6) {
            throw new InvalidConfig('metrics.queries may declare at most 6 metrics');
        }
        $out = [];
        foreach ($queries as $name => $q) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $name) !== 1) {
                throw new InvalidConfig('metrics.queries has a metric name that is not ^[a-z][a-z0-9_]{0,39}$');
            }
            $path = 'metrics.queries.' . $name;
            if (!is_array($q) || !is_string($q['query'] ?? null) || $q['query'] === '') {
                throw new InvalidConfig($path . '.query is required');
            }
            self::only($q, self::QUERY_KEYS, $path);
            $t = $q['threshold'] ?? null;
            if ($t !== null && (!is_array($t) || !is_numeric($t['relative'] ?? null) || !is_numeric($t['floor'] ?? null) || array_diff(array_keys($t), ['relative', 'floor']) !== [])) {
                throw new InvalidConfig($path . '.threshold must be {relative: number, floor: number}');
            }
            try {
                $out[] = new MetricDefinition(
                    $name,
                    QueryTemplate::compile($name, $q['query'], $service, $vars),
                    $q['aggregate'] ?? null,
                    $t === null ? null : ['relative' => (float) $t['relative'], 'floor' => (float) $t['floor']],
                    $q['unit'] ?? '',
                    $q['mode'] ?? 'instant',
                );
            } catch (InvalidConfig $e) {
                throw $e; // QueryTemplate names the key path itself
            } catch (InvalidArgumentException) { // the message names the metric and the rule, never the query
                throw new InvalidConfig($path . ' has an invalid aggregate (sum|max|avg|last), unit, mode (instant|range) or threshold');
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $section
     * @param list<string>            $allowed
     */
    private static function only(array $section, array $allowed, string $path): void
    {
        foreach (array_keys($section) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new InvalidConfig('Unknown configuration key: ' . $path . '.' . $key);
            }
        }
    }

    /** @param array<array-key, mixed> $from */
    private static function bool(array $from, string $key, string $path, bool $default): bool
    {
        $v = $from[$key] ?? $default;
        if (!is_bool($v)) {
            throw new InvalidConfig($path . ' must be true or false');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    private static function int(array $from, string $key, int $default, int $min, int $max, string $path): int
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new InvalidConfig($path . " must be an integer between {$min} and {$max}");
        }

        return $v;
    }
}
