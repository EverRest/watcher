<?php

declare(strict_types=1);

namespace Watcher\Config;

use Watcher\Notify\Destination;
use Watcher\Analyze\SdkMessagesClient;
use Watcher\Level;
use Watcher\State\Redis\RedisClient;
use Watcher\Source\Kubernetes\KubeSettings;

/**
 * Validated, default-filled, immutable configuration (contracts/configuration.md).
 *
 * Sections are plain arrays with every default applied; levels are already parsed to Level.
 * Error messages carry key paths only, never values (secrets).
 */
final class WatchConfig implements \JsonSerializable
{
    private const TOP_LEVEL = [
        'service', 'schedule', 'source', 'watch_rules', 'mute', 'service_context', 'dependencies',
        'dependency_read_cap', 'dependency_domains', 'deploy', 'links', 'repo', 'selection',
        'analysis', 'redaction', 'state', 'notify', 'heartbeat', 'baseline', 'grouping',
        'enabled', 'environment', 'purge', 'level_anomaly', 'budget_alerts', 'kubernetes', 'events', 'hygiene',
    ];

    /** Every on/off feature flag (spec 012). All default to true; none can touch redaction, the normalizer or the allowlist. */
    public const FLAGS = [
        'heartbeat', 'purge', 'dependencies', 'deploy', 'links.logs', 'links.code', 'service_context', 'watch_rules', 'mute',
        'level_anomaly', 'escalation', 'triage', 'advice', 'baseline', 'grouping', 'budget_alerts', 'notice', 'digest', 'kubernetes',
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
     * @param array{stale_after: int, weekly_summary: bool} $heartbeat stale_after in seconds
     * @param array{mode: string, windows: int, max_age: string, max_age_seconds: int, exclude: list<string>, since: ?string} $baseline
     * @param array{similarity: float, message_cap: int, backend_patterns: bool, memory_budget_mb: int} $grouping
     * @param array<string, bool> $flags see {@see self::FLAGS}
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
        public readonly array $heartbeat,
        public readonly array $baseline,
        public readonly array $grouping,
        public readonly bool $enabled,
        public readonly ?string $environment,
        public readonly string $stateKey,
        private readonly array $flags,
        public readonly ?KubeSettings $kubernetes = null,
        public readonly \Watcher\Hygiene\HygieneSettings $hygiene = new \Watcher\Hygiene\HygieneSettings(),
    ) {
    }

    /**
     * What a debug dump or an error page shows: the sections that hold credentials are replaced.
     * The real values stay readable on the public properties for the code that needs them.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $out = get_object_vars($this);
        foreach (['source', 'analysis', 'notify', 'state', 'serviceContext', 'flags'] as $k) {
            $out[$k] = '[redacted]';
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** Whether a feature flag is on. */
    public function on(string $flag): bool
    {
        return $this->flags[$flag] ?? throw new \LogicException('unknown feature flag ' . $flag);
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::TOP_LEVEL, true)) {
                throw new InvalidConfig('Unknown configuration key: ' . $key);
            }
        }

        $service = self::str($config, 'service', 'service');
        if (str_contains($service, '@')) {
            throw new InvalidConfig('service must not contain @ (it joins service and environment in state keys)');
        }
        $enabled = self::flag($config, 'enabled', 'enabled', false);
        $environment = $config['environment'] ?? null;
        if ($environment !== null && (!is_string($environment) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $environment) !== 1)) {
            throw new InvalidConfig('environment must be a name of letters, digits, dot, dash and underscore');
        }
        $flags = [];

        $sch = self::section($config, 'schedule');
        self::only($sch, ['run_at', 'timezone', 'window', 'lock_ttl', 'run_deadline'], 'schedule');
        $runAt = self::str($sch, 'run_at', 'schedule.run_at');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $runAt) !== 1) {
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
        $lockTtl = self::posInt($sch, 'lock_ttl', 900, 'schedule.lock_ttl');
        // A4-01: total wall-clock budget of a run. Default: the lock lifetime minus a margin for the post-read steps, never above it.
        $deadline = self::posInt($sch, 'run_deadline', $lockTtl <= 60 ? max(1, intdiv($lockTtl, 2)) : $lockTtl - max(30, intdiv($lockTtl, 10)), 'schedule.run_deadline');
        if ($deadline > $lockTtl) {
            throw new InvalidConfig('schedule.run_deadline must not be longer than schedule.lock_ttl');
        }
        $schedule = [
            'run_at' => $runAt,
            'timezone' => $tz,
            'window' => $window,
            'lock_ttl' => $lockTtl,
            'run_deadline' => $deadline,
        ];

        $source = self::source(self::section($config, 'source'));

        [$kubernetes, $flags['kubernetes']] = self::kubernetes($config, $source['driver']);

        $selSec = self::section($config, 'selection');
        self::only($selSec, ['min_level', 'growth_factor', 'top_n', 'top_m', 'escalation'], 'selection');
        $flags['escalation'] = self::subFlag($selSec, 'escalation', 'selection.escalation');
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
        $flags['service_context'] = true;
        if (is_array($ctx)) {
            self::only($ctx, ['enabled', 'text'], 'service_context');
            $flags['service_context'] = self::flag($ctx, 'enabled', 'service_context.enabled');
            $ctx = $ctx['text'] ?? '';
        }
        if (!is_string($ctx) || mb_strlen($ctx) > 1000) {
            throw new InvalidConfig('service_context must be a string of at most 1000 characters');
        }

        $domains = $config['dependency_domains'] ?? ['svc.cluster.local'];
        if (!is_array($domains) || !array_is_list($domains) || array_filter($domains, 'is_string') !== $domains) {
            throw new InvalidConfig('dependency_domains must be a list of strings');
        }

        $redaction = self::section($config, 'redaction');
        self::only($redaction, ['extra_patterns', 'min_group_events_for_text'], 'redaction');
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

        $state = self::state(self::section($config, 'state'));
        $baselineSec = self::section($config, 'baseline');
        $flags['baseline'] = self::flag($baselineSec, 'enabled', 'baseline.enabled');
        unset($baselineSec['enabled']);
        $baseline = self::baseline($baselineSec, $interval, $state['retention']);
        $groupingSec = self::section($config, 'grouping');
        $flags['grouping'] = self::flag($groupingSec, 'enabled', 'grouping.enabled');
        unset($groupingSec['enabled']);
        foreach (['purge', 'level_anomaly', 'budget_alerts'] as $name) {
            $sec = self::section($config, $name);
            self::only($sec, ['enabled'], $name);
            $flags[$name] = self::flag($sec, 'enabled', $name . '.enabled');
        }
        [$watchRules, $flags['watch_rules']] = self::reserved($config['watch_rules'] ?? [], 'watch_rules');
        [$muteEntries, $flags['mute']] = self::reserved($config['mute'] ?? [], 'mute');
        $rules = self::watchRules($watchRules); // validated even when switched off, so a flag flip never reveals a bad rule
        $mute = self::mute($muteEntries);
        [$deps, $flags['dependencies']] = self::reserved($config['dependencies'] ?? [], 'dependencies');
        $deploySec = self::known(self::section($config, 'deploy'), ['release', 'commit', 'version_label', 'enabled'], 'deploy');
        $flags['deploy'] = self::flag($deploySec, 'enabled', 'deploy.enabled');
        $links = self::links(self::section($config, 'links'));
        $flags['links.logs'] = self::subFlag($links, 'logs', 'links.logs');
        $flags['links.code'] = self::subFlag($links, 'code', 'links.code');
        $analysis = self::analysis(self::section($config, 'analysis'));
        $flags['triage'] = self::subFlag($analysis, 'triage', 'analysis.triage');
        $flags['advice'] = self::subFlag($analysis, 'advice', 'analysis.advice');
        $heartbeat = self::heartbeat(self::section($config, 'heartbeat'), $interval);
        $flags['heartbeat'] = $heartbeat['enabled'];
        unset($heartbeat['enabled']);
        $notifySec = self::section($config, 'notify');
        $notify = self::notify($notifySec);
        foreach (['digest', 'notice'] as $kind) {
            $flags[$kind] = self::flag(is_array($notifySec[$kind] ?? null) ? $notifySec[$kind] : [], 'enabled', 'notify.' . $kind . '.enabled');
        }

        return new self(
            $service,
            $schedule,
            $source,
            $flags['watch_rules'] ? $rules : [],
            $flags['mute'] ? $mute : [],
            $ctx,
            self::dependencies($deps),
            self::posInt($config, 'dependency_read_cap', 5000, 'dependency_read_cap'),
            $domains,
            $deploySec,
            $links,
            self::repo($config['repo'] ?? null),
            $selection,
            $analysis,
            $patterns,
            self::posInt($redaction, 'min_group_events_for_text', 3, 'redaction.min_group_events_for_text'),
            $state,
            $notify,
            $heartbeat,
            $baseline,
            self::grouping($groupingSec),
            $enabled,
            $environment,
            $environment === null ? $service : $service . '@' . $environment,
            $flags,
            $kubernetes,
            \Watcher\Hygiene\HygieneSettings::fromArray(self::section($config, 'hygiene')),
        );
    }

    /**
     * @param array<string, mixed> $b
     * @return array{mode: string, windows: int, max_age: string, max_age_seconds: int, exclude: list<string>, since: ?string}
     */
    private static function baseline(array $b, \DateInterval $window, int $retention): array
    {
        self::only($b, ['mode', 'windows', 'max_age', 'exclude', 'since'], 'baseline');
        $mode = $b['mode'] ?? 'previous';
        if (!in_array($mode, ['previous', 'rolling', 'weekday'], true)) {
            throw new InvalidConfig('baseline.mode must be one of previous, rolling, weekday');
        }
        $min = $mode === 'weekday' ? 3 : 2;
        $windows = $b['windows'] ?? ($mode === 'weekday' ? 4 : 7);
        if ($mode !== 'previous' && (!is_int($windows) || $windows < $min)) {
            throw new InvalidConfig('baseline.windows must be an integer >= ' . $min);
        }
        $maxAge = $b['max_age'] ?? 'P7D';
        try {
            $maxAgeSeconds = is_string($maxAge) ? (new \DateTimeImmutable('@0'))->add(new \DateInterval($maxAge))->getTimestamp() : 0;
        } catch (\Exception) {
            $maxAgeSeconds = 0;
        }
        if (!is_string($maxAge) || $maxAgeSeconds < 1) {
            throw new InvalidConfig('baseline.max_age must be an ISO-8601 duration');
        }
        if ($maxAgeSeconds > $retention) {
            throw new InvalidConfig('baseline.max_age exceeds state.retention');
        }
        $exclude = $b['exclude'] ?? [];
        if (!is_array($exclude) || !array_is_list($exclude) || array_filter($exclude, self::isDate(...)) !== $exclude) {
            throw new InvalidConfig('baseline.exclude entries must be dates like 2026-12-25');
        }
        $since = $b['since'] ?? null;
        if ($since !== null && !self::isDate($since)) {
            throw new InvalidConfig('baseline.since must be a date like 2026-10-01');
        }
        if ($mode !== 'previous') {
            $wholeDays = $window->y === 0 && $window->m === 0 && $window->i === 0 && $window->s === 0 && $window->h % 24 === 0
                ? $window->d + intdiv($window->h, 24) : 0;
            if ($wholeDays < 1) {
                throw new InvalidConfig('baseline.mode rolling/weekday needs a whole-day schedule.window');
            }
            $needs = $windows * ($mode === 'weekday' ? 7 : $wholeDays) * 86400 + 86400;
            if ($retention < $needs) {
                throw new InvalidConfig(sprintf('state.retention (%d s) is shorter than baseline.windows needs (%d s)', $retention, $needs));
            }
        }

        return [
            'mode' => $mode,
            'windows' => is_int($windows) ? $windows : 0,
            'max_age' => $maxAge,
            'max_age_seconds' => $maxAgeSeconds,
            'exclude' => $exclude,
            'since' => $since,
        ];
    }

    private static function isDate(mixed $v): bool
    {
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /**
     * @param array<string, mixed> $g
     * @return array{similarity: float, message_cap: int, backend_patterns: bool, memory_budget_mb: int}
     */
    private static function grouping(array $g): array
    {
        self::only($g, ['similarity', 'message_cap', 'backend_patterns', 'memory_budget_mb'], 'grouping');
        $similarity = $g['similarity'] ?? 0.5;
        if ((!is_int($similarity) && !is_float($similarity)) || $similarity <= 0 || $similarity > 1) {
            throw new InvalidConfig('grouping.similarity must be greater than 0 and at most 1');
        }
        $cap = $g['message_cap'] ?? 50000;
        if (!is_int($cap) || $cap < 1000) {
            throw new InvalidConfig('grouping.message_cap must be an integer >= 1000');
        }
        if (($g['backend_patterns'] ?? false) !== false) {
            throw new InvalidConfig('grouping.backend_patterns is not available yet');
        }

        $memory = $g['memory_budget_mb'] ?? 32;
        if (!is_int($memory) || $memory < 1) {
            throw new InvalidConfig('grouping.memory_budget_mb must be an integer >= 1');
        }

        return ['similarity' => (float) $similarity, 'message_cap' => $cap, 'backend_patterns' => false, 'memory_budget_mb' => $memory];
    }

    /**
     * @param array<string, mixed> $h
     * @return array{stale_after: int, weekly_summary: bool, enabled: bool}
     */
    private static function heartbeat(array $h, \DateInterval $window): array
    {
        self::only($h, ['stale_after', 'weekly_summary', 'enabled'], 'heartbeat');
        $on = self::flag($h, 'enabled', 'heartbeat.enabled');
        $weekly = $h['weekly_summary'] ?? false;
        if (!is_bool($weekly)) {
            throw new InvalidConfig('heartbeat.weekly_summary must be true or false');
        }
        $epoch = new \DateTimeImmutable('@0');
        if (array_key_exists('stale_after', $h)) {
            $given = $h['stale_after'];
            try {
                $seconds = is_string($given) ? $epoch->add(new \DateInterval($given))->getTimestamp() : 0;
            } catch (\Exception) {
                $seconds = 0;
            }
            if ($seconds < 1) {
                throw new InvalidConfig('heartbeat.stale_after must be an ISO-8601 duration longer than zero');
            }
        } else {
            $seconds = (int) ceil(1.5 * $epoch->add($window)->getTimestamp());
        }

        return ['stale_after' => $seconds, 'weekly_summary' => $weekly, 'enabled' => $on];
    }

    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function source(array $s): array
    {
        self::only($s, ['driver', 'loki', 'datadog', 'stream_tags', 'read_min_level', 'anomaly_cap', 'group_cap', 'event_cap', 'level_mode', 'max_response_mb'], 'source');
        $driver = self::str($s, 'driver', 'source.driver');
        if ($driver === 'loki') {
            $loki = self::section($s, 'loki');
            self::only($loki, ['url', 'auth', 'tenant', 'allow_insecure'], 'source.loki');
            $url = self::str($loki, 'url', 'source.loki.url');
            if (isset($loki['allow_insecure']) && !is_bool($loki['allow_insecure'])) {
                throw new InvalidConfig('source.loki.allow_insecure must be true or false');
            }
            if (($problem = SecureUrl::problem($url, ($loki['allow_insecure'] ?? false) === true)) !== null) {
                throw new InvalidConfig('source.loki.url ' . $problem);
            }
            $auth = $loki['auth'] ?? null;
            if ($auth !== null) {
                if (!is_array($auth) || !in_array($auth['type'] ?? null, ['bearer', 'basic'], true)) {
                    throw new InvalidConfig('source.loki.auth.type must be bearer or basic');
                }
                self::only($auth, ['type', 'token', 'user', 'password'], 'source.loki.auth');
                $need = $auth['type'] === 'bearer' ? ['token'] : ['user', 'password'];
                foreach ($need as $k) {
                    self::str($auth, $k, 'source.loki.auth.' . $k);
                }
            }
        } elseif ($driver === 'datadog') {
            $dd = self::section($s, 'datadog');
            self::only($dd, ['site', 'api_key', 'app_key', 'newest_first'], 'source.datadog');
            if (isset($dd['newest_first']) && !is_bool($dd['newest_first'])) {
                throw new InvalidConfig('source.datadog.newest_first must be true or false');
            }
            self::str($dd, 'api_key', 'source.datadog.api_key');
            self::str($dd, 'app_key', 'source.datadog.app_key');
            if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/iD', self::optStr($dd, 'site', 'datadoghq.com', 'source.datadog.site')) !== 1) {
                throw new InvalidConfig('source.datadog.site must be a Datadog site host such as datadoghq.eu');
            }
        } elseif ($driver !== 'kubernetes') { // its settings live in the top-level `kubernetes` section (spec 008)
            throw new InvalidConfig('source.driver must be loki, datadog or kubernetes');
        }

        $tags = $s['stream_tags'] ?? [];
        if (!is_array($tags)) {
            throw new InvalidConfig('source.stream_tags must be a map');
        }

        return array_merge($s, [
            'read_min_level' => self::level($s, 'read_min_level', 'warning', 'source.read_min_level'),
            'anomaly_cap' => self::posInt($s, 'anomaly_cap', 20000, 'source.anomaly_cap'),
            'group_cap' => self::posInt($s, 'group_cap', 5000, 'source.group_cap'),
            'event_cap' => self::posInt($s, 'event_cap', 200000, 'source.event_cap'),
            'max_response_mb' => self::maxResponseMb($s),
            'level_mode' => self::levelMode($s),
            'stream_tags' => $tags,
        ]);
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function analysis(array $a): array
    {
        self::only($a, ['provider', 'client', 'api_key', 'models', 'prices', 'max_input_tokens', 'max_output_tokens', 'confidence_threshold', 'escalation_cap', 'daily_budget_usd', 'daily_token_limit', 'budget_alert_percents', 'triage', 'advice'], 'analysis');
        self::str($a, 'api_key', 'analysis.api_key');
        $models = self::section($a, 'models');
        self::only($models, self::STEPS, 'analysis.models');
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
            self::only($given, self::STEPS, 'analysis.' . $key);
            foreach (self::STEPS as $i => $step) {
                $limits[$key][$step] = self::posInt($given, $step, $defaults[$i], 'analysis.' . $key . '.' . $step);
            }
        }

        $client = self::optStr($a, 'client', 'http', 'analysis.client');
        if (!in_array($client, ['http', 'sdk'], true)) {
            throw new InvalidConfig('analysis.client must be http or sdk');
        }
        if ($client === 'sdk' && !SdkMessagesClient::available()) {
            throw new InvalidConfig('analysis.client is sdk but anthropic-ai/sdk is not installed: run composer require anthropic-ai/sdk, or set analysis.client to http');
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
            'client' => $client,
            'models' => $models,
            'confidence_threshold' => $conf,
            'escalation_cap' => self::posInt($a, 'escalation_cap', 3, 'analysis.escalation_cap'),
            'daily_budget_usd' => self::num($a, 'daily_budget_usd', 0.50, 'analysis.daily_budget_usd', 0.0),
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
        self::only($s, ['driver', 'retention', 'redis', 'pdo', 'file'], 'state');
        $driver = self::str($s, 'driver', 'state.driver');
        match ($driver) {
            'redis' => self::redis(self::section($s, 'redis')),
            'pdo' => self::pdo(self::section($s, 'pdo')),
            'file' => self::str(self::known(self::section($s, 'file'), ['path'], 'state.file'), 'path', 'state.file.path'),
            default => throw new InvalidConfig('state.driver must be redis, pdo or file'),
        };

        return array_merge($s, ['retention' => self::posInt($s, 'retention', 2592000, 'state.retention')]);
    }

    /** @param array<string, mixed> $r an injected `instance` wins; otherwise host/port/password/database build one */
    private static function redis(array $r): void
    {
        self::only($r, ['instance', 'host', 'port', 'username', 'password', 'database', 'client', 'compress_min_bytes', 'read_timeout', 'prefix'], 'state.redis');
        if (array_key_exists('prefix', $r) && (!is_string($r['prefix']) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $r['prefix']) !== 1)) {
            throw new InvalidConfig('state.redis.prefix must be 1 to 64 characters of letters, digits and _ . : -');
        }
        if (!in_array($r['client'] ?? 'auto', ['auto', 'phpredis', 'predis'], true)) {
            throw new InvalidConfig('state.redis.client must be auto, phpredis or predis');
        }
        $min = $r['compress_min_bytes'] ?? 512;
        if (!is_int($min) || $min < 0) {
            throw new InvalidConfig('state.redis.compress_min_bytes must be an integer of 0 or more (0 = never compress)');
        }
        if (isset($r['read_timeout']) && (!(is_int($r['read_timeout']) || is_float($r['read_timeout'])) || $r['read_timeout'] <= 0)) {
            throw new InvalidConfig('state.redis.read_timeout must be a positive number of seconds');
        }
        if (array_key_exists('instance', $r)) {
            $i = $r['instance'];
            if (!($i instanceof \Redis || $i instanceof RedisClient || is_a($i, 'Predis\\ClientInterface'))) {
                throw new InvalidConfig('state.redis.instance must be a \\Redis, a Predis client or a ' . RedisClient::class);
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
        if (isset($r['username']) && (!is_string($r['username']) || $r['username'] === '')) {
            throw new InvalidConfig('state.redis.username must be a non-empty string');
        }
    }

    /** @param array<string, mixed> $p */
    private static function pdo(array $p): void
    {
        self::only($p, ['dsn', 'username', 'password', 'table'], 'state.pdo');
        self::str($p, 'dsn', 'state.pdo.dsn');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', self::optStr($p, 'table', 'watcher_state', 'state.pdo.table')) !== 1) {
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
        self::only($n, ['digest', 'notice'], 'notify');
        $digest = self::notifyEntry(self::withoutEnabled($n['digest'] ?? null), 'notify.digest');
        $notice = $n['notice'] ?? null;
        $notice = self::withoutEnabled($notice);
        $notice = $notice === null || $notice === [] ? $digest : self::notifyEntry($notice, 'notify.notice');

        return ['digest' => $digest, 'notice' => $notice];
    }

    private static function withoutEnabled(mixed $entry): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }
        unset($entry['enabled']);

        return $entry;
    }

    /**
     * One validated `notify.<kind>` entry; every message names the key path, never a value. Public for the fleet.
     *
     * @return array<string, mixed>
     */
    public static function notifyEntry(mixed $d, string $path): array
    {
        $keys = ['slack' => ['webhook', 'allow_insecure'], 'telegram' => ['bot_token', 'chat_id', 'thread_id'], 'discord' => ['webhook']];
        if (!is_array($d)) {
            throw new InvalidConfig($path . '.webhook is required');
        }
        $driver = $d['driver'] ?? 'slack';
        if (!is_string($driver)) {
            throw new InvalidConfig($path . '.driver must be a string');
        }
        if (!isset($keys[$driver])) {
            throw new InvalidConfig($path . '.driver must be one of slack, telegram, discord');
        }
        foreach (array_keys($d) as $key) {
            if ($key === 'driver' || in_array($key, $keys[$driver], true)) {
                continue;
            }
            if (in_array($key, array_merge(...array_values($keys)), true)) {
                throw new InvalidConfig($path . '.' . $key . ' is not valid for driver ' . $driver);
            }
            throw new InvalidConfig('Unknown configuration key: ' . $path . '.' . $key);
        }
        if ($driver !== 'telegram') {
            $out = ['driver' => $driver, 'webhook' => self::str($d, 'webhook', $path . '.webhook')];
            if (isset($d['allow_insecure'])) {
                if (!is_bool($d['allow_insecure'])) {
                    throw new InvalidConfig($path . '.allow_insecure must be true or false');
                }
                $out['allow_insecure'] = $d['allow_insecure'];
            }

            return $out;
        }
        $out = ['driver' => $driver, 'bot_token' => self::str($d, 'bot_token', $path . '.bot_token')];
        $chat = $d['chat_id'] ?? null;
        if ($chat !== null && !is_int($chat) && !is_string($chat)) {
            throw new InvalidConfig($path . '.chat_id must be an integer or a string');
        }
        if ($chat === null || $chat === '') {
            throw new InvalidConfig($path . '.chat_id is required');
        }
        $out['chat_id'] = (string) $chat;
        if (isset($d['thread_id'])) {
            if (!is_int($d['thread_id']) || $d['thread_id'] < 1) {
                throw new InvalidConfig($path . '.thread_id must be a positive integer');
            }
            $out['thread_id'] = $d['thread_id'];
        }

        return $out;
    }

    /** @param array<string, mixed> $entry a validated entry, see {@see notifyEntry()} */
    public static function destinationFrom(array $entry): Destination
    {
        return match ($entry['driver']) {
            'telegram' => Destination::telegram($entry['bot_token'], $entry['chat_id'], $entry['thread_id'] ?? null),
            'discord' => Destination::discord($entry['webhook']),
            default => Destination::slack($entry['webhook'], ($entry['allow_insecure'] ?? false) === true),
        };
    }

    /** @param 'digest'|'notice' $kind */
    public function destination(string $kind): Destination
    {
        return self::destinationFrom($this->notify[$kind]);
    }

    /** @return list<string> every secret of both destinations, digest first */
    public function notifySecrets(): array
    {
        $out = [];
        foreach (['digest', 'notice'] as $kind) {
            foreach (['webhook', 'bot_token', 'chat_id'] as $k) {
                if (isset($this->notify[$kind][$k])) {
                    $out[] = (string) $this->notify[$kind][$k];
                }
            }
        }

        return $out;
    }

    /**
     * Every credential in the config (model key, notifier tokens and webhooks, source auth, state passwords): what no
     * error text may carry out. Strings only; the connection `instance` is not a value to match.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $candidates = [
            $this->analysis['api_key'],
            ...$this->notifySecrets(),
            $this->source['loki']['auth']['token'] ?? null,
            $this->source['loki']['auth']['password'] ?? null,
            $this->source['datadog']['api_key'] ?? null,
            $this->source['datadog']['app_key'] ?? null,
            $this->state['redis']['password'] ?? null,
            $this->state['pdo']['password'] ?? null,
            $this->state['pdo']['dsn'] ?? null, // a driver error can echo it whole, `password=` and all
            ...($this->kubernetes?->maskValues() ?? []), // the API token, or what token_file holds right now
        ];

        $secrets = array_values(array_unique(array_filter($candidates, static fn (mixed $v): bool => is_string($v) && $v !== '')));
        // Longest first: masking `sk-ant` before `sk-ant-SECRET` would leave `-SECRET` behind. (usort is stable in PHP 8.)
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
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
            if ($until !== null && (!is_string($until) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) !== 1 || !checkdate((int) substr($until, 5, 2), (int) substr($until, 8, 2), (int) substr($until, 0, 4)))) {
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
        self::only($m, ['template', 'error_type', 'field', 'equals', 'matches', ...($allowFingerprint ? ['fingerprint', 'until'] : [])], $path);
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
            self::only($dep, ['stream_tags', 'source'], 'dependencies.' . $name);
            if (($dep['source'] ?? null) !== null) {
                throw new InvalidConfig('dependencies.' . $name . '.source is not available yet; the service source serves all dependencies');
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
        self::only($repo, ['provider', 'web_url', 'path_map'], 'repo');
        self::str($repo, 'web_url', 'repo.web_url');

        return $repo;
    }

    /**
     * @param array<string, mixed> $links
     * @return array<string, mixed>
     */
    private static function links(array $links): array
    {
        self::only($links, ['grafana', 'datadog', 'logs', 'code'], 'links');
        foreach (['grafana' => ['base_url', 'datasource_uid'], 'datadog' => ['app_url']] as $name => $keys) {
            if (is_array($links[$name] ?? null)) {
                self::only($links[$name], $keys, 'links.' . $name);
            }
        }

        return $links;
    }

    /**
     * Spec 008: the `kubernetes` section plus `events` (beside `source`) and `source.driver: kubernetes`.
     * A section holding only the flag is not validated further; any other content, or any use, is.
     *
     * @param array<string, mixed> $config
     * @return array{0: ?KubeSettings, 1: bool} settings, the `kubernetes.enabled` flag
     */
    private static function kubernetes(array $config, string $driver): array
    {
        $sec = self::section($config, 'kubernetes');
        $on = self::flag($sec, 'enabled', 'kubernetes.enabled');
        unset($sec['enabled']);
        $events = $config['events'] ?? null;
        if ($events !== null) {
            if (!is_array($events)) {
                throw new InvalidConfig('events must be a map');
            }
            self::only($events, ['driver'], 'events');
            if (($events['driver'] ?? null) !== 'kubernetes') {
                throw new InvalidConfig('events.driver must be kubernetes');
            }
        }
        $asSource = $driver === 'kubernetes';
        if ($sec === [] && $events === null && !$asSource) {
            return [null, $on];
        }
        if ($sec === [] && ($events !== null || $asSource)) {
            throw new InvalidConfig(($asSource ? 'source.driver kubernetes' : 'events') . ' needs the kubernetes section');
        }
        $deps = is_array($config['dependencies'] ?? null) ? $config['dependencies'] : [];
        unset($deps['enabled']);
        if ($asSource && $deps !== []) {
            throw new InvalidConfig('dependencies are not available with source.driver kubernetes: they are read from the service source');
        }
        if ($asSource && !$on) {
            throw new InvalidConfig('source.driver kubernetes needs kubernetes.enabled to be true');
        }
        try {
            return [KubeSettings::fromArray($sec, $on, $events !== null, $asSource), $on];
        } catch (\InvalidArgumentException $e) {
            throw new InvalidConfig($e->getMessage()); // paths only, never a value
        }
    }

    /** @param array<string, mixed> $s */
    private static function levelMode(array $s): string
    {
        $v = $s['level_mode'] ?? 'text';
        if (!in_array($v, ['label', 'text', 'all'], true)) {
            throw new InvalidConfig('source.level_mode must be label, text or all');
        }

        return $v;
    }

    /**
     * Rejects keys outside $allowed, naming the key path and never a value (a typo must not silently disable a setting).
     *
     * @param array<array-key, mixed> $section
     * @param list<string> $allowed
     */
    private static function only(array $section, array $allowed, string $path): void
    {
        foreach (array_keys($section) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new InvalidConfig('Unknown configuration key: ' . $path . '.' . $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function known(array $section, array $allowed, string $path): array
    {
        self::only($section, $allowed, $path);

        return $section;
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

    /**
     * A strict boolean flag: true/false only, a string such as 'false' is rejected.
     *
     * @param array<array-key, mixed> $from
     */
    private static function flag(array $from, string $key, string $path, bool $default = true): bool
    {
        $v = $from[$key] ?? $default;
        if (!is_bool($v)) {
            throw new InvalidConfig($path . ' must be true or false');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $parent a section holding `$key => ['enabled' => bool]` */
    private static function subFlag(array $parent, string $key, string $path): bool
    {
        $sub = $parent[$key] ?? [];
        if (!is_array($sub)) {
            throw new InvalidConfig($path . ' must be a map');
        }
        self::only($sub, ['enabled'], $path);

        return self::flag($sub, 'enabled', $path . '.enabled');
    }

    /**
     * A list or map that reserves the key `enabled` for its flag (entries never are a bare boolean).
     *
     * @return array{0: mixed, 1: bool} the value without the flag, the flag
     */
    private static function reserved(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_key_exists('enabled', $value)) {
            return [$value, true];
        }
        $on = $value['enabled'];
        if (!is_bool($on)) {
            throw new InvalidConfig($path . '.enabled must be true or false');
        }
        unset($value['enabled']);

        return [$value, $on];
    }

    /** @param array<array-key, mixed> $s */
    private static function maxResponseMb(array $s): int
    {
        $v = self::posInt($s, 'max_response_mb', 16, 'source.max_response_mb');
        if ($v > 64) {
            throw new InvalidConfig('source.max_response_mb must be between 1 and 64');
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
    private static function num(array $from, string $key, float $default, string $path, ?float $min = null): float
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) && !is_float($v)) {
            throw new InvalidConfig($path . ' must be a number');
        }
        if ($min !== null && $v < $min) {
            throw new InvalidConfig($path . ' must be at least ' . $min);
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
