<?php

declare(strict_types=1);

namespace Watcher\Privacy;

use DateTimeImmutable;
use JsonSerializable;
use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;
use Watcher\Source\LogEvent;

/** The only shape the analyzer and renderer accept: an allowlist, no raw message, fields or labels. */
final class RedactedGroup implements JsonSerializable
{
    public const MAX_FRAMES = 5;

    /** @var list<string> */
    public readonly array $frames;

    public readonly ?string $origin;

    public readonly ?int $pods;

    public readonly ?string $workloadKind;

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
        ?string $origin = null,
        ?int $pods = null,
        ?string $workloadKind = null,
    ) {
        // Closed vocabularies only: whatever reaches here is checked again, so no free text can ride along.
        $this->origin = $origin === LogEvent::ORIGIN_KUBERNETES ? $origin : null;
        $this->pods = $this->origin !== null && $pods !== null && $pods >= 0 ? $pods : null;
        $this->workloadKind = $this->origin !== null && in_array($workloadKind, LogEvent::WORKLOAD_KINDS, true) ? $workloadKind : null;
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
            origin: $g->origin,
            pods: $g->pods,
            workloadKind: $g->workloadKind,
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
        if ($this->origin !== null) {
            // Spec 008: only a pod-event group carries these three; every other payload stays byte-identical.
            $out['src'] = 'k8s';
            $out['pods'] = $this->pods;
            $out['wk'] = $this->workloadKind;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
