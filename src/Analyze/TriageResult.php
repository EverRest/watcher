<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use InvalidArgumentException;

final class TriageResult
{
    public const VERDICTS = ['noise', 'known', 'interesting'];

    /**
     * @param array<string, string> $verdicts fingerprint => noise|known|interesting
     */
    public function __construct(
        public readonly array $verdicts,
        public readonly Usage $usage,
    ) {
        foreach ($verdicts as $v) {
            if (!in_array($v, self::VERDICTS, true)) {
                throw new InvalidArgumentException('Unknown triage verdict');
            }
        }
    }
}
