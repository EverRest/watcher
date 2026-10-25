<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Config\InvalidConfig;
use Watcher\Notify\Destination;
use Watcher\Config\WatchConfig;

/**
 * A validated fleet (contracts/config.md): every entry is a complete single-service config checked by the unchanged
 * WatchConfig. Nothing runs and nothing external is called here. Messages carry key paths, never values.
 */
final class FleetConfig
{
    private const KEYS = ['fleet', 'enabled', 'digest', 'timezone', 'time_budget', 'service_time_budget', 'budget', 'notify', 'correlation', 'observe', 'defaults', 'services'];

    /**
     * @param list<ServiceEntry> $services in configuration order (FleetOrder decides the run order)
     * @param array{daily_budget_usd: ?float, daily_token_limit: ?int, alert_percents: list<int>} $budget
     * @param array<string, mixed> $notify fleet default destinations
     */
    private function __construct(
        public readonly string $name,
        public readonly array $services,
        public readonly string $digest,
        public readonly string $timezone,
        public readonly ?int $timeBudgetSeconds,
        public readonly array $budget,
        public readonly array $notify,
        public readonly bool $correlationEnabled,
        public readonly int $correlationToleranceSeconds,
        public readonly bool $enabled = true,
        private readonly ?int $serviceTimeBudget = null,
    ) {
    }

    /**
     * A4-12: wall-clock seconds one service may spend: `service_time_budget`, else the fleet budget shared equally; null = none.
     * The equal share is floored at half the smallest `lock_ttl` (R-3): a big fleet must not give every service a deadline it cannot finish in.
     */
    public function serviceTimeBudgetSeconds(): ?int
    {
        if ($this->serviceTimeBudget !== null || $this->timeBudgetSeconds === null) {
            return $this->serviceTimeBudget;
        }
        $floor = intdiv(min(array_map(static fn (ServiceEntry $e): int => $e->config->schedule['lock_ttl'], $this->services) ?: [900]), 2);

        return max(1, $floor, intdiv($this->timeBudgetSeconds, max(1, count($this->services))));
    }

    /** @return array<string, mixed> a dump shows no webhook or token */
    public function __debugInfo(): array
    {
        return ['notify' => '[redacted]'] + get_object_vars($this);
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidConfig('Unknown configuration key: fleet.' . $key);
            }
        }
        $name = $config['fleet'] ?? null;
        if ($name === null) {
            throw new InvalidConfig('fleet is required');
        }
        if (!is_string($name) || preg_match('/^[A-Za-z0-9._-]+$/D', $name) !== 1) {
            throw new InvalidConfig('fleet must be a name of letters, digits, dot, dash and underscore');
        }

        $enabled = $config['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('enabled must be true or false');
        }
        $digest = $config['digest'] ?? 'per_service';
        if ($digest !== 'per_service' && $digest !== 'combined') {
            throw new InvalidConfig('digest must be per_service or combined');
        }
        $timezone = $config['timezone'] ?? 'UTC';
        if (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidConfig('timezone must be an IANA timezone');
        }
        $timeBudget = isset($config['time_budget']) ? self::seconds($config['time_budget'], 'time_budget') : null;
        if ($timeBudget === 0) {
            throw new InvalidConfig('time_budget must be longer than zero');
        }
        $serviceBudget = isset($config['service_time_budget']) ? self::seconds($config['service_time_budget'], 'service_time_budget') : null;
        if ($serviceBudget === 0) {
            throw new InvalidConfig('service_time_budget must be longer than zero');
        }
        $notify = self::notify(self::map($config['notify'] ?? [], 'notify'));
        if ($digest === 'combined' && !isset($notify['digest'])) {
            throw new InvalidConfig('digest combined needs notify.digest');
        }
        $corr = self::map($config['correlation'] ?? [], 'correlation');
        self::only($corr, ['enabled', 'tolerance'], 'correlation');
        if (!is_bool($corr['enabled'] ?? true)) {
            throw new InvalidConfig('correlation.enabled must be true or false');
        }

        $services = self::entries($config, $notify);
        foreach (['digest', 'notice'] as $kind) {
            // A fleet destination of `none` is dashboard-only for the whole fleet: some service must have a sink to be seen in.
            if (($notify[$kind]['driver'] ?? null) === 'none' && array_filter($services, static fn (ServiceEntry $e): bool => $e->config->observe->anyEnabled()) === []) {
                throw new InvalidConfig('notify.' . $kind . '.driver none needs at least one enabled observe sink');
            }
        }

        return new self(
            $name,
            $services,
            $digest,
            $timezone,
            $timeBudget,
            self::budget(self::map($config['budget'] ?? [], 'budget')),
            $notify,
            $corr['enabled'] ?? true,
            isset($corr['tolerance']) ? self::seconds($corr['tolerance'], 'correlation.tolerance') : 0,
            $enabled,
            $serviceBudget,
        );
    }

    /**
     * A fleet file (returns the fleet array) or a directory: `_fleet.php` holds the fleet keys, every other `*.php`
     * (name order) returns one service entry.
     */
    public static function fromPath(string $path): self
    {
        if (is_file($path)) {
            return self::fromArray(self::load($path));
        }
        if (!is_dir($path)) {
            throw new InvalidConfig('fleet config not found');
        }
        $fleetFile = rtrim($path, '/') . '/_fleet.php';
        if (!is_file($fleetFile)) {
            throw new InvalidConfig('fleet directory needs _fleet.php');
        }
        $files = glob(rtrim($path, '/') . '/*.php') ?: [];
        sort($files);
        $config = self::load($fleetFile);
        $config['services'] = [];
        foreach ($files as $file) {
            if ($file !== $fleetFile) {
                $config['services'][] = self::load($file);
            }
        }

        return self::fromArray($config);
    }

    /** @return list<ServiceEntry> */
    public function inOrder(): array
    {
        return FleetOrder::of($this->services);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $fleetNotify
     * @return list<ServiceEntry>
     */
    private static function entries(array $config, array $fleetNotify): array
    {
        $list = $config['services'] ?? null;
        if (!is_array($list) || $list === [] || !array_is_list($list)) {
            throw new InvalidConfig('services must be a non-empty list');
        }
        $defaults = self::map($config['defaults'] ?? [], 'defaults');
        $entries = [];
        /** @var array<string, int> $files Prometheus file per service, first index */
        $files = [];
        /** @var array<string, int> $index first index per service name */
        $index = [];
        foreach ($list as $i => $raw) {
            $label = "services[$i]" . (is_array($raw) && is_string($raw['service'] ?? null) ? ' (' . $raw['service'] . ')' : '');
            if (!is_array($raw)) {
                throw new InvalidConfig("$label: entry must be a map");
            }
            $priority = $raw['priority'] ?? 0;
            if (!is_int($priority)) {
                throw new InvalidConfig("$label: priority must be an integer");
            }
            $provides = $raw['provides'] ?? null;
            if ($provides !== null && (!is_array($provides) || !array_is_list($provides) || $provides === [] || array_filter($provides, static fn ($p): bool => is_string($p) && $p !== '') !== $provides)) {
                throw new InvalidConfig("$label: provides must be a list of non-empty strings");
            }
            unset($raw['priority'], $raw['provides']);
            $merged = self::merged($fleetNotify, $defaults, $raw, $config['observe'] ?? null);
            if (is_array($merged['observe']['prometheus'] ?? null) && array_key_exists('name', $merged['observe']['prometheus'])) {
                throw new InvalidConfig("$label: observe.prometheus.name must be unset in a fleet"); // each service gets its own file
            }
            try {
                $c = WatchConfig::fromArray($merged);
            } catch (InvalidConfig $e) {
                throw new InvalidConfig("$label: " . $e->getMessage());
            }
            if (str_contains($c->service, '/')) {
                throw new InvalidConfig("$label: service must not contain '/'");
            }
            if (isset($index[$c->stateKey])) {
                $first = $index[$c->stateKey];
                throw new InvalidConfig("duplicate service: services[$first] ($c->stateKey) and $label");
            }
            $index[$c->stateKey] = $i;
            if ($c->observe->prometheus['enabled']) {
                $file = $c->observe->prometheus['dir'] . '/' . $c->observe->prometheus['name'];
                if (isset($files[$file])) {
                    throw new InvalidConfig("$label: observe.prometheus would write the same file as services[{$files[$file]}] ({$entries[$files[$file]]->config->service})");
                }
                $files[$file] = $i;
            }
            $entry = new ServiceEntry($c, $priority, $provides);
            if ($entries !== []) {
                self::sameShared($entries[0], $entry, $label);
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Fleet defaults under `defaults` under the entry. A destination (`notify.digest`, `notify.notice`) is replaced
     * wholesale by the nearest layer that sets it: merging would graft a fleet Slack webhook onto a service's Telegram entry.
     *
     * @param array<string, mixed> $fleetNotify
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function merged(array $fleetNotify, array $defaults, array $raw, mixed $fleetObserve = null): array
    {
        $merged = array_replace_recursive($fleetNotify === [] ? [] : ['notify' => $fleetNotify], $defaults, $raw);
        // Spec 013: `observe` is shared, and the nearest layer that sets it replaces it as a whole (like a destination).
        foreach ([$raw, $defaults, ['observe' => $fleetObserve]] as $layer) {
            if (($layer['observe'] ?? null) !== null) {
                $merged['observe'] = $layer['observe'];
                break;
            }
        }
        if (!is_array($merged['notify'] ?? null)) {
            return $merged;
        }
        foreach (['digest', 'notice'] as $kind) {
            foreach ([$raw, $defaults, ['notify' => $fleetNotify]] as $layer) {
                if (is_array($layer['notify'] ?? null) && array_key_exists($kind, $layer['notify'])) {
                    $merged['notify'][$kind] = $layer['notify'][$kind];
                    break;
                }
            }
        }

        return $merged;
    }

    /**
     * The fleet's own destinations, validated like a service's; a kind that is absent stays absent.
     *
     * @param array<string, mixed> $n
     * @return array<string, array<string, mixed>>
     */
    private static function notify(array $n): array
    {
        self::only($n, ['digest', 'notice'], 'notify');
        $out = [];
        foreach (['digest', 'notice'] as $kind) {
            if (($n[$kind] ?? null) !== null) {
                $out[$kind] = WatchConfig::notifyEntry($n[$kind], 'notify.' . $kind);
            }
        }

        return $out;
    }

    /** Where the fleet's own messages go: the notice destination, else the digest one; for `digest`, the reverse. */
    public function destination(string $kind): ?Destination
    {
        $entry = $this->notify[$kind] ?? $this->notify[$kind === 'digest' ? 'notice' : 'digest'] ?? null;

        return $entry === null ? null : WatchConfig::destinationFrom($entry);
    }

    /** One StateStore and one Analyzer serve the fleet, so the sections they are built from must not differ. */
    private static function sameShared(ServiceEntry $first, ServiceEntry $entry, string $label): void
    {
        $of = "(differs from services[0] ({$first->name()}))";
        if ($first->config->state != $entry->config->state) {
            throw new InvalidConfig("$label: state must be identical in all services $of");
        }
        if ($first->config->analysis['api_key'] !== $entry->config->analysis['api_key']) {
            throw new InvalidConfig("$label: analysis.api_key must be identical in all services $of");
        }
    }

    /**
     * @param array<string, mixed> $b
     * @return array{daily_budget_usd: ?float, daily_token_limit: ?int, alert_percents: list<int>}
     */
    private static function budget(array $b): array
    {
        self::only($b, ['daily_budget_usd', 'daily_token_limit', 'alert_percents'], 'budget');
        $usd = $b['daily_budget_usd'] ?? null;
        if ($usd !== null && (!is_numeric($usd) || is_string($usd) || (float) $usd <= 0)) {
            throw new InvalidConfig('budget.daily_budget_usd must be a number greater than zero');
        }
        $tokens = $b['daily_token_limit'] ?? null;
        if ($tokens !== null && (!is_int($tokens) || $tokens <= 0)) {
            throw new InvalidConfig('budget.daily_token_limit must be null or a positive integer');
        }
        $percents = $b['alert_percents'] ?? [25, 50, 75, 90];
        $ok = is_array($percents) && array_is_list($percents) && $percents !== [];
        $prev = 0;
        foreach ($ok ? $percents : [] as $p) {
            if (!is_int($p) || $p <= $prev || $p > 99) {
                $ok = false;
                break;
            }
            $prev = $p;
        }
        if (!$ok) {
            throw new InvalidConfig('budget.alert_percents must be ascending integers from 1 to 99');
        }

        /** @var list<int> $percents */
        return ['daily_budget_usd' => $usd === null ? null : (float) $usd, 'daily_token_limit' => $tokens, 'alert_percents' => $percents];
    }

    private static function seconds(mixed $value, string $path): int
    {
        try {
            $interval = new \DateInterval(is_string($value) ? $value : '');
        } catch (\Exception) {
            throw new InvalidConfig("$path must be an ISO-8601 duration");
        }

        return (new \DateTimeImmutable('@0'))->add($interval)->getTimestamp();
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new InvalidConfig("$path must be a map");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string> $allowed
     */
    private static function only(array $section, array $allowed, string $path): void
    {
        foreach (array_keys($section) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidConfig("Unknown configuration key: fleet.$path.$key");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function load(string $file): array
    {
        $values = (static fn (string $__path): mixed => require $__path)($file);
        if (!is_array($values)) {
            throw new InvalidConfig(basename($file) . ' must return an array');
        }

        return $values;
    }
}
