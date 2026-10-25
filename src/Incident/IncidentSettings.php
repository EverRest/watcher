<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Watcher\Analyze\Severity;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Notify\Destination;
use Watcher\Observe\ObserveSettings;

final class IncidentSettings
{
    /**
     * @param list<KnownIssue>     $knownIssues
     * @param list<Route>          $routes
     * @param array<string, mixed>|null $draftsDestination
     * @param array<string, mixed>|null $reportWeeklyDestination
     */
    public function __construct(
        public readonly bool $knownIssuesEnabled,
        public readonly array $knownIssues,
        public readonly bool $draftsEnabled,
        public readonly Severity $draftsMinSeverity,
        public readonly int $draftsMaxPerRun,
        public readonly ?array $draftsDestination,
        public readonly bool $routesEnabled,
        public readonly array $routes,
        public readonly bool $reportWeeklyEnabled,
        public readonly ?array $reportWeeklyDestination,
        public readonly int $reportWeeklyTop,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $notify
     */
    public static function fromArray(array $config, array $notify, ObserveSettings $observe): self
    {
        $known = self::knownIssues($config['known_issues'] ?? []);
        $drafts = self::drafts($config['drafts'] ?? [], $notify);
        $routes = self::routes($notify);
        $report = self::report($config['report'] ?? [], $observe);

        return new self(
            $known['enabled'],
            $known['entries'],
            $drafts['enabled'],
            $drafts['min_severity'],
            $drafts['max_per_run'],
            $drafts['destination'],
            $routes['enabled'],
            $routes['routes'],
            $report['enabled'],
            $report['destination'],
            $report['top'],
        );
    }

    public function draftsDestination(WatchConfig $config): Destination
    {
        return $this->draftsDestination !== null
            ? WatchConfig::destinationFrom($this->draftsDestination)
            : $config->destination('notice');
    }

    /** @return array{enabled: bool, entries: list<KnownIssue>} */
    private static function knownIssues(mixed $raw): array
    {
        if ($raw === []) {
            return ['enabled' => true, 'entries' => []];
        }
        if (is_array($raw) && array_is_list($raw)) {
            $raw = ['enabled' => true, 'entries' => $raw];
        }
        if (!is_array($raw)) {
            throw new InvalidConfig('known_issues must be a list or map');
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['enabled', 'entries'], true)) {
                throw new InvalidConfig('Unknown configuration key: known_issues.' . $key);
            }
        }
        $enabled = $raw['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('known_issues.enabled must be true or false');
        }
        $entries = $raw['entries'] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InvalidConfig('known_issues.entries must be a list');
        }
        if (count($entries) > 200) {
            throw new InvalidConfig('known_issues.entries must have at most 200 entries');
        }
        $parsed = [];
        $seen = [];
        foreach ($entries as $i => $row) {
            if (!is_array($row)) {
                throw new InvalidConfig('known_issues.entries[' . $i . '] must be a map');
            }
            $fp = $row['fingerprint'] ?? null;
            if (!is_string($fp) || preg_match('/^[0-9a-f]{12,64}$/D', $fp) !== 1) {
                throw new InvalidConfig('known_issues.entries[' . $i . '].fingerprint must be 12 to 64 hex characters');
            }
            $ticket = $row['ticket'] ?? null;
            if (!is_string($ticket) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._#-]{0,39}$/D', $ticket) !== 1) {
                throw new InvalidConfig('known_issues.entries[' . $i . '].ticket invalid');
            }
            $status = $row['status'] ?? 'open';
            if ($status !== 'open' && $status !== 'resolved') {
                throw new InvalidConfig('known_issues.entries[' . $i . '].status must be open or resolved');
            }
            $until = $row['until'] ?? null;
            if ($until !== null && (!is_string($until) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) !== 1)) {
                throw new InvalidConfig('known_issues.entries[' . $i . '].until must be a YYYY-MM-DD date or null');
            }
            $pair = $fp . "\0" . $ticket;
            if (isset($seen[$pair])) {
                throw new InvalidConfig('known_issues.entries duplicate fingerprint and ticket');
            }
            $seen[$pair] = true;
            $parsed[] = new KnownIssue($fp, $ticket, $status === 'resolved', $until);
        }

        return ['enabled' => $enabled, 'entries' => $parsed];
    }

    /**
     * @param array<string, mixed> $notify
     *
     * @return array{enabled: bool, min_severity: Severity, max_per_run: int, destination: array<string, mixed>|null}
     */
    private static function drafts(mixed $raw, array $notify): array
    {
        if ($raw === [] || $raw === null) {
            return ['enabled' => false, 'min_severity' => Severity::Error, 'max_per_run' => 5, 'destination' => null];
        }
        if (!is_array($raw)) {
            throw new InvalidConfig('drafts must be a map');
        }
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['enabled', 'min_severity', 'max_per_run', 'destination'], true)) {
                throw new InvalidConfig('Unknown configuration key: drafts.' . $key);
            }
        }
        $enabled = $raw['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('drafts.enabled must be true or false');
        }
        $minSev = Severity::tryFrom((string) ($raw['min_severity'] ?? 'error')) ?? throw new InvalidConfig('drafts.min_severity must be a severity name');
        $max = $raw['max_per_run'] ?? 5;
        if (!is_int($max) || $max < 1 || $max > 20) {
            throw new InvalidConfig('drafts.max_per_run must be an integer from 1 to 20');
        }
        $dest = isset($raw['destination']) ? WatchConfig::notifyEntry($raw['destination'], 'drafts.destination') : null;
        if ($enabled && $dest === null && ($notify['notice']['driver'] ?? 'slack') === 'none') {
            throw new InvalidConfig('drafts.destination required');
        }

        return ['enabled' => $enabled, 'min_severity' => $minSev, 'max_per_run' => $max, 'destination' => $dest];
    }

    /**
     * @param array<string, mixed> $notify
     *
     * @return array{enabled: bool, routes: list<Route>}
     */
    private static function routes(array $notify): array
    {
        $enabled = $notify['routes_enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('notify.routes_enabled must be true or false');
        }
        $raw = $notify['routes'] ?? [];
        if ($raw === []) {
            return ['enabled' => $enabled, 'routes' => []];
        }
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 5) {
            throw new InvalidConfig('notify.routes must be a list of at most 5 entries');
        }
        $digestDest = WatchConfig::destinationFrom(WatchConfig::notifyEntry($notify['digest'] ?? [], 'notify.digest'));
        $parsed = [];
        $names = [];
        $used = [];
        foreach ($raw as $i => $row) {
            $path = 'notify.routes[' . $i . ']';
            if (!is_array($row)) {
                throw new InvalidConfig($path . ' must be a map');
            }
            $name = $row['name'] ?? '';
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', (string) $name) !== 1) {
                throw new InvalidConfig($path . '.name invalid');
            }
            if (isset($names[$name])) {
                throw new InvalidConfig($path . '.name must be unique');
            }
            $names[$name] = true;
            $isLast = $i === count($raw) - 1;
            $hasSev = array_key_exists('severity', $row);
            if (!$isLast && !$hasSev) {
                throw new InvalidConfig('notify.routes[' . $i . '].severity is required except on the last route');
            }
            if ($isLast && $hasSev) {
                throw new InvalidConfig('notify.routes needs a last catch-all route (no severity)');
            }
            $severities = null;
            if ($hasSev) {
                $sev = $row['severity'];
                if (!is_array($sev) || $sev === [] || !array_is_list($sev)) {
                    throw new InvalidConfig($path . '.severity must be a non-empty list');
                }
                $severities = [];
                foreach ($sev as $s) {
                    $ps = Severity::tryFrom((string) $s) ?? throw new InvalidConfig($path . '.severity invalid');
                    if (isset($used[$ps->value])) {
                        throw new InvalidConfig('notify.routes[' . $i . '].severity overlaps notify.routes[' . $used[$ps->value] . ']');
                    }
                    $used[$ps->value] = $i;
                    $severities[] = $ps;
                }
            }
            $dest = null;
            if (isset($row['to'])) {
                $dest = WatchConfig::destinationFrom(WatchConfig::notifyEntry($row['to'], $path . '.to'));
                if ($dest->sameAs($digestDest)) {
                    throw new InvalidConfig('notify.routes[' . $i . '].to must differ from notify.digest');
                }
                foreach ($parsed as $prev) {
                    if ($prev->destination !== null && $dest->sameAs($prev->destination)) {
                        throw new InvalidConfig($path . '.to must differ from other routes');
                    }
                }
            }
            $parsed[] = new Route($name, $severities, $dest);
        }

        return ['enabled' => $enabled, 'routes' => $parsed];
    }

    /**
     * @return array{enabled: bool, destination: array<string, mixed>|null, top: int}
     */
    private static function report(mixed $raw, ObserveSettings $observe): array
    {
        $weekly = is_array($raw) ? ($raw['weekly'] ?? []) : [];
        if ($weekly === []) {
            return ['enabled' => false, 'destination' => null, 'top' => 5];
        }
        $enabled = $weekly['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('report.weekly.enabled must be true or false');
        }
        if ($enabled && (!$observe->file['enabled'] || $observe->file['retention_days'] < 8)) {
            throw new InvalidConfig('report.weekly needs observe.file enabled with retention_days >= 8');
        }
        $top = $weekly['top'] ?? 5;
        if (!is_int($top) || $top < 3 || $top > 10) {
            throw new InvalidConfig('report.weekly.top must be an integer from 3 to 10');
        }
        $dest = isset($weekly['destination']) ? WatchConfig::notifyEntry($weekly['destination'], 'report.weekly.destination') : null;

        return ['enabled' => $enabled, 'destination' => $dest, 'top' => $top];
    }
}
