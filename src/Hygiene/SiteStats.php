<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use DateTimeImmutable;

/** What one call site accumulated while the window streamed by: integers, a level, two times. Never a value. */
final class SiteStats
{
    public int $lines = 0;

    /** Lines holding at least one personal-data pattern. */
    public int $anyHit = 0;

    /** @var array<string, int> category => lines holding it */
    public array $cats = [];

    public int $long = 0;

    public int $trace = 0;

    public int $mismatch = 0;

    public function __construct(
        public readonly string $key,
        public readonly string $id,
        public readonly string $kind,
        public readonly string $fingerprint,
        public readonly string $level,
        public DateTimeImmutable $first,
        public DateTimeImmutable $last,
    ) {
    }

    /** @return array{key: string, id: string, kind: string, level: string, lines: int, any_hit: int, cats: array<string, int>, long: int, trace: int, mismatch: int, first: string, last: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'id' => $this->id, 'kind' => $this->kind, 'level' => $this->level, 'lines' => $this->lines,
            'any_hit' => $this->anyHit, 'cats' => $this->cats, 'long' => $this->long, 'trace' => $this->trace, 'mismatch' => $this->mismatch,
            'first' => $this->first->format(DATE_ATOM), 'last' => $this->last->format(DATE_ATOM),
        ];
    }
}
