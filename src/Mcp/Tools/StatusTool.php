<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Closure;
use Throwable;
use Watcher\Mcp\OutputGuard;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolError;
use Watcher\Operate\HeartbeatStatus;

/** FR-030: what `watcher status --json` returns, plus today's spend. Reads the heartbeat through the read-only store. */
final class StatusTool implements Tool
{
    /**
     * @param Closure(): HeartbeatStatus $status
     * @param Closure(): array{usd: float, tokens: int, budget_usd: float, token_limit: int|null, remaining_usd: float} $spend
     * @param Closure(): \DateTimeImmutable $now
     * @param bool $enabled false: the master switch (or the heartbeat) is off, nothing is read (FR-007)
     */
    public function __construct(private readonly Closure $status, private readonly Closure $spend, private readonly Closure $now, private readonly OutputGuard $guard, private readonly bool $enabled)
    {
    }

    public function name(): string
    {
        return 'watcher_status';
    }

    public function group(): string
    {
        return 'inspect';
    }

    public function description(): string
    {
        return 'Reports whether the watcher is healthy: state of the last run, ages, limits and today\'s spend. Reads state only.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        if (!$this->enabled) {
            return ['state' => 'disabled', 'now' => OutputGuard::timestamp(($this->now)()->format(DATE_ATOM))];
        }
        try {
            $s = ($this->status)();
            $spend = ($this->spend)();
        } catch (Throwable) {
            throw new ToolError('state_unavailable');
        }
        $rec = $s->record;
        $last = $rec === null ? null : [
            'outcome' => OutputGuard::outcome($rec['outcome'] ?? null),
            'at' => OutputGuard::timestamp($rec['finishedAt'] ?? null),
            'duration_seconds' => OutputGuard::int($rec['durationSeconds'] ?? null),
            'cost_usd' => OutputGuard::float($rec['costUsd'] ?? null),
            'reason' => $this->guard->text($rec['reason'] ?? null, 200),
        ];
        $ts = static fn (?\DateTimeImmutable $d): ?string => $d === null ? null : OutputGuard::timestamp($d->format(DATE_ATOM));

        return [
            'state' => OutputGuard::enum($s->state, ['ok', 'stale', 'failed', 'unknown', 'disabled']),
            'last_run' => $last,
            'last_ok_at' => $ts($s->comparedWith),
            'last_trigger_at' => $ts($s->lastTriggerAt),
            'now' => $ts($s->now),
            'age_seconds' => OutputGuard::int($s->ageSeconds),
            'limit_seconds' => OutputGuard::int($s->limitSeconds),
            'spend_today' => $spend,
        ];
    }
}
