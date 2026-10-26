<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/** The outcome of one streaming pass: counts per site and per format class. */
final class ScanResult
{
    /**
     * @param list<SiteStats> $sites most lines first
     * @param array<string, int> $formats class => events
     */
    public function __construct(
        public readonly int $events,
        public readonly int $otherEvents,
        public readonly array $sites,
        public readonly array $formats,
        public readonly int $maxLength = 0,
        public readonly int $unscannable = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'events' => $this->events,
            'other_events' => $this->otherEvents,
            'sites' => array_map(static fn (SiteStats $s): array => $s->toArray(), $this->sites),
            'formats' => $this->formats,
            'max_length' => $this->maxLength,
            'unscannable' => $this->unscannable,
        ];
    }
}
