<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use Watcher\Source\LogEvent;

/**
 * Stable problem id: 12 hex of sha256(level|errorType|frame0|tokenCount|firstTwoWords) over tokens
 * from the Normalizer output (see {@see TemplateMiner::tokens()}). Deliberately independent of the
 * mined template and of volume, so a problem keeps its id at 1 event or 5, and when messages differ
 * only by a name after word two.
 *
 * Bump {@see self::FPV} when Normalizer rules change; stored snapshots with a different version are
 * not compared (baseline reset).
 */
final class Fingerprinter
{
    /** Snapshot field `fpv`: increment when masked text or tokenisation changes. */
    public const FPV = 1;

    public function __construct(private readonly Normalizer $normalizer)
    {
    }

    /** @param array<mixed>|null $snapshot */
    public static function snapshotVersion(?array $snapshot): int
    {
        if ($snapshot === null) {
            return 0;
        }
        $fpv = $snapshot['fpv'] ?? 0;

        return is_int($fpv) ? $fpv : (is_numeric($fpv) ? (int) $fpv : 0);
    }

    /** @param array<mixed>|null $snapshot */
    public static function snapshotUsable(?array $snapshot): bool
    {
        return $snapshot !== null && self::snapshotVersion($snapshot) === self::FPV;
    }

    public function of(LogEvent $event): string
    {
        return $this->fingerprint($event, TemplateMiner::tokens($this->normalizer, $event));
    }

    /** @param list<string> $tokens masked message tokens, from TemplateMiner::tokens() */
    public function fingerprint(LogEvent $event, array $tokens): string
    {
        $firstTwo = mb_strtolower(implode(' ', array_slice($tokens, 0, 2)));

        return substr(hash('sha256', implode('|', [
            $event->level->value,
            $event->errorType ?? '',
            $event->frames[0] ?? '',
            count($tokens),
            $firstTwo,
        ])), 0, 12);
    }
}
