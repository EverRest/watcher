<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Alert\ImmediateAlerts;
use Watcher\Config\InvalidConfig;
use Watcher\Level;

/** Validates the `alerts` section (immediate alerts on a log line match). */
final class AlertsSection
{
    /**
     * @param array<string, mixed> $a
     *
     * @return array{window: int, fields: list<string>, immediate: array<string, array{level: Level, message: ?string, action: ?string}>}
     */
    public static function alerts(array $a): array
    {
        SectionFields::only($a, ['enabled', 'window', 'immediate', 'fields'], 'alerts');
        $window = $a['window'] ?? 'PT15M';
        $seconds = is_string($window) ? SectionFields::seconds($window) : null;
        if ($seconds === null || $seconds < 1) {
            throw new InvalidConfig('alerts.window must be an ISO-8601 duration longer than zero');
        }
        // Context values are off unless listed here: docs/privacy.md says field values never leave the service by default.
        $fields = $a['fields'] ?? [];
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new InvalidConfig('alerts.fields must be a list of field names');
        }
        foreach ($fields as $name) {
            if (!is_string($name) || !in_array($name, ImmediateAlerts::FIELDS, true)) {
                throw new InvalidConfig('alerts.fields may only name ' . implode(', ', ImmediateAlerts::FIELDS));
            }
        }
        $rules = $a['immediate'] ?? [];
        if (!is_array($rules) || ($rules !== [] && array_is_list($rules))) {
            throw new InvalidConfig('alerts.immediate must be a map of name => rule');
        }
        $immediate = [];
        foreach ($rules as $name => $rule) {
            $path = 'alerts.immediate.' . $name;
            if (!is_array($rule)) {
                throw new InvalidConfig($path . ' must be a map');
            }
            SectionFields::only($rule, ['level', 'message', 'action'], $path);
            foreach (['message', 'action'] as $key) {
                if (isset($rule[$key]) && (!is_string($rule[$key]) || $rule[$key] === '')) {
                    throw new InvalidConfig($path . '.' . $key . ' must be a non-empty string');
                }
            }
            $immediate[$name] = [
                'level' => SectionFields::level($rule, 'level', 'critical', $path . '.level'),
                'message' => $rule['message'] ?? null,
                'action' => $rule['action'] ?? null,
            ];
        }

        // Switched on with no rule, nothing could ever match: say so at load instead of running silently.
        if (($a['enabled'] ?? false) === true && $immediate === []) {
            throw new InvalidConfig('alerts.enabled needs at least one alerts.immediate rule');
        }

        return ['window' => $seconds, 'fields' => $fields, 'immediate' => $immediate];
    }
}
