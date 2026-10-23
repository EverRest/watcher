<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Reduce\ProblemGroup;
use Watcher\Source\LogEvent;

/**
 * One explicit match form. template / error_type match a ProblemGroup (matchesGroup); a field matcher matches
 * only LogEvent::$fields (matchesEvent) because field values never leave the service and are not on the group:
 * the pipeline records, per fingerprint, which field matchers matched any event of that group.
 * Regexes were validated at config time; a failing regex is a non-match, never an exception.
 */
final class Matcher
{
    public const TEMPLATE = 'template';
    public const ERROR_TYPE = 'error_type';
    public const FIELD = 'field';

    public function __construct(
        public readonly string $kind,
        public readonly string $pattern,   // regex, or the `equals` literal for an equals field matcher
        public readonly ?string $field = null,
        public readonly bool $isRegex = true,
    ) {
    }

    public function isFieldMatcher(): bool
    {
        return $this->kind === self::FIELD;
    }

    public function matchesGroup(ProblemGroup $g): bool
    {
        return match ($this->kind) {
            self::TEMPLATE => $g->template !== null && $this->regex($g->template),
            self::ERROR_TYPE => $g->errorType !== null && $this->regex($g->errorType),
            default => false,
        };
    }

    public function matchesEvent(LogEvent $e): bool
    {
        if ($this->kind !== self::FIELD || $this->field === null || !array_key_exists($this->field, $e->fields)) {
            return false;
        }
        $value = $e->fields[$this->field];
        if ($value === null) {
            return false;
        }
        $s = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $this->isRegex ? $this->regex($s) : $s === $this->pattern;
    }

    private function regex(string $subject): bool
    {
        return @preg_match($this->pattern, $subject) === 1;
    }
}
