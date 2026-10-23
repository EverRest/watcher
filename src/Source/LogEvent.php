<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use Watcher\Level;

/** One normalised entry from a source. Lives only inside a run; `fields` never leaves the service. */
final class LogEvent
{
    public const MAX_FRAMES = 5;

    /** @var list<string> */
    public readonly array $frames;

    /**
     * @param list<string>                 $frames
     * @param array<string, scalar|null>   $fields
     * @param array<string, string>        $labels
     */
    public function __construct(
        public readonly DateTimeImmutable $time,
        public readonly Level $level,
        public readonly string $message,
        public readonly ?string $errorType,
        array $frames,
        public readonly array $fields,
        public readonly array $labels,
    ) {
        $this->frames = array_slice(array_values($frames), 0, self::MAX_FRAMES);
    }
}
