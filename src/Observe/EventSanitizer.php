<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Throwable;
use Watcher\Privacy\Redactor;

/**
 * Per-sink gate in front of every sink (Principle I): only allowlisted fields leave, enumerations and patterns are
 * enforced (a value that fails becomes null, never passes through), text is present only with include_text and only
 * after the redactor's scan, and text that cannot be scanned is dropped.
 */
final class EventSanitizer
{
    public function __construct(private readonly Redactor $redactor)
    {
    }

    /** @return array<string, mixed> */
    public function sanitize(Event $event, bool $includeText): array
    {
        $type = $event->type;
        $out = [];
        foreach ($event->toArray($includeText) as $key => $value) {
            if (isset(EventSchema::TEXT[$type][$key])) {
                $text = $value === null ? null : $this->text($key, $value, EventSchema::TEXT[$type][$key]);
                if ($value === null && $key === 'error_type') {
                    $out[$key] = null;
                } elseif ($text !== null) {
                    $out[$key] = $text;
                }

                continue;
            }
            $out[$key] = $this->value($type, $key, $value);
        }

        return $out;
    }

    private function value(string $type, string $key, mixed $value): mixed
    {
        if (isset(EventSchema::COUNTS[$key])) {
            return $this->counts(EventSchema::COUNTS[$key], $value);
        }
        if (isset(EventSchema::LIST_PATTERNS[$key])) {
            return $this->list($key, $value);
        }
        if (is_string($value)) {
            $enum = EventSchema::enum($type, $key);
            if ($enum !== null) {
                return in_array($value, $enum, true) ? $value : null;
            }
            $pattern = EventSchema::PATTERNS[$key] ?? null;

            return $pattern !== null && preg_match($pattern, $value) === 1 ? $value : null;
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : null,
            default => null,
        };
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, int>|null
     */
    private function counts(array $keys, mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = is_int($value[$k] ?? null) && $value[$k] >= 0 ? $value[$k] : 0;
        }

        return $out;
    }

    /** @return list<string> */
    private function list(string $key, mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_string($entry) && preg_match(EventSchema::LIST_PATTERNS[$key], $entry) === 1) {
                $out[] = $entry;
            }
        }

        return array_slice($out, 0, EventSchema::LIST_MAX[$key]);
    }

    /** @return string|list<string>|null null = drop the field */
    private function text(string $key, mixed $value, int $cap): string|array|null
    {
        if ($key === 'related') {
            $out = [];
            foreach (is_array($value) ? $value : [] as $entry) {
                // an exact fingerprint is a hash prefix, not data (as in Redactor::redactFinding)
                $clean = is_string($entry) && preg_match('/^[0-9a-f]{12}$/D', $entry) === 1 ? $entry : $this->scan($entry, $cap);
                if ($clean !== null) {
                    $out[] = $clean;
                }
            }

            return array_slice($out, 0, EventSchema::RELATED_MAX);
        }

        return $this->scan($value, $cap, $key === 'error_type');
    }

    private function scan(mixed $value, int $cap, bool $maskNumbers = false): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            $clean = $this->redactor->redactText($value, !$maskNumbers);
        } catch (Throwable) {
            return null;
        }

        return $clean === null ? null : mb_substr($clean, 0, $cap);
    }
}
