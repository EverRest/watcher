<?php

declare(strict_types=1);

namespace Watcher\Privacy;

use DateTimeImmutable;
use JsonSerializable;
use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;

/** The only shape the analyzer and renderer accept: an allowlist, no raw message, fields or labels. */
final class RedactedGroup implements JsonSerializable
{
    public const MAX_FRAMES = 5;

    /** @var list<string> */
    public readonly array $frames;

    /**
     * @param list<string> $frames
     * @param list<string> $watchMatches
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly ?string $template,
        public readonly Level $level,
        public readonly ?string $errorType,
        array $frames,
        public readonly int $count,
        public readonly ?int $previousCount,
        public readonly GroupStatusLabel $status,
        public readonly array $watchMatches,
        public readonly DateTimeImmutable $firstSeen,
        public readonly DateTimeImmutable $lastSeen,
    ) {
        $this->frames = array_slice($frames, 0, self::MAX_FRAMES);
    }

    /** Layer 1 of Principle I: copies exactly the allowlisted fields and nothing else. */
    public static function fromProblemGroup(ProblemGroup $g): self
    {
        return new self(
            $g->fingerprint,
            $g->template,
            $g->level,
            $g->errorType,
            $g->frames,
            $g->count,
            $g->previousCount,
            match ($g->status) {
                GroupStatus::New => GroupStatusLabel::New,
                GroupStatus::Grown => GroupStatusLabel::Grown,
                GroupStatus::Unchanged => GroupStatusLabel::Unchanged,
                GroupStatus::Expected => GroupStatusLabel::Expected,
            },
            $g->watchMatches,
            $g->firstSeen,
            $g->lastSeen,
        );
    }

    /** @return array<string, mixed> short stable keys; this is what is serialised for the model */
    public function toArray(): array
    {
        $out = [
            'fp' => $this->fingerprint,
            'tpl' => $this->template,
            'lvl' => strtolower($this->level->name),
            'err' => $this->errorType,
            'frames' => $this->frames,
            'n' => $this->count,
            'prev' => $this->previousCount,
            'st' => $this->status->value,
            'watch' => $this->watchMatches,
            'first' => $this->firstSeen->format(DATE_ATOM),
            'last' => $this->lastSeen->format(DATE_ATOM),
        ];

        return $out;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
