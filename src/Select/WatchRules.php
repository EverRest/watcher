<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Reduce\ProblemGroup;
use Watcher\Source\LogEvent;

/** Named rules that force a group to be analysed. Only rule NAMES are ever recorded, never matched values. */
final class WatchRules
{
    /** @var array<string, Matcher> */
    private array $rules = [];

    /** @param array<string, array<string, mixed>> $rules WatchConfig::$watchRules */
    public function __construct(array $rules)
    {
        foreach ($rules as $name => $rule) {
            $this->rules[(string) $name] = MatcherParser::parse($rule);
        }
    }

    /** @return list<string> names of template/error_type rules matching the group */
    public function namesMatchingGroup(ProblemGroup $g): array
    {
        return array_keys(array_filter($this->rules, static fn (Matcher $m) => $m->matchesGroup($g)));
    }

    /** @return list<string> names of field rules matching the event; the pipeline collects these per fingerprint */
    public function namesMatchingEvent(LogEvent $e): array
    {
        return array_keys(array_filter($this->rules, static fn (Matcher $m) => $m->matchesEvent($e)));
    }

    /**
     * Never removes a group.
     *
     * @param list<ProblemGroup>        $groups
     * @param array<string, list<string>> $eventMatchesByFingerprint rule names matched at event time
     * @return list<ProblemGroup>
     */
    public function apply(array $groups, array $eventMatchesByFingerprint): array
    {
        $out = [];
        foreach ($groups as $g) {
            $names = array_values(array_unique([
                ...$this->namesMatchingGroup($g),
                ...array_filter($eventMatchesByFingerprint[$g->fingerprint] ?? [], fn (string $n) => isset($this->rules[$n])),
            ]));
            $out[] = $names === [] ? $g : new ProblemGroup(
                $g->fingerprint,
                $g->template,
                $g->level,
                $g->errorType,
                $g->frames,
                $g->count,
                $g->firstSeen,
                $g->lastSeen,
                $g->previousCount,
                $g->status,
                $names,
            );
        }

        return $out;
    }
}
