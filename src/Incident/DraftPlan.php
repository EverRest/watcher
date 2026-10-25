<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Watcher\Analyze\Finding;
use Watcher\Analyze\Severity;

final class DraftPlan
{
    /**
     * @param list<Finding>       $findings
     * @param array<string, int>  $countsByFingerprint
     * @param array<string, true> $alreadySent
     *
     * @return array{selected: list<Finding>, skipped: int}
     */
    public static function select(array $findings, Severity $minSeverity, int $maxPerRun, array $countsByFingerprint, array $alreadySent = []): array
    {
        $eligible = [];
        foreach ($findings as $f) {
            if (self::rank($f->severity) < self::rank($minSeverity) || isset($alreadySent[$f->fingerprints[0]])) {
                continue;
            }
            $eligible[] = $f;
        }
        usort($eligible, static function (Finding $a, Finding $b) use ($countsByFingerprint): int {
            $ca = $countsByFingerprint[$a->fingerprints[0]] ?? 0;
            $cb = $countsByFingerprint[$b->fingerprints[0]] ?? 0;

            return [self::rank($b->severity), $cb, $a->fingerprints[0]] <=> [self::rank($a->severity), $ca, $b->fingerprints[0]];
        });
        $selected = array_slice($eligible, 0, max(0, $maxPerRun));

        return ['selected' => $selected, 'skipped' => max(0, count($eligible) - count($selected))];
    }

    private static function rank(Severity $s): int
    {
        return match ($s) {
            Severity::Critical => 4,
            Severity::Error => 3,
            Severity::Warn => 2,
            Severity::Info => 1,
        };
    }
}
