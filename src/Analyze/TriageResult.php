<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use InvalidArgumentException;

final class TriageResult
{
    public const VERDICTS = ['noise', 'known', 'interesting'];

    /**
     * @param array<string, string> $verdicts fingerprint => noise|known|interesting
     * @param array<string, string> $why      fingerprint => short reason (≤ 100 chars)
     */
    public function __construct(
        public readonly array $verdicts,
        public readonly array $why,
        public readonly Usage $usage,
    ) {
        foreach ($verdicts as $v) {
            if (!in_array($v, self::VERDICTS, true)) {
                throw new InvalidArgumentException('Unknown triage verdict');
            }
        }
        foreach ($why as $w) {
            if (mb_strlen($w) > 100) {
                throw new InvalidArgumentException('why exceeds 100 chars');
            }
        }
    }
}
