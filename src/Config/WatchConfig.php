<?php

declare(strict_types=1);

namespace Watcher\Config;

use Watcher\Issues\SentrySettings;
use Watcher\Metrics\MetricsSettings;
use Watcher\Notify\Destination;
use Watcher\Observe\EventSchema;
use Watcher\Observe\ObserveSettings;
use Watcher\Level;
use Watcher\State\Redis\RedisClient;
use Watcher\Source\Kubernetes\SecretValue;

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
        'enabled', 'environment', 'purge', 'level_anomaly', 'observe', 'metrics', 'sentry', 'hygiene', 'api_context',
        'known_issues', 'drafts', 'report',
    ];

    /** Every on/off feature flag (spec 012). All default to true; none can touch redaction, the normalizer or the allowlist. */
    public const FLAGS = [
        'heartbeat', 'purge', 'dependencies', 'deploy', 'links.logs', 'links.code', 'service_context', 'watch_rules', 'mute',
        'level_anomaly', 'escalation', 'triage', 'advice', 'baseline', 'grouping', 'notice', 'digest',
        'known_issues', 'drafts', 'routes',
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
        public readonly ObserveSettings $observe,
        public readonly ?MetricsSettings $metrics,
        public readonly ?SentrySettings $sentry,
        public readonly \Watcher\Hygiene\HygieneSettings $hygiene,
        public readonly \Watcher\Incident\IncidentSettings $incident,
        /** @var array<string, mixed>|null the validated `api_context` section (spec 010), null when absent; not a feature flag */
        public readonly ?array $apiContext = null,
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
        foreach (['source', 'analysis', 'notify', 'state', 'serviceContext', 'flags', 'observe', 'metrics', 'sentry', 'apiContext'] as $k) {
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

    /**
     * The master switch, read from the raw array before anything is validated: only the boolean `true` turns the package on.
     * Missing, null, a string ('0', 'false', 'yes') or any other value is off (fail-safe); fromArray() still rejects a non-boolean.
     *
     * @param array<array-key, mixed> $config
     */
    public static function masterSwitchOff(#[\SensitiveParameter] array $config): bool
    {
        return ($config['enabled'] ?? false) !== true;
    }

    /**
     * Single-service config must not carry a sibling `fleet` block (Laravel keeps both under `watcher`).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function withoutFleet(array $config): array
    {
        unset($config['fleet']);

        return $config;
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        $removed = [];
        foreach (require dirname(__DIR__, 2) . '/resources/removed-config-keys.php' as $rule => $message) {
            // `path` or `path=value`: a value rule matches only that value (e.g. source.driver=kubernetes, analysis.provider=anthropic)
            $parts = explode('=', $rule, 2);
            $path = $parts[0];
            $value = $parts[1] ?? null;
            $node = $config;
            foreach (explode('.', $path) as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    continue 2;
                }
                $node = $node[$part];
            }
            if ($value !== null && $node !== $value) {
                continue;
            }
            $removed[] = $message; // every removed key is named at once, in the table's order (`watcher check` lists them all)
        }
        if ($removed !== []) {
            throw new InvalidConfig(implode('; ', $removed));
        }
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

        [$schedule, $interval] = self::schedule(self::section($config, 'schedule'));

        $source = self::source(self::section($config, 'source'));

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
        $baseline = self::baseline($baselineSec, $interval, $state['retention'], $schedule['runs_per_day'], $schedule['period_seconds']);
        $groupingSec = self::section($config, 'grouping');
        $flags['grouping'] = self::flag($groupingSec, 'enabled', 'grouping.enabled');
        unset($groupingSec['enabled']);
        foreach (['purge', 'level_anomaly'] as $name) {
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

        $observe = ObserveSettings::fromArray(
            $config['observe'] ?? [],
            $environment === null ? $service : $service . '_' . $environment,
            $source['driver'] === 'datadog' ? 'api.' . ($source['datadog']['site'] ?? 'datadoghq.com') : ($source['loki']['url'] ?? null),
        );
        if ($observe->anyEnabled()) {
            // The event sanitizer nulls a name outside its pattern, and a null service turns every Prometheus label into "unset": refuse it here.
            if (preg_match(EventSchema::PATTERNS['service'], $service) !== 1) {
                throw new InvalidConfig('service must match [A-Za-z0-9][A-Za-z0-9._@-]{0,63} when an observe sink is enabled');
            }
            if ($environment !== null && preg_match(EventSchema::PATTERNS['environment'], $environment) !== 1) {
                throw new InvalidConfig('environment must match [A-Za-z0-9][A-Za-z0-9._-]{0,31} when an observe sink is enabled');
            }
        }
        if (!$observe->anyEnabled() && ($notify['digest']['driver'] === 'none' || $notify['notice']['driver'] === 'none')) {
            throw new InvalidConfig('notify.' . ($notify['digest']['driver'] === 'none' ? 'digest' : 'notice') . '.driver none needs at least one enabled observe sink');
        }

        $incident = \Watcher\Incident\IncidentSettings::fromArray($config, $notify, $observe);
        $flags['known_issues'] = $incident->knownIssuesEnabled;
        $flags['drafts'] = $incident->draftsEnabled;
        $flags['routes'] = $incident->routesEnabled;

        return new self(
            $service,
            $schedule,
            self::hide($source, 'loki.auth.token', 'loki.auth.password', 'datadog.api_key', 'datadog.app_key'),
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
            self::hide($analysis, 'api_keys.openai', 'api_keys.gemini'),
            $patterns,
            self::posInt($redaction, 'min_group_events_for_text', 3, 'redaction.min_group_events_for_text'),
            self::hide($state, 'redis.password', 'pdo.dsn', 'pdo.username', 'pdo.password'),
            self::hide($notify, 'digest.webhook', 'digest.bot_token', 'notice.webhook', 'notice.bot_token'),
            $heartbeat,
            $baseline,
            self::grouping($groupingSec),
            $enabled,
            $environment,
            $environment === null ? $service : $service . '@' . $environment,
            $flags,
            $observe,
            // Spec 009: absent = off. Validated even with `enabled: false`; not a FLAG (the section's own `enabled` is the switch).
            is_array($config['metrics'] ?? null) ? MetricsSettings::fromArray($config['metrics'], $service) : self::absent($config, 'metrics'),
            is_array($config['sentry'] ?? null) ? SentrySettings::fromArray($config['sentry']) : self::absent($config, 'sentry'),
            \Watcher\Hygiene\HygieneSettings::fromArray(self::section($config, 'hygiene')),
            $incident,
            array_key_exists('api_context', $config) ? self::hide(self::apiContext($config['api_context'], $state['retention']), 'source', 'auth_token') : null,
        );
    }

    /**
     * The credential leaves of a section become {@see SecretValue} holders, so a dump of the config (var_export, print_r, serialize)
     * shows none. Paths are dotted keys inside the section; a missing or non-string leaf is left alone.
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    public static function hide(array $section, string ...$paths): array
    {
        foreach ($paths as $path) {
            $parts = explode('.', $path, 2);
            $head = $parts[0];
            if (!array_key_exists($head, $section)) {
                continue;
            }
            if (isset($parts[1]) && is_array($section[$head])) {
                $section[$head] = self::hide($section[$head], $parts[1]);
            } elseif (!isset($parts[1]) && is_string($section[$head])) {
                $section[$head] = new SecretValue($section[$head]);
            }
        }

        return $section;
    }

    /** The value of a holder made by {@see hide()}, read at the point of use; a plain string passes through. */
    public static function reveal(mixed $value): string
    {
        return $value instanceof SecretValue ? $value->reveal() : (string) $value;
    }

    /** A section with every holder read back, for comparing two sections: a SecretValue alone compares equal to any other. Never for output. */
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof SecretValue) {
            return $value->reveal();
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }

    /**
     * @param array<string, mixed> $sch
     * @return array{0: array<string, mixed>, 1: \DateInterval}
     */
    private static function schedule(array $sch): array
    {
        self::only($sch, ['run_at', 'every', 'timezone', 'window', 'catch_up', 'ingestion_delay', 'lock_ttl', 'run_deadline'], 'schedule');
        $every = $sch['every'] ?? null;
        if ($every !== null && !is_string($every)) {
            throw new InvalidConfig('schedule.every must be one of PT1H, PT2H, PT3H, PT4H, PT6H, PT8H, PT12H');
        }
        if ($every !== null && !in_array($every, \Watcher\Schedule\RunGate::allowedEvery(), true)) {
            throw new InvalidConfig('schedule.every must be one of PT1H, PT2H, PT3H, PT4H, PT6H, PT8H, PT12H');
        }
        $runAtRaw = $sch['run_at'] ?? null;
        $runAtList = [];
        if ($runAtRaw !== null) {
            if (is_string($runAtRaw)) {
                $runAtList = [$runAtRaw];
            } elseif (is_array($runAtRaw) && array_is_list($runAtRaw)) {
                $runAtList = $runAtRaw;
            } else {
                throw new InvalidConfig('schedule.run_at must be HH:MM or a list of HH:MM');
            }
            foreach ($runAtList as $t) {
                if (!is_string($t) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $t) !== 1) {
                    throw new InvalidConfig('schedule.run_at must be HH:MM or a list of HH:MM');
                }
            }
            sort($runAtList);
            if (count($runAtList) !== count(array_unique($runAtList))) {
                throw new InvalidConfig('schedule.run_at entries must be unique');
            }
        }
        if ($every !== null && $runAtList !== []) {
            throw new InvalidConfig('schedule.run_at and schedule.every are mutually exclusive');
        }
        if ($every === null && $runAtList === []) {
            throw new InvalidConfig('schedule.run_at (or schedule.every) is required');
        }
        if ($runAtList !== []) {
            self::assertRunAtGaps($runAtList);
        }
        $tz = self::optStr($sch, 'timezone', 'UTC', 'schedule.timezone');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidConfig('schedule.timezone must be an IANA timezone');
        }
        $singleDaily = $every === null && count($runAtList) === 1;
        $windowGiven = array_key_exists('window', $sch);
        $window = null;
        if ($windowGiven) {
            $window = $sch['window'];
            if (!is_string($window)) {
                throw new InvalidConfig('schedule.window must be an ISO-8601 duration');
            }
            try {
                $windowInterval = new \DateInterval($window);
            } catch (\Exception) {
                throw new InvalidConfig('schedule.window must be an ISO-8601 duration');
            }
            if ($windowInterval->y + $windowInterval->m + $windowInterval->d + $windowInterval->h + $windowInterval->i + $windowInterval->s === 0) {
                throw new InvalidConfig('schedule.window must be longer than zero');
            }
        } elseif ($singleDaily) {
            $window = 'PT24H';
            $windowInterval = new \DateInterval($window);
        } else {
            $windowInterval = new \DateInterval('PT1H');
        }
        $catchUp = $sch['catch_up'] ?? 1;
        if (!is_int($catchUp) || $catchUp < 1 || $catchUp > 24) {
            throw new InvalidConfig('schedule.catch_up must be an integer from 1 to 24');
        }
        if ($catchUp > 1 && $every === null) {
            throw new InvalidConfig('schedule.catch_up above 1 needs schedule.every');
        }
        $delay = $sch['ingestion_delay'] ?? 'PT0S';
        try {
            $delaySeconds = (new \DateTimeImmutable('@0'))->add(new \DateInterval($delay))->getTimestamp();
        } catch (\Exception) {
            throw new InvalidConfig('schedule.ingestion_delay must be an ISO-8601 duration');
        }
        if (!is_string($delay) || $delaySeconds < 0) {
            throw new InvalidConfig('schedule.ingestion_delay must be an ISO-8601 duration');
        }
        $periodSeconds = $every !== null
            ? (new \DateTimeImmutable('@0'))->add(new \DateInterval($every))->getTimestamp()
            : self::longestRunAtGapSeconds($runAtList);
        if ($delaySeconds >= $periodSeconds) {
            throw new InvalidConfig('schedule.ingestion_delay must be shorter than the period');
        }
        $runsPerDay = $every !== null ? intdiv(86400, $periodSeconds) : count($runAtList);
        $lockTtl = self::posInt($sch, 'lock_ttl', 900, 'schedule.lock_ttl');
        $deadline = self::posInt($sch, 'run_deadline', $lockTtl <= 60 ? max(1, intdiv($lockTtl, 2)) : $lockTtl - max(30, intdiv($lockTtl, 10)), 'schedule.run_deadline');
        if ($deadline > $lockTtl) {
            throw new InvalidConfig('schedule.run_deadline must not be longer than schedule.lock_ttl');
        }
        $heartbeatPeriod = $singleDaily
            ? $windowInterval
            : new \DateInterval('PT' . max(1, $periodSeconds) . 'S');

        return [[
            'run_at' => $runAtList,
            'every' => $every,
            'timezone' => $tz,
            'window' => $window,
            'catch_up' => $catchUp,
            'ingestion_delay' => $delay,
            'ingestion_delay_seconds' => $delaySeconds,
            'lock_ttl' => $lockTtl,
            'run_deadline' => $deadline,
            'period_seconds' => $periodSeconds,
            'runs_per_day' => $runsPerDay,
            'single_daily_run_at' => $singleDaily,
        ], $heartbeatPeriod];
    }

    /** @param list<string> $runAtList sorted */
    private static function assertRunAtGaps(array $runAtList): void
    {
        $n = count($runAtList);
        for ($i = 0; $i < $n; ++$i) {
            $a = self::minutesOfDay($runAtList[$i]);
            $b = self::minutesOfDay($runAtList[($i + 1) % $n]);
            $gap = $i + 1 < $n ? $b - $a : (1440 - $a) + $b;
            if ($gap < 60) {
                throw new InvalidConfig('schedule.run_at entries must be at least 1 hour apart');
            }
        }
    }

    /** @param list<string> $runAtList */
    private static function longestRunAtGapSeconds(array $runAtList): int
    {
        $max = 0;
        $n = count($runAtList);
        for ($i = 0; $i < $n; ++$i) {
            $a = self::minutesOfDay($runAtList[$i]);
            $b = self::minutesOfDay($runAtList[($i + 1) % $n]);
            $gap = $i + 1 < $n ? $b - $a : (1440 - $a) + $b;
            $max = max($max, $gap);
        }

        return $max * 60;
    }

    private static function minutesOfDay(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }

    /**
     * @param array<string, mixed> $b
     * @return array{mode: string, windows: int, max_age: string, max_age_seconds: int, exclude: list<string>, since: ?string}
     */
    private static function baseline(array $b, \DateInterval $window, int $retention, int $runsPerDay, int $periodSeconds): array
    {
        self::only($b, ['mode', 'windows', 'max_age', 'exclude', 'since'], 'baseline');
        $mode = $b['mode'] ?? 'previous';
        if (!in_array($mode, ['previous', 'rolling', 'weekday', 'same_slot_yesterday', 'same_slot_last_week'], true)) {
            throw new InvalidConfig('baseline.mode must be one of previous, rolling, weekday, same_slot_yesterday, same_slot_last_week');
        }
        if (in_array($mode, ['rolling', 'weekday'], true) && ($runsPerDay > 1 || $periodSeconds < 86400)) {
            throw new InvalidConfig('baseline.mode rolling/weekday needs whole-day periods; use same_slot_yesterday or same_slot_last_week with schedule.every');
        }
        if ($mode === 'same_slot_last_week' && $retention < 691200) {
            throw new InvalidConfig('state.retention (691200 s) is shorter than baseline.mode same_slot_last_week needs (691200 s)');
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
        self::only($a, ['provider', 'providers', 'api_keys', 'providers_options', 'client', 'models', 'max_input_tokens', 'max_output_tokens', 'confidence_threshold', 'escalation_cap', 'triage', 'advice'], 'analysis');
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

        // The transport is chosen per provider now; the key is accepted (every existing config has `http`) and ignored.
        if (array_key_exists('client', $a) && $a['client'] !== 'http') {
            throw new InvalidConfig('analysis.client is no longer a transport choice; every provider uses the package HTTP client. Delete the key.');
        }
        $client = 'http';
        [$provider, $stepProviders, $apiKeys, $options] = self::providers($a);

        return array_merge($a, $limits, [
            'provider' => $provider,
            'step_providers' => $stepProviders,
            'api_keys' => $apiKeys,
            'providers_options' => $options,
            'client' => $client,
            'models' => $models,
            'confidence_threshold' => $conf,
            'escalation_cap' => self::posInt($a, 'escalation_cap', 3, 'analysis.escalation_cap'),
        ]);
    }

    /** Model-provider names this release knows. Adding one needs its adapter (specs/016-model-providers). */
    private const PROVIDERS = ['openai', 'gemini'];

    private const PROVIDER_BASE_URLS = [
        'openai' => 'https://api.openai.com',
        'gemini' => 'https://generativelanguage.googleapis.com',
    ];

    /**
     * The provider of each step, the key of each provider in use, and the options of each provider (every provider listed).
     *
     * @param array<string, mixed> $a
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>, 3: array<string, array{base_url: string, retries: int}>}
     */
    private static function providers(array $a): array
    {
        $provider = self::optStr($a, 'provider', 'gemini', 'analysis.provider');
        if ($provider === 'anthropic') {
            throw new InvalidConfig('analysis.provider = anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use gemini or openai.');
        }
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidConfig('analysis.provider must be one of: ' . implode(', ', self::PROVIDERS));
        }

        $perStep = self::section($a, 'providers');
        self::only($perStep, self::STEPS, 'analysis.providers');
        $steps = [];
        foreach (self::STEPS as $step) {
            $name = $perStep[$step] ?? $provider;
            if ($name === 'anthropic') {
                throw new InvalidConfig('analysis.providers.' . $step . ' = anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use gemini or openai.');
            }
            if (!is_string($name) || !in_array($name, self::PROVIDERS, true)) {
                throw new InvalidConfig('analysis.providers.' . $step . ' must be one of: ' . implode(', ', self::PROVIDERS));
            }
            $steps[$step] = $name;
        }

        $keys = self::section($a, 'api_keys');
        self::only($keys, self::PROVIDERS, 'analysis.api_keys');
        $apiKeys = [];
        foreach (self::PROVIDERS as $name) {
            $key = $keys[$name] ?? null;
            if (is_string($key) && $key !== '') {
                $apiKeys[$name] = $key;
            }
        }
        foreach (array_unique(array_values($steps)) as $name) {
            if (!isset($apiKeys[$name])) {
                throw new InvalidConfig('analysis.api_keys.' . $name . ' is required for the provider in use');
            }
        }

        $given = self::section($a, 'providers_options');
        self::only($given, self::PROVIDERS, 'analysis.providers_options');
        $options = [];
        foreach (self::PROVIDERS as $name) {
            $opt = self::section($given, $name);
            self::only($opt, ['base_url', 'retries'], 'analysis.providers_options.' . $name);
            $url = self::optStr($opt, 'base_url', self::PROVIDER_BASE_URLS[$name], 'analysis.providers_options.' . $name . '.base_url');
            if (!str_starts_with($url, 'https://')) {
                throw new InvalidConfig('analysis.providers_options.' . $name . '.base_url must be https');
            }
            $retries = $opt['retries'] ?? 0;
            if (!is_int($retries) || $retries < 0 || $retries > 3) {
                throw new InvalidConfig('analysis.providers_options.' . $name . '.retries must be 0 to 3');
            }
            if ($retries !== 0) {
                throw new InvalidConfig('analysis.providers_options.' . $name . '.retries must be 0: this adapter does not retry (the analyzer makes its second attempt)');
            }
            $options[$name] = ['base_url' => $url, 'retries' => $retries];
        }

        return [$provider, $steps, $apiKeys, $options];
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
        self::only($n, ['digest', 'notice', 'routes', 'routes_enabled'], 'notify');
        $digest = self::notifyEntry(self::withoutEnabled($n['digest'] ?? null), 'notify.digest');
        $notice = $n['notice'] ?? null;
        $notice = self::withoutEnabled($notice);
        $notice = $notice === null || $notice === [] ? $digest : self::notifyEntry($notice, 'notify.notice');
        $routes = $n['routes'] ?? [];
        if (!is_array($routes) || ($routes !== [] && !array_is_list($routes))) {
            throw new InvalidConfig('notify.routes must be a list');
        }
        $routesEnabled = $n['routes_enabled'] ?? true;
        if (!is_bool($routesEnabled)) {
            throw new InvalidConfig('notify.routes_enabled must be true or false');
        }

        return ['digest' => $digest, 'notice' => $notice, 'routes' => $routes, 'routes_enabled' => $routesEnabled];
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
        $keys = ['slack' => ['webhook', 'allow_insecure'], 'telegram' => ['bot_token', 'chat_id', 'thread_id'], 'discord' => ['webhook'], 'none' => []];
        if (!is_array($d)) {
            throw new InvalidConfig($path . '.webhook is required');
        }
        $driver = $d['driver'] ?? 'slack';
        if (!is_string($driver)) {
            throw new InvalidConfig($path . '.driver must be a string');
        }
        if (!isset($keys[$driver])) {
            throw new InvalidConfig($path . '.driver must be one of slack, telegram, discord, none');
        }
        if ($driver === 'none') {
            // A config file lists `webhook => env(...)` for every driver; unset values (null, '', false from getenv) are not a setting.
            $d = array_filter($d, static fn (mixed $v, int|string $k): bool => $k === 'driver' || !in_array($v, [null, '', false], true), ARRAY_FILTER_USE_BOTH);
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
        if ($driver === 'none') {
            return ['driver' => 'none']; // dashboard-only: nothing to deliver, nothing to validate
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
            'none' => Destination::none(),
            'telegram' => Destination::telegram(self::reveal($entry['bot_token']), $entry['chat_id'], $entry['thread_id'] ?? null),
            'discord' => Destination::discord(self::reveal($entry['webhook'])),
            default => Destination::slack(self::reveal($entry['webhook']), ($entry['allow_insecure'] ?? false) === true),
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
                    $out[] = self::reveal($this->notify[$kind][$k]);
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
            ...array_values($this->analysis['api_keys'] ?? []),
            ...$this->notifySecrets(),
            $this->source['loki']['auth']['token'] ?? null,
            $this->source['loki']['auth']['password'] ?? null,
            $this->source['datadog']['api_key'] ?? null,
            $this->source['datadog']['app_key'] ?? null,
            $this->state['redis']['password'] ?? null,
            $this->state['pdo']['password'] ?? null,
            $this->state['pdo']['dsn'] ?? null, // a driver error can echo it whole, `password=` and all
            ...$this->observe->secrets(),
            ...($this->metrics?->secrets() ?? []),
            ...($this->sentry?->secrets() ?? []),
            ...$this->apiContextSecrets(),
        ];
        $values = array_map(static fn (mixed $v): mixed => $v instanceof SecretValue ? $v->reveal() : $v, $candidates);
        $secrets = array_values(array_unique(array_filter($values, static fn (mixed $v): bool => is_string($v) && $v !== '')));
        // Longest first: masking `sk-ant` before `sk-ant-SECRET` would leave `-SECRET` behind. (usort is stable in PHP 8.)
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /** @param array<array-key, mixed> $config */
    private static function absent(array $config, string $key): null
    {
        if (($config[$key] ?? null) !== null) {
            throw new InvalidConfig($key . ' must be a map');
        }

        return null;
    }

    /**
     * The OpenAPI bearer token and, for a URL source, the URL itself (it may carry a token in the path or query).
     *
     * @return list<string>
     */
    private function apiContextSecrets(): array
    {
        $source = self::reveal($this->apiContext['source'] ?? '');

        return array_values(array_filter([
            isset($this->apiContext['auth_token']) ? self::reveal($this->apiContext['auth_token']) : null,
            preg_match('~^https?://~i', $source) === 1 ? $source : null,
        ], 'is_string'));
    }

    /**
     * The `api_context` section (spec 010): validated even when `enabled` is false, so a later flip never reveals a bad value. Key paths only.
     *
     * @return array<string, mixed>
     */
    private static function apiContext(mixed $sec, int $retention): array
    {
        if (!is_array($sec)) {
            throw new InvalidConfig('api_context must be a map');
        }
        self::only($sec, ['enabled', 'source', 'auth_token', 'ttl', 'max_endpoints', 'summary_cap', 'max_bytes', 'base_path', 'ignore_prefixes', 'allow_insecure', 'allow_private_network', 'allowed_roots'], 'api_context');
        $enabled = self::flag($sec, 'enabled', 'api_context.enabled');
        $insecure = self::flag($sec, 'allow_insecure', 'api_context.allow_insecure', false);
        $private = self::flag($sec, 'allow_private_network', 'api_context.allow_private_network', false);
        $source = $sec['source'] ?? null;
        if ($source !== null && $source !== '' || $enabled) {
            $source = is_string($source) && $source !== '' ? $source : throw new InvalidConfig('api_context.source is required');
            if (preg_match('~\.ya?ml(?:[?#].*)?$~i', $source) === 1) {
                throw new InvalidConfig('api_context.source must be a JSON document (YAML is not supported yet)');
            }
            if (preg_match('~^https?://~i', $source) === 1) {
                if (\Watcher\ApiContext\OpenApiSource::urlProblem($source, $insecure, $private) !== null) {
                    throw new InvalidConfig('api_context.source must be an https URL; plain http needs allow_insecure and allow_private_network and a loopback, private-network or cluster-local host, and no internal address is allowed otherwise');
                }
            } elseif (preg_match('~^(?:file:(?://)?)?/~i', $source) !== 1 || str_contains(preg_replace('~^file:~i', '', $source) ?? '', '://')) {
                throw new InvalidConfig('api_context.source must be an https URL or an absolute file path');
            }
        }
        $token = $sec['auth_token'] ?? null;
        if ($token !== null && (!is_string($token) || $token === '')) {
            throw new InvalidConfig('api_context.auth_token must be a non-empty string');
        }
        $ttl = $retention < 86400 ? $retention : 86400;
        if (array_key_exists('ttl', $sec)) {
            $ttl = is_string($sec['ttl']) ? self::seconds($sec['ttl']) : null;
            if ($ttl === null || $ttl < 1 || $ttl > $retention) {
                throw new InvalidConfig('api_context.ttl must be a duration between 1 second and state.retention');
            }
        }
        $basePath = $sec['base_path'] ?? null;
        if ($basePath !== null && (!is_string($basePath) || preg_match('~^/[A-Za-z0-9._\-/]*$~D', $basePath) !== 1 || in_array('..', explode('/', $basePath), true))) {
            throw new InvalidConfig('api_context.base_path must be a path starting with /');
        }
        $prefixes = $sec['ignore_prefixes'] ?? [];
        if (!is_array($prefixes) || !array_is_list($prefixes) || count($prefixes) > 10 || array_filter($prefixes, static fn (mixed $p): bool => is_string($p) && preg_match('~^/[A-Za-z0-9._\-/]*$~D', $p) === 1 && !in_array('..', explode('/', $p), true)) !== $prefixes) {
            throw new InvalidConfig('api_context.ignore_prefixes must be a list of at most 10 paths starting with /');
        }
        $roots = $sec['allowed_roots'] ?? [];
        if (!is_array($roots) || !array_is_list($roots) || count($roots) > 10 || array_filter($roots, static fn (mixed $p): bool => is_string($p) && str_starts_with($p, '/') && !str_contains($p, '://') && !in_array('..', explode('/', $p), true)) !== $roots) {
            throw new InvalidConfig('api_context.allowed_roots must be a list of at most 10 absolute paths');
        }

        return [
            'enabled' => $enabled,
            'source' => is_string($source) ? $source : null,
            'auth_token' => $token,
            'ttl' => $ttl,
            'max_endpoints' => self::boundedInt($sec, 'max_endpoints', 5, 1, 20),
            'summary_cap' => self::boundedInt($sec, 'summary_cap', 80, 20, 200),
            'max_bytes' => self::boundedInt($sec, 'max_bytes', 1048576, 65536, 2097152),
            'base_path' => $basePath,
            'ignore_prefixes' => $prefixes,
            'allow_insecure' => $insecure,
            'allow_private_network' => $private,
            'allowed_roots' => $roots,
        ];
    }

    /** @param array<array-key, mixed> $from */
    private static function boundedInt(array $from, string $key, int $default, int $min, int $max): int
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new InvalidConfig("api_context.$key must be an integer between $min and $max");
        }

        return $v;
    }

    /** ISO-8601 duration -> seconds (a month counts 30 days, a year 365); null when it is not one. */
    private static function seconds(string $iso): ?int
    {
        try {
            $i = new \DateInterval($iso);
        } catch (\Exception) {
            return null;
        }

        return ((($i->y * 365 + $i->m * 30 + $i->d) * 24 + $i->h) * 60 + $i->i) * 60 + $i->s;
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
        self::only($links, ['grafana', 'datadog', 'logs', 'code', 'tempo', 'datadog_apm'], 'links');
        foreach (['grafana' => ['base_url', 'datasource_uid'], 'datadog' => ['app_url']] as $name => $keys) {
            if (is_array($links[$name] ?? null)) {
                self::only($links[$name], $keys, 'links.' . $name);
            }
        }

        // Spec 009 US4: trace links. Built, never fetched; the trace id exists only inside the link URL.
        foreach (['tempo' => ['base_url', 'datasource_uid', 'trace_field', 'service_name'], 'datadog_apm' => ['trace_field', 'service']] as $name => $keys) {
            if (!array_key_exists($name, $links)) {
                continue;
            }
            if (!is_array($links[$name])) {
                throw new InvalidConfig('links.' . $name . ' must be a map');
            }
            self::only($links[$name], $keys, 'links.' . $name);
            foreach ($keys as $k) {
                $v = $links[$name][$k] ?? null;
                $bad = $v !== null && (!is_string($v) || $v === '' || ($k === 'trace_field' && preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $v) !== 1));
                if ($bad || ($name === 'tempo' && in_array($k, ['base_url', 'datasource_uid'], true) && $v === null)) {
                    throw new InvalidConfig('links.' . $name . '.' . $k . ($v === null ? ' is required' : ' is invalid'));
                }
            }
        }
        if (isset($links['tempo']['base_url']) && SecureUrl::problem((string) $links['tempo']['base_url']) !== null) {
            throw new InvalidConfig('links.tempo.base_url must be an https URL without credentials');
        }
        if (array_key_exists('datadog_apm', $links) && !is_string($links['datadog']['app_url'] ?? null)) {
            throw new InvalidConfig('links.datadog_apm requires links.datadog.app_url');
        }

        return $links;
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
