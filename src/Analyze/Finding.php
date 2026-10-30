<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use InvalidArgumentException;

final class Finding
{
    /**
     * @param list<string> $fingerprints
     * @param string       $howToCheck    what to look at to confirm or refute the hypothesis; '' = none
     * @param list<string> $relatedChecks other groups / declared dependencies worth a look, at most 3
     */
    public function __construct(
        public readonly array $fingerprints,
        public readonly FindingKind $kind,
        public readonly Severity $severity,
        public readonly string $title,
        public readonly string $evidence,
        public readonly string $suspectedCause,
        public readonly string $suggestedAction,
        public readonly float $confidence,
        public readonly bool $escalated = false,
        public readonly string $howToCheck = '',
        public readonly array $relatedChecks = [],
    ) {
        if ($fingerprints === []) {
            throw new InvalidArgumentException('A finding needs at least one fingerprint');
        }
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('confidence must be within 0..1');
        }
    }

    /**
     * Same finding with its coverage or code-bounded kind and severity replaced (the model's other text is untouched).
     *
     * @param list<string> $fingerprints
     */
    public function with(array $fingerprints, FindingKind $kind, Severity $severity): self
    {
        return new self($fingerprints, $kind, $severity, $this->title, $this->evidence, $this->suspectedCause, $this->suggestedAction, $this->confidence, $this->escalated, $this->howToCheck, $this->relatedChecks);
    }

    /**
     * The stored form, kept so a repeat can be sent without a model call (FR-008).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fingerprints' => $this->fingerprints,
            'kind' => $this->kind->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'evidence' => $this->evidence,
            'suspectedCause' => $this->suspectedCause,
            'suggestedAction' => $this->suggestedAction,
            'confidence' => $this->confidence,
            'escalated' => $this->escalated,
            'howToCheck' => $this->howToCheck,
            'relatedChecks' => $this->relatedChecks,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        return new self(
            array_values((array) $d['fingerprints']),
            FindingKind::from((string) $d['kind']),
            Severity::from((string) $d['severity']),
            (string) $d['title'],
            (string) $d['evidence'],
            (string) $d['suspectedCause'],
            (string) $d['suggestedAction'],
            (float) $d['confidence'],
            (bool) $d['escalated'],
            (string) $d['howToCheck'],
            array_values((array) $d['relatedChecks']),
        );
    }

    public function escalated(): self
    {
        return new self(
            $this->fingerprints,
            $this->kind,
            $this->severity,
            $this->title,
            $this->evidence,
            $this->suspectedCause,
            $this->suggestedAction,
            $this->confidence,
            true,
            $this->howToCheck,
            $this->relatedChecks
        );
    }
}
