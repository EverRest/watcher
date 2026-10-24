<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Privacy\RedactedGroup;

/**
 * One finding per origin group (contracts/correlated-finding.md): service names, fingerprints, counts, levels, times and
 * the origin's already-redacted template. Never a consumer template, a raw line or a field value.
 */
final class CorrelatedFinding
{
    /**
     * @param list<array{service: string, groups: int, count: int, dependency: string}> $consumers sorted by service
     */
    public function __construct(
        public readonly string $fleet,
        public readonly string $originService,
        public readonly RedactedGroup $origin,
        public readonly array $consumers,
        public readonly DateTimeImmutable $windowStart,
        public readonly DateTimeImmutable $windowEnd,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $o = $this->origin;

        return [
            'fleet' => $this->fleet,
            'hypothesis' => true,
            'origin' => [
                'service' => $this->originService,
                'fingerprint' => $o->fingerprint,
                'level' => strtolower($o->level->name),
                'status' => $o->status->value,
                'count' => $o->count,
                'previous_count' => $o->previousCount,
                'template' => $o->template,
                'first_seen' => self::utc($o->firstSeen),
                'last_seen' => self::utc($o->lastSeen),
            ],
            'consumers' => $this->consumers,
            'window' => ['start' => self::utc($this->windowStart), 'end' => self::utc($this->windowEnd)],
            'basis' => ['host_reference', 'window_overlap', 'origin_' . $o->status->value],
        ];
    }

    private static function utc(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
