<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;

/** Validates `watch_rules` and `mute` (matchers and their reserved flags). */
final class WatchRulesSection
{
    /** @return array<string, array<string, mixed>> */
    public static function watchRules(mixed $rules): array
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
    public static function mute(mixed $entries): array
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
    public static function matcher(mixed $m, string $path, bool $allowFingerprint): void
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
        SectionFields::only($m, ['template', 'error_type', 'field', 'equals', 'matches', ...($allowFingerprint ? ['fingerprint', 'until'] : [])], $path);
        if ($kind === 'template' || $kind === 'error_type') {
            SectionFields::regex($m[$kind], $path . '.' . $kind);
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
                SectionFields::regex($m['matches'], $path . '.matches');
            }
        }
    }
}
