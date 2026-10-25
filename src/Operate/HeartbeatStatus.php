<?php

declare(strict_types=1);

namespace Watcher\Operate;

use DateTimeImmutable;

/** Answer of {@see Heartbeat::status()}. `state` is ok|degraded|stale|failed|unknown|disabled; only `ok` is healthy. `degraded`: the last run worked but could not read the pod events. Not stored. */
final class HeartbeatStatus
{
    /** @param array<mixed>|null $record the stored heartbeat record */
    public function __construct(
        public readonly string $state,
        public readonly ?array $record,
        public readonly ?int $ageSeconds,
        public readonly int $limitSeconds,
        public readonly DateTimeImmutable $now,
        public readonly ?DateTimeImmutable $comparedWith,
        public readonly ?DateTimeImmutable $lastTriggerAt,
    ) {
    }

    /** The watcher (or its heartbeat) is switched off: nothing is written, so nothing can be stale. Reads no store. */
    public static function disabled(int $limitSeconds, DateTimeImmutable $now): self
    {
        return new self('disabled', null, null, $limitSeconds, $now, null, null);
    }

    public function ok(): bool
    {
        return $this->state === 'ok';
    }

    /** 0 ok, 3 unknown (no record, expired or unreadable store), 1 stale or failed. */
    public function exitCode(): int
    {
        return match ($this->state) {
            'ok', 'disabled' => 0,
            'unknown' => 3,
            default => 1,
        };
    }

    public function text(string $service): string
    {
        $rec = $this->record;
        $at = static fn (?DateTimeImmutable $d): string => $d?->format(DATE_ATOM) ?? '-';
        $last = $rec === null ? '-' : sprintf(
            '%s  outcome=%s  duration=%ss  cost_usd=%s%s',
            is_string($rec['finishedAt'] ?? null) ? $rec['finishedAt'] : '-',
            is_string($rec['outcome'] ?? null) ? $rec['outcome'] : '-',
            is_int($rec['durationSeconds'] ?? null) ? $rec['durationSeconds'] : '-',
            is_numeric($rec['costUsd'] ?? null) ? number_format((float) $rec['costUsd'], 4, '.', '') : '-',
            is_string($rec['reason'] ?? null) && $rec['reason'] !== '' ? '  reason=' . $rec['reason'] : '',
        );

        return "service:        $service\n"
            . "state:          {$this->state}\n"
            . "last run:       $last\n"
            . 'last ok:        ' . $at($this->comparedWith) . '  age=' . ($this->ageSeconds === null ? '-' : self::span($this->ageSeconds)) . '  limit=' . self::span($this->limitSeconds) . "\n"
            . 'last trigger:   ' . $at($this->lastTriggerAt) . "\n"
            . (is_string($rec['events'] ?? null) ? 'events:         ' . $rec['events'] . "\n" : '')
            . (is_string($rec['reason'] ?? null) && $rec['reason'] !== '' ? 'last warning:   ' . $rec['reason'] . "\n" : '')
            . 'compared:       now=' . $this->now->format(DATE_ATOM) . ' against last ok ' . $at($this->comparedWith) . "\n";
    }

    /** @return array<string, mixed> */
    public function toArray(string $service): array
    {
        return [
            'schema' => 1,
            'service' => $service,
            'state' => $this->state,
            'last_run' => $this->record,
            'age_seconds' => $this->ageSeconds,
            'limit_seconds' => $this->limitSeconds,
            'now' => $this->now->format(DATE_ATOM),
            'last_ok_at' => $this->comparedWith?->format(DATE_ATOM),
            'last_trigger_at' => $this->lastTriggerAt?->format(DATE_ATOM),
        ];
    }

    private static function span(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? $h . 'h' . ($m > 0 ? $m . 'm' : '') : $m . 'm';
    }
}
