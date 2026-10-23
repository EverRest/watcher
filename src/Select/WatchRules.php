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

    /**
     * Regexes (no delimiters, `(?i)` prefix when case-insensitive) that are safe to hand to the log backend so a
     * matching line below the read level is still read (FR-036). A template regex runs on the MASKED template while
     * the backend sees raw lines, so only a plain literal is pushed; an error_type regex only if RE2 can run it;
     * field rules never (values exist only after parsing). Everything else is still matched locally on what is read.
     * The text comes from config, not from log data.
     *
     * @return list<string>
     */
    public function backendPatterns(): array
    {
        $out = [];
        foreach ($this->rules as $m) {
            $parts = $m->kind === Matcher::FIELD ? null : self::split($m->pattern);
            if ($parts === null || !in_array($parts[1], ['', 'i'], true) || $parts[0] === '') {
                continue;
            }
            $body = $parts[0];
            if ($m->kind === Matcher::TEMPLATE) {
                if (preg_match('/[\\\\.^$|?*+()\[\]{}]/', $body) === 1) {
                    continue;
                }
                $body = preg_quote($body);
            } elseif (!Re2Compat::supports($body)) {
                continue;
            }
            $out[] = ($parts[1] === 'i' ? '(?i)' : '') . $body;
        }

        return array_values(array_unique($out));
    }

    /** @return array{string, string}|null body and flags of `/body/flags` */
    private static function split(string $re): ?array
    {
        $d = $re[0] ?? '';
        $end = strrpos($re, $d);
        if ($d === '' || ctype_alnum($d) || $d === '\\' || $end === false || $end === 0) {
            return null;
        }

        return [substr($re, 1, $end - 1), substr($re, $end + 1)];
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
