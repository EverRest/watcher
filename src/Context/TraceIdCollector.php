<?php

declare(strict_types=1);

namespace Watcher\Context;

use DateTimeImmutable;
use Watcher\Source\LogEvent;

/**
 * Keeps, per fingerprint, the valid trace id of the earliest event. In memory only (spec 009 R9): the id is never a
 * property of a group, so it cannot reach the model input, state or any text except inside a link URL.
 */
final class TraceIdCollector
{
    /** @var array<string, array{string, DateTimeImmutable}> */
    private array $seen = [];

    public function __construct(private readonly string $field = 'trace_id')
    {
    }

    /** Observer shape for a `Reducer` hook: called once per event with the group fingerprint. */
    public function observe(LogEvent $event, string $fingerprint): void
    {
        $id = $event->fields[$this->field] ?? null;
        if (!is_string($id) || preg_match('/^[0-9a-f]{16,32}$/Di', $id) !== 1) {
            return;
        }
        if (!isset($this->seen[$fingerprint]) || $event->time < $this->seen[$fingerprint][1]) {
            $this->seen[$fingerprint] = [strtolower($id), $event->time];
        }
    }

    public function idFor(string $fingerprint): ?string
    {
        return $this->seen[$fingerprint][0] ?? null;
    }
}
