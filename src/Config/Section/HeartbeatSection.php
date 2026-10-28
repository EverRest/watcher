<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;

/** Validates the `heartbeat` section. */
final class HeartbeatSection
{
    /**
     * @param array<string, mixed> $h
     * @return array{stale_after: int, weekly_summary: bool, enabled: bool}
     */
    public static function heartbeat(array $h, \DateInterval $window): array
    {
        SectionFields::only($h, ['stale_after', 'weekly_summary', 'enabled'], 'heartbeat');
        $on = SectionFields::flag($h, 'enabled', 'heartbeat.enabled');
        $weekly = $h['weekly_summary'] ?? false;
        if (!is_bool($weekly)) {
            throw new InvalidConfig('heartbeat.weekly_summary must be true or false');
        }
        $epoch = new \DateTimeImmutable('@0');
        if (array_key_exists('stale_after', $h)) {
            $given = $h['stale_after'];
            try {
                $seconds = is_string($given) ? $epoch->add(new \DateInterval($given))->getTimestamp() : 0;
            } catch (\Exception) {
                $seconds = 0;
            }
            if ($seconds < 1) {
                throw new InvalidConfig('heartbeat.stale_after must be an ISO-8601 duration longer than zero');
            }
        } else {
            $seconds = (int) ceil(1.5 * $epoch->add($window)->getTimestamp());
        }

        return ['stale_after' => $seconds, 'weekly_summary' => $weekly, 'enabled' => $on];
    }
}
