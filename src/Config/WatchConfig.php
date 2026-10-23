<?php

declare(strict_types=1);

namespace Watcher\Config;

use Watcher\Level;

/**
 * Validated, default-filled, immutable configuration (contracts/configuration.md).
 *
 * Sections are plain arrays with every default applied; levels are already parsed to Level.
 * Error messages carry key paths only, never values (secrets).
 */
final class WatchConfig
{
    private const TOP_LEVEL = [
        'service', 'schedule', 'source', 'watch_rules', 'mute', 'service_context', 'dependencies',
        'dependency_read_cap', 'dependency_domains', 'deploy', 'links', 'repo', 'selection',
        'analysis', 'redaction', 'state', 'notify',
    ];
    private const STEPS = ['triage', 'analyze', 'escalate'];

    /**
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $source
     * @param array<string, array<string, mixed>> $watchRules
     * @param list<array<string, mixed>> $mute
     * @param array<string, array<string, mixed>> $dependencies
     * @param list<string> $dependencyDomains
     * @param array<string, mixed> $deploy
     * @param array<string, mixed> $links
     * @param array<string, mixed>|null $repo
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $analysis
     * @param array<string, string> $redactionPatterns
     * @param array<string, mixed> $state
     * @param array<string, mixed> $notify
     */
    private function __construct(
        public readonly string $service,
        public readonly array $schedule,
        public readonly array $source,
        public readonly array $watchRules,
        public readonly array $mute,
        public readonly string $serviceContext,
        public readonly array $dependencies,
        public readonly int $dependencyReadCap,
        public readonly array $dependencyDomains,
        public readonly array $deploy,
        public readonly array $links,
        public readonly ?array $repo,
        public readonly array $selection,
        public readonly array $analysis,
        public readonly array $redactionPatterns,
        public readonly int $minGroupEventsForText,
        public readonly array $state,
        public readonly array $notify,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::TOP_LEVEL, true)) {
                throw new InvalidConfig('Unknown configuration key: ' . $key);
            }
        }

        $service = self::str($config, 'service', 'service');

        $sch = self::section($config, 'schedule');
        $runAt = self::str($sch, 'run_at', 'schedule.run_at');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $runAt) !== 1) {
            throw new InvalidConfig('schedule.run_at must be HH:MM');
        }
        $tz = self::optStr($sch, 'timezone', 'UTC', 'schedule.timezone');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidConfig('schedule.timezone must be an IANA timezone');
        }
        $window = self::optStr($sch, 'window', 'PT24H', 'schedule.window');
        try {
            $interval = new \DateInterval($window);
        } catch (\Exception) {
            throw new InvalidConfig('schedule.window must be an ISO-8601 duration');
        }
        if ($interval->y + $interval->m + $interval->d + $interval->h + $interval->i + $interval->s === 0) {
            throw new InvalidConfig('schedule.window must be longer than zero');
        }
        $schedule = [
            'run_at' => $runAt,
            'timezone' => $tz,
            'window' => $window,
            'lock_ttl' => self::posInt($sch, 'lock_ttl', 900, 'schedule.lock_ttl'),
        ];

        $source = self::source(self::section($config, 'source'));

        $selSec = self::section($config, 'selection');
        $selection = [
            'min_level' => self::level($selSec, 'min_level', 'error', 'selection.min_level'),
            'growth_factor' => self::num($selSec, 'growth_factor', 3.0, 'selection.growth_factor'),
            'top_n' => self::posInt($selSec, 'top_n', 50, 'selection.top_n'),
            'top_m' => self::posInt($selSec, 'top_m', 10, 'selection.top_m'),
        ];
        if ($selection['growth_factor'] <= 1) {
            throw new InvalidConfig('selection.growth_factor must be greater than 1');
        }
        if ($selection['top_m'] > $selection['top_n']) {
            throw new InvalidConfig('selection.top_m must not exceed selection.top_n');
        }

        $ctx = $config['service_context'] ?? '';
        if (!is_string($ctx) || mb_strlen($ctx) > 1000) {
            throw new InvalidConfig('service_context must be a string of at most 1000 characters');
        }

        $domains = $config['dependency_domains'] ?? ['svc.cluster.local'];
        if (!is_array($domains) || !array_is_list($domains) || array_filter($domains, 'is_string') !== $domains) {
            throw new InvalidConfig('dependency_domains must be a list of strings');
        }

        $redaction = self::section($config, 'redaction');
        $patterns = $redaction['extra_patterns'] ?? [];
        if (!is_array($patterns)) {
            throw new InvalidConfig('redaction.extra_patterns must be a map');
        }
        foreach ($patterns as $name => $re) {
            if (in_array(strtoupper((string) $name), \Watcher\Privacy\Redactor::RESERVED, true)) {
                throw new InvalidConfig("redaction.extra_patterns.$name is reserved for a built-in placeholder");
            }
            self::regex($re, 'redaction.extra_patterns.' . $name);
        }

        return new self(
            $service,
            $schedule,
            $source,
            self::watchRules($config['watch_rules'] ?? []),
            self::mute($config['mute'] ?? []),
            $ctx,
            self::dependencies($config['dependencies'] ?? []),
            self::posInt($config, 'dependency_read_cap', 5000, 'dependency_read_cap'),
            $domains,
            self::section($config, 'deploy'),
            self::section($config, 'links'),
            self::repo($config['repo'] ?? null),
            $selection,
            self::analysis(self::section($config, 'analysis')),
            $patterns,
            self::posInt($redaction, 'min_group_events_for_text', 3, 'redaction.min_group_events_for_text'),
            self::state(self::section($config, 'state')),
            self::notify(self::section($config, 'notify')),
        );
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function source(array $s): array
    {
        $driver = self::str($s, 'driver', 'source.driver');
        if ($driver === 'loki') {
            $loki = self::section($s, 'loki');
            self::str($loki, 'url', 'source.loki.url');
            $auth = $loki['auth'] ?? null;
            if ($auth !== null) {
                if (!is_array($auth) || !in_array($auth['type'] ?? null, ['bearer', 'basic'], true)) {
                    throw new InvalidConfig('source.loki.auth.type must be bearer or basic');
                }
                $need = $auth['type'] === 'bearer' ? ['token'] : ['user', 'password'];
                foreach ($need as $k) {
                    self::str($auth, $k, 'source.loki.auth.' . $k);
                }
            }
        } elseif ($driver === 'datadog') {
            $dd = self::section($s, 'datadog');
            self::str($dd, 'api_key', 'source.datadog.api_key');
            self::str($dd, 'app_key', 'source.datadog.app_key');
            if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/i', self::optStr($dd, 'site', 'datadoghq.com', 'source.datadog.site')) !== 1) {
                throw new InvalidConfig('source.datadog.site must be a Datadog site host such as datadoghq.eu');
            }
        } else {
            throw new InvalidConfig('source.driver must be loki or datadog');
        }

        $tags = $s['stream_tags'] ?? [];
        if (!is_array($tags)) {
            throw new InvalidConfig('source.stream_tags must be a map');
        }

        return array_merge($s, [
            'read_min_level' => self::level($s, 'read_min_level', 'warning', 'source.read_min_level'),
            'anomaly_cap' => self::posInt($s, 'anomaly_cap', 20000, 'source.anomaly_cap'),
            'sample_size' => self::posInt($s, 'sample_size', 3, 'source.sample_size'),
            'event_cap' => self::posInt($s, 'event_cap', 200000, 'source.event_cap'),
            'stream_tags' => $tags,
        ]);
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function analysis(array $a): array
    {
        self::str($a, 'api_key', 'analysis.api_key');
        $models = self::section($a, 'models');
        foreach (self::STEPS as $step) {
            self::str($models, $step, 'analysis.models.' . $step);
        }
        $conf = self::num($a, 'confidence_threshold', 0.6, 'analysis.confidence_threshold');
        if ($conf <= 0 || $conf >= 1) {
            throw new InvalidConfig('analysis.confidence_threshold must be between 0 and 1 exclusive');
        }
        $limits = [];
        foreach (['max_input_tokens' => [20000, 30000, 15000], 'max_output_tokens' => [1500, 4000, 2000]] as $key => $defaults) {
            $given = self::section($a, $key);
            foreach (self::STEPS as $i => $step) {
                $limits[$key][$step] = self::posInt($given, $step, $defaults[$i], 'analysis.' . $key . '.' . $step);
            }
        }

        $prices = self::prices(self::section($a, 'prices'), $models);
        $percents = $a['budget_alert_percents'] ?? [25, 50, 75, 90];
        $tokenLimit = $a['daily_token_limit'] ?? null;
        if ($tokenLimit !== null && (!is_int($tokenLimit) || $tokenLimit < 1)) {
            throw new InvalidConfig('analysis.daily_token_limit must be null or a positive integer');
        }
        if (!is_array($percents) || !array_is_list($percents) || $percents === [] || !self::ascendingPercents($percents)) {
            throw new InvalidConfig('analysis.budget_alert_percents must be ascending integers from 1 to 99');
        }

        return array_merge($a, $limits, [
            'provider' => self::optStr($a, 'provider', 'anthropic', 'analysis.provider'),
            'models' => $models,
            'confidence_threshold' => $conf,
            'escalation_cap' => self::posInt($a, 'escalation_cap', 3, 'analysis.escalation_cap'),
            'daily_budget_usd' => self::num($a, 'daily_budget_usd', 0.50, 'analysis.daily_budget_usd'),
            'prices' => $prices,
            'daily_token_limit' => $tokenLimit,
            'budget_alert_percents' => $percents,
        ]);
    }

    /**
     * @param array<string, mixed> $prices
     * @param array<string, mixed> $models
     * @return array<string, array{float, float, float, float}>
     */
    private static function prices(array $prices, array $models): array
    {
        foreach (self::STEPS as $step) {
            if (!isset($prices[$models[$step]])) {
                throw new InvalidConfig('analysis.prices (no price for models.' . $step . ')');
            }
        }
        $out = [];
        foreach ($prices as $model => $p) {
            $path = 'analysis.prices.' . $model;
            if (!is_array($p) || !array_is_list($p) || count($p) !== 4) {
                throw new InvalidConfig($path . ' must be 4 non-negative numbers: input, output, cache_read, cache_write');
            }
            foreach ($p as $n) {
                if ((!is_int($n) && !is_float($n)) || $n < 0) {
                    throw new InvalidConfig($path . ' must be 4 non-negative numbers: input, output, cache_read, cache_write');
                }
            }
            $out[(string) $model] = [(float) $p[0], (float) $p[1], (float) $p[2], (float) $p[3]];
        }

        return $out;
    }

    /** @param array<mixed> $percents */
    private static function ascendingPercents(array $percents): bool
    {
        $last = 0;
        foreach ($percents as $p) {
            if (!is_int($p) || $p <= $last || $p > 99) {
                return false;
            }
            $last = $p;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function state(array $s): array
    {
        $driver = self::str($s, 'driver', 'state.driver');
        match ($driver) {
            'redis' => self::redis(self::section($s, 'redis')),
            'pdo' => self::pdo(self::section($s, 'pdo')),
            'file' => self::str(self::section($s, 'file'), 'path', 'state.file.path'),
            default => throw new InvalidConfig('state.driver must be redis, pdo or file'),
        };

        return array_merge($s, ['retention' => self::posInt($s, 'retention', 2592000, 'state.retention')]);
    }

    /** @param array<string, mixed> $r an injected `instance` wins; otherwise host/port/password/database build one */
    private static function redis(array $r): void
    {
        if (array_key_exists('instance', $r)) {
            if (!$r['instance'] instanceof \Redis) {
                throw new InvalidConfig('state.redis.instance must be a \\Redis instance');
            }

            return;
        }
        self::optStr($r, 'host', '127.0.0.1', 'state.redis.host');
        $port = $r['port'] ?? 6379;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidConfig('state.redis.port must be an integer from 1 to 65535');
        }
        $db = $r['database'] ?? 0;
        if (!is_int($db) || $db < 0) {
            throw new InvalidConfig('state.redis.database must be a non-negative integer');
        }
        if (isset($r['password']) && !is_string($r['password'])) {
            throw new InvalidConfig('state.redis.password must be a string');
        }
    }

    /** @param array<string, mixed> $p */
    private static function pdo(array $p): void
    {
        self::str($p, 'dsn', 'state.pdo.dsn');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', self::optStr($p, 'table', 'watcher_state', 'state.pdo.table')) !== 1) {
            throw new InvalidConfig('state.pdo.table must be a plain identifier');
        }
        foreach (['username', 'password'] as $k) {
            if (isset($p[$k]) && !is_string($p[$k])) {
                throw new InvalidConfig('state.pdo.' . $k . ' must be a string');
            }
        }
    }

    /**
     * @param array<string, mixed> $n
     * @return array<string, mixed>
     */
    private static function notify(array $n): array
    {
        $digest = self::destination($n['digest'] ?? null, 'notify.digest');
        $notice = ($n['notice'] ?? null) === null ? $digest : self::destination($n['notice'], 'notify.notice');

        return ['digest' => $digest, 'notice' => $notice];
    }

    /** @return array<string, mixed> */
    private static function destination(mixed $d, string $path): array
    {
        if (!is_array($d)) {
            throw new InvalidConfig($path . '.webhook is required');
        }
        $driver = self::optStr($d, 'driver', 'slack', $path . '.driver');
        if ($driver !== 'slack') {
            throw new InvalidConfig($path . '.driver must be slack');
        }
        self::str($d, 'webhook', $path . '.webhook');

        return array_merge($d, ['driver' => $driver]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function watchRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            throw new InvalidConfig('watch_rules must be a map');
        }
        foreach ($rules as $name => $rule) {
            self::matcher($rule, 'watch_rules.' . $name, false);
        }

        return $rules;
    }

    /** @return list<array<string, mixed>> */
    private static function mute(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InvalidConfig('mute must be a list');
        }
        foreach ($entries as $i => $entry) {
            $path = 'mute.' . $i;
            self::matcher($entry, $path, true);
            $until = $entry['until'] ?? null;
            if ($until !== null && (!is_string($until) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1 || !checkdate((int) substr($until, 5, 2), (int) substr($until, 8, 2), (int) substr($until, 0, 4)))) {
                throw new InvalidConfig($path . '.until must be a YYYY-MM-DD date or null');
            }
        }

        return $entries;
    }

    /** Exactly one of template | error_type | field (mute also: fingerprint). */
    private static function matcher(mixed $m, string $path, bool $allowFingerprint): void
    {
        if (!is_array($m)) {
            throw new InvalidConfig($path . ' must be a map');
        }
        $kinds = array_values(array_intersect(
            $allowFingerprint ? ['fingerprint', 'template', 'error_type', 'field'] : ['template', 'error_type', 'field'],
            array_keys($m),
        ));
        if (count($kinds) !== 1) {
            throw new InvalidConfig($path . ' needs exactly one of template, error_type, field' . ($allowFingerprint ? ', fingerprint' : ''));
        }
        $kind = $kinds[0];
        if ($kind === 'template' || $kind === 'error_type') {
            self::regex($m[$kind], $path . '.' . $kind);
        } elseif ($kind === 'fingerprint') {
            if (!is_string($m['fingerprint']) || $m['fingerprint'] === '') {
                throw new InvalidConfig($path . '.fingerprint must be a non-empty string');
            }
        } else {
            if (!is_string($m['field']) || $m['field'] === '') {
                throw new InvalidConfig($path . '.field must be a non-empty string');
            }
            if (isset($m['equals']) === isset($m['matches'])) {
                throw new InvalidConfig($path . ' field needs exactly one of equals, matches');
            }
            if (isset($m['matches'])) {
                self::regex($m['matches'], $path . '.matches');
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private static function dependencies(mixed $deps): array
    {
        if (!is_array($deps)) {
            throw new InvalidConfig('dependencies must be a map');
        }
        foreach ($deps as $name => $dep) {
            if (!is_array($dep) || !is_array($dep['stream_tags'] ?? null)) {
                throw new InvalidConfig('dependencies.' . $name . '.stream_tags must be a map');
            }
        }

        return $deps;
    }

    /** @return array<string, mixed>|null */
    private static function repo(mixed $repo): ?array
    {
        if ($repo === null) {
            return null;
        }
        if (!is_array($repo) || !in_array($repo['provider'] ?? null, ['gitlab', 'github'], true)) {
            throw new InvalidConfig('repo.provider must be gitlab or github');
        }
        self::str($repo, 'web_url', 'repo.web_url');

        return $repo;
    }

    private static function regex(mixed $re, string $path): void
    {
        if (!is_string($re) || $re === '' || @preg_match($re, '') === false) {
            throw new InvalidConfig($path . ' must be a valid regular expression');
        }
    }

    /**
     * @param array<array-key, mixed> $from
     * @return array<string, mixed>
     */
    private static function section(array $from, string $key): array
    {
        $v = $from[$key] ?? [];
        if (!is_array($v)) {
            throw new InvalidConfig($key . ' must be a map');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    private static function str(array $from, string $key, string $path): string
    {
        $v = $from[$key] ?? null;
        if (!is_string($v) || $v === '') {
            throw new InvalidConfig($path . ' is required');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    private static function optStr(array $from, string $key, string $default, string $path): string
    {
        $v = $from[$key] ?? $default;
        if (!is_string($v) || $v === '') {
            throw new InvalidConfig($path . ' must be a non-empty string');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    private static function posInt(array $from, string $key, int $default, string $path): int
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) || $v < 1) {
            throw new InvalidConfig($path . ' must be a positive integer');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    private static function num(array $from, string $key, float $default, string $path): float
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) && !is_float($v)) {
            throw new InvalidConfig($path . ' must be a number');
        }

        return (float) $v;
    }

    /** @param array<array-key, mixed> $from */
    private static function level(array $from, string $key, string $default, string $path): Level
    {
        $v = $from[$key] ?? $default;
        $level = is_string($v) ? Level::parse($v) : null;
        // Level::parse maps unknown names to Info; a typo in config must not pass silently.
        if ($level === null || ($level === Level::Info && strtolower(trim((string) $v)) !== 'info')) {
            throw new InvalidConfig($path . ' must be a level name');
        }

        return $level;
    }
}
