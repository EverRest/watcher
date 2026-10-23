<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Select\Candidate;

final class Budget
{
    /** UTF-8 bytes / 3, rounded up: deliberately conservative. */
    public static function estimateTokens(string $payload): int
    {
        return (int) ceil(strlen($payload) / 3);
    }

    /**
     * Drops the lowest-priority candidates (later ones first on ties) until the serialized payload fits.
     *
     * @param list<Candidate> $candidates
     * @param callable(list<Candidate>): string $serialize
     */
    public static function truncate(array $candidates, int $maxInputTokens, callable $serialize): TruncationResult
    {
        $kept = $candidates;
        while ($kept !== [] && self::estimateTokens($serialize($kept)) > $maxInputTokens) {
            $drop = 0;
            foreach ($kept as $i => $c) {
                if ($c->priority <= $kept[$drop]->priority) {
                    $drop = $i;
                }
            }
            array_splice($kept, $drop, 1);
        }

        return new TruncationResult($kept, count($candidates) - count($kept));
    }
}
