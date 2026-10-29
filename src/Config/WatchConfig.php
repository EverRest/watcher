<?php

declare(strict_types=1);

namespace Watcher\Config;

use Watcher\Config\Section\AlertsSection;
use Watcher\Config\Section\AnalysisSection;
use Watcher\Config\Section\ApiContextSection;
use Watcher\Config\Section\BaselineSection;
use Watcher\Config\Section\ContextSection;
use Watcher\Config\Section\GroupingSection;
use Watcher\Config\Section\HeartbeatSection;
use Watcher\Config\Section\NotifySection;
use Watcher\Config\Section\ScheduleSection;
use Watcher\Config\Section\SectionFields;
use Watcher\Config\Section\SourceSection;
use Watcher\Config\Section\StateSection;
use Watcher\Config\Section\WatchRulesSection;
use Watcher\Issues\SentrySettings;
use Watcher\Level;
use Watcher\Metrics\MetricsSettings;
use Watcher\Notify\Destination;
use Watcher\Observe\EventSchema;
use Watcher\Observe\ObserveSettings;
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
        'known_issues', 'drafts', 'report', 'alerts',
    ];

    /** Every on/off feature flag (spec 012). All default to true; none can touch redaction, the normalizer or the allowlist. */
    public const FLAGS = [
        'heartbeat', 'purge', 'dependencies', 'deploy', 'links.logs', 'links.code', 'service_context', 'watch_rules', 'mute',
        'level_anomaly', 'escalation', 'triage', 'advice', 'baseline', 'grouping', 'notice', 'digest',
        'known_issues', 'drafts', 'routes', 'alerts',
    ];

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
        /** @var array{window: int, fields: list<string>, immediate: array<string, array{level: Level, message: ?string, action: ?string}>} alerts; window in seconds */
        public readonly array $alerts = ['window' => 900, 'fields' => [], 'immediate' => []],
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

        $service = SectionFields::str($config, 'service', 'service');
        if (str_contains($service, '@')) {
            throw new InvalidConfig('service must not contain @ (it joins service and environment in state keys)');
        }
        $enabled = SectionFields::flag($config, 'enabled', 'enabled', false);
        $environment = $config['environment'] ?? null;
        if ($environment !== null && (!is_string($environment) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $environment) !== 1)) {
            throw new InvalidConfig('environment must be a name of letters, digits, dot, dash and underscore');
        }
        $flags = [];

        [$schedule, $interval] = ScheduleSection::schedule(SectionFields::section($config, 'schedule'));

        $source = SourceSection::source(SectionFields::section($config, 'source'));

        $selSec = SectionFields::section($config, 'selection');
        SectionFields::only($selSec, ['min_level', 'growth_factor', 'top_n', 'top_m', 'escalation'], 'selection');
        $flags['escalation'] = SectionFields::subFlag($selSec, 'escalation', 'selection.escalation');
        $selection = [
            'min_level' => SectionFields::level($selSec, 'min_level', 'error', 'selection.min_level'),
            'growth_factor' => SectionFields::num($selSec, 'growth_factor', 3.0, 'selection.growth_factor'),
            'top_n' => SectionFields::posInt($selSec, 'top_n', 50, 'selection.top_n'),
            'top_m' => SectionFields::posInt($selSec, 'top_m', 10, 'selection.top_m'),
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
            SectionFields::only($ctx, ['enabled', 'text'], 'service_context');
            $flags['service_context'] = SectionFields::flag($ctx, 'enabled', 'service_context.enabled');
            $ctx = $ctx['text'] ?? '';
        }
        if (!is_string($ctx) || mb_strlen($ctx) > 1000) {
            throw new InvalidConfig('service_context must be a string of at most 1000 characters');
        }

        $domains = $config['dependency_domains'] ?? ['svc.cluster.local'];
        if (!is_array($domains) || !array_is_list($domains) || array_filter($domains, 'is_string') !== $domains) {
            throw new InvalidConfig('dependency_domains must be a list of strings');
        }

        $redaction = SectionFields::section($config, 'redaction');
        SectionFields::only($redaction, ['extra_patterns', 'min_group_events_for_text'], 'redaction');
        $patterns = $redaction['extra_patterns'] ?? [];
        if (!is_array($patterns)) {
            throw new InvalidConfig('redaction.extra_patterns must be a map');
        }
        foreach ($patterns as $name => $re) {
            if (in_array(strtoupper((string) $name), \Watcher\Privacy\Redactor::RESERVED, true)) {
                throw new InvalidConfig("redaction.extra_patterns.$name is reserved for a built-in placeholder");
            }
            SectionFields::regex($re, 'redaction.extra_patterns.' . $name);
        }

        $state = StateSection::state(SectionFields::section($config, 'state'));
        $baselineSec = SectionFields::section($config, 'baseline');
        $flags['baseline'] = SectionFields::flag($baselineSec, 'enabled', 'baseline.enabled');
        unset($baselineSec['enabled']);
        $baseline = BaselineSection::baseline($baselineSec, $interval, $state['retention'], $schedule['runs_per_day'], $schedule['period_seconds']);
        $groupingSec = SectionFields::section($config, 'grouping');
        $flags['grouping'] = SectionFields::flag($groupingSec, 'enabled', 'grouping.enabled');
        unset($groupingSec['enabled']);
        foreach (['purge', 'level_anomaly'] as $name) {
            $sec = SectionFields::section($config, $name);
            SectionFields::only($sec, ['enabled'], $name);
            $flags[$name] = SectionFields::flag($sec, 'enabled', $name . '.enabled');
        }
        [$watchRules, $flags['watch_rules']] = SectionFields::reserved($config['watch_rules'] ?? [], 'watch_rules');
        [$muteEntries, $flags['mute']] = SectionFields::reserved($config['mute'] ?? [], 'mute');
        $rules = WatchRulesSection::watchRules($watchRules); // validated even when switched off, so a flag flip never reveals a bad rule
        $mute = WatchRulesSection::mute($muteEntries);
        [$deps, $flags['dependencies']] = SectionFields::reserved($config['dependencies'] ?? [], 'dependencies');
        $deploySec = SectionFields::known(SectionFields::section($config, 'deploy'), ['release', 'commit', 'version_label', 'enabled'], 'deploy');
        $flags['deploy'] = SectionFields::flag($deploySec, 'enabled', 'deploy.enabled');
        $links = ContextSection::links(SectionFields::section($config, 'links'));
        $flags['links.logs'] = SectionFields::subFlag($links, 'logs', 'links.logs');
        $flags['links.code'] = SectionFields::subFlag($links, 'code', 'links.code');
        $analysis = AnalysisSection::analysis(SectionFields::section($config, 'analysis'));
        $flags['triage'] = SectionFields::subFlag($analysis, 'triage', 'analysis.triage');
        $flags['advice'] = SectionFields::subFlag($analysis, 'advice', 'analysis.advice');
        $heartbeat = HeartbeatSection::heartbeat(SectionFields::section($config, 'heartbeat'), $interval);
        $flags['heartbeat'] = $heartbeat['enabled'];
        unset($heartbeat['enabled']);
        $flags['alerts'] = SectionFields::flag(SectionFields::section($config, 'alerts'), 'enabled', 'alerts.enabled', false);
        $alerts = AlertsSection::alerts(SectionFields::section($config, 'alerts'));
        $notifySec = SectionFields::section($config, 'notify');
        $notify = NotifySection::notify($notifySec);
        foreach (['digest', 'notice'] as $kind) {
            $flags[$kind] = SectionFields::flag(is_array($notifySec[$kind] ?? null) ? $notifySec[$kind] : [], 'enabled', 'notify.' . $kind . '.enabled');
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
            Credentials::hide($source, 'loki.auth.token', 'loki.auth.password', 'datadog.api_key', 'datadog.app_key'),
            $flags['watch_rules'] ? $rules : [],
            $flags['mute'] ? $mute : [],
            $ctx,
            ContextSection::dependencies($deps),
            SectionFields::posInt($config, 'dependency_read_cap', 5000, 'dependency_read_cap'),
            $domains,
            $deploySec,
            $links,
            ContextSection::repo($config['repo'] ?? null),
            $selection,
            Credentials::hide($analysis, 'api_keys.openai', 'api_keys.gemini'),
            $patterns,
            SectionFields::posInt($redaction, 'min_group_events_for_text', 3, 'redaction.min_group_events_for_text'),
            Credentials::hide($state, 'redis.password', 'pdo.dsn', 'pdo.username', 'pdo.password'),
            Credentials::hide($notify, 'digest.webhook', 'digest.bot_token', 'notice.webhook', 'notice.bot_token'),
            $heartbeat,
            $baseline,
            GroupingSection::grouping($groupingSec),
            $enabled,
            $environment,
            $environment === null ? $service : $service . '@' . $environment,
            $flags,
            $observe,
            // Spec 009: absent = off. Validated even with `enabled: false`; not a FLAG (the section's own `enabled` is the switch).
            is_array($config['metrics'] ?? null) ? MetricsSettings::fromArray($config['metrics'], $service) : SectionFields::absent($config, 'metrics'),
            is_array($config['sentry'] ?? null) ? SentrySettings::fromArray($config['sentry']) : SectionFields::absent($config, 'sentry'),
            \Watcher\Hygiene\HygieneSettings::fromArray(SectionFields::section($config, 'hygiene')),
            $incident,
            array_key_exists('api_context', $config) ? Credentials::hide(ApiContextSection::apiContext($config['api_context'], $state['retention']), 'source', 'auth_token') : null,
            $alerts,
        );
    }
























    /** @param 'digest'|'notice' $kind */
    public function destination(string $kind): Destination
    {
        return NotifySection::destinationFrom($this->notify[$kind]);
    }

    /** @return list<string> every secret of both destinations, digest first */
    public function notifySecrets(): array
    {
        $out = [];
        foreach (['digest', 'notice'] as $kind) {
            foreach (['webhook', 'bot_token', 'chat_id'] as $k) {
                if (isset($this->notify[$kind][$k])) {
                    $out[] = Credentials::reveal($this->notify[$kind][$k]);
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


    /**
     * The OpenAPI bearer token and, for a URL source, the URL itself (it may carry a token in the path or query).
     *
     * @return list<string>
     */
    private function apiContextSecrets(): array
    {
        $source = Credentials::reveal($this->apiContext['source'] ?? '');

        return array_values(array_filter([
            isset($this->apiContext['auth_token']) ? Credentials::reveal($this->apiContext['auth_token']) : null,
            preg_match('~^https?://~i', $source) === 1 ? $source : null,
        ], 'is_string'));
    }
























    /**
     * One validated `notify.<kind>` entry; every message names the key path, never a value. Public for the fleet.
     *
     * @return array<string, mixed>
     */
    public static function notifyEntry(mixed $d, string $path): array
    {
        return NotifySection::notifyEntry($d, $path);
    }

    /** @param array<string, mixed> $entry a validated entry, see {@see notifyEntry()} */
    public static function destinationFrom(array $entry): Destination
    {
        return NotifySection::destinationFrom($entry);
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
        return Credentials::hide($section, ...$paths);
    }

    /** The value of a holder made by {@see hide()}, read at the point of use; a plain string passes through. */
    public static function reveal(mixed $value): string
    {
        return Credentials::reveal($value);
    }

    /** A section with every holder read back, for comparing two sections: a SecretValue alone compares equal to any other. Never for output. */
    public static function plain(mixed $value): mixed
    {
        return Credentials::plain($value);
    }
}
