<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/**
 * The reports of one pass and how it ended. Exit 0: reports produced (findings or not) or switched off; 1: a source, state
 * or delivery failure (a fixed reason, never an exception message); 2: a usage error.
 */
final class HygieneResult
{
    /**
     * @param array<string, Report> $reports in the order privacy, shape, proposals
     * @param list<string> $notes lines printed after the reports
     * @param array<string, mixed> $envelope service, environment, generated_at, window, events_read, sampled
     */
    public function __construct(
        public readonly array $reports = [],
        public readonly array $notes = [],
        private readonly int $exit = 0,
        public readonly ?string $failure = null,
        public readonly bool $skipped = false,
        public readonly bool $disabled = false,
        private readonly array $envelope = [],
    ) {
    }

    public function withNote(string $note): self
    {
        return new self($this->reports, [...$this->notes, $note], $this->exit, $this->failure, $this->skipped, $this->disabled, $this->envelope);
    }

    public function exitCode(): int
    {
        return $this->exit;
    }

    public function text(): string
    {
        $lines = [];
        if (isset($this->envelope['header'])) {
            $lines[] = (string) $this->envelope['header'];
        }
        foreach ($this->reports as $r) {
            $lines[] = $r->text();
        }
        if ($this->failure !== null) {
            $lines[] = 'hygiene failed: ' . $this->failure;
        }
        foreach ($this->notes as $n) {
            $lines[] = $n;
        }

        return implode("\n", $lines);
    }

    public function toJson(): string
    {
        $e = $this->envelope;

        return json_encode([
            'schema' => 1,
            'service' => $e['service'] ?? null,
            'environment' => $e['environment'] ?? null,
            'generated_at' => $e['generated_at'] ?? null,
            'window' => $e['window'] ?? null,
            'events_read' => $e['events_read'] ?? 0,
            'sampled' => $e['sampled'] ?? false,
            'failure' => $this->failure,
            'notes' => $this->notes,
            // An object even when empty, so a consumer always finds a map.
            'reports' => (object) array_map(static fn (Report $r): array => $r->toArray(), $this->reports),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
