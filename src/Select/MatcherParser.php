<?php

declare(strict_types=1);

namespace Watcher\Select;

/** Turns a validated config entry (WatchConfig shapes) into a Matcher. */
final class MatcherParser
{
    /** @param array<string, mixed> $entry exactly one of template | error_type | field(+equals|matches) */
    public static function parse(array $entry): Matcher
    {
        if (isset($entry['template'])) {
            return new Matcher(Matcher::TEMPLATE, (string) $entry['template']);
        }
        if (isset($entry['error_type'])) {
            return new Matcher(Matcher::ERROR_TYPE, (string) $entry['error_type']);
        }
        $field = (string) ($entry['field'] ?? '');
        if (isset($entry['matches'])) {
            return new Matcher(Matcher::FIELD, (string) $entry['matches'], $field);
        }

        return new Matcher(Matcher::FIELD, is_bool($entry['equals'] ?? null) ? ($entry['equals'] ? 'true' : 'false') : (string) ($entry['equals'] ?? ''), $field, false);
    }
}
