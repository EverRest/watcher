<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use Watcher\Level;

/**
 * Event type and reason -> level, decided in code (Principle IV). Normal is Info; Warning is Warning, raised to Error for a
 * critical reason, and `Unhealthy` (a failing probe) is raised only when it repeated or the pod has restarted (Owner decision 3).
 */
final class ReasonLevels
{
    private const PROBE = 'Unhealthy';

    /** @param list<string> $critical */
    public function __construct(private readonly array $critical, private readonly int $minRepeatForProbeFailures)
    {
    }

    public function level(string $type, string $reason, int $occurrences, bool $restarted): Level
    {
        if ($type === 'Warning') {
            if (in_array($reason, $this->critical, true)) {
                return Level::Error;
            }
            if ($reason === self::PROBE && ($restarted || $occurrences >= $this->minRepeatForProbeFailures)) {
                return Level::Error;
            }

            return Level::Warning;
        }

        return Level::Info; // Normal, and a type the API should never send: not a problem, and dropped by the minimum level
    }
}
