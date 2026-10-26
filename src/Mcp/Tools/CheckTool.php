<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Closure;
use Throwable;
use Watcher\Mcp\OutputGuard;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolError;
use Watcher\Operate\CheckReport;

/**
 * FR-031: the doctor's checks in order, with a closed reason vocabulary. The report comes from a callable (INTEGRATOR: a `Doctor` built
 * with its `readOnly` flag over the read-only store, T022); this class never builds a notifier, posts or calls a model.
 */
final class CheckTool implements Tool
{
    private const NAMES = ['config', 'source', 'provider_auth', 'models', 'prices', 'state', 'notifier'];

    /** The fixed vocabulary of spec 002 (and the reasons the doctor produces today); anything else is "unavailable". */
    private const REASONS = [
        '/^(?:unavailable|unauthorised|timeout|disabled|disabled \(enabled=false\)|config invalid|provider auth failed|provider has no model list|none \(dashboard-only\))$/D',
        '/^HTTP \d{3}$/D',
        '/^x-api-key (?:accepted|rejected \(HTTP \d{3}\))$/D',
        '/^invalid: [A-Za-z0-9_.<>() -]{1,120}$/D',
        '/^(?:unknown model|no price) for (?:triage|analyze|escalate)(?:, (?:triage|analyze|escalate))*$/D',
        '/^(?:read-only probe|unreadable|write failed|read-back mismatch|lock not exclusive)$/D',
        '/^internal error \([A-Za-z0-9_]{1,80}\)$/D',
    ];

    /** @param Closure(): CheckReport $report */
    public function __construct(private readonly Closure $report)
    {
    }

    public function name(): string
    {
        return 'watcher_check';
    }

    public function group(): string
    {
        return 'inspect';
    }

    public function description(): string
    {
        return 'Runs the read-only configuration, source, provider, price, state and notifier checks of the watcher. Posts nothing and makes no model call.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function call(array $args): array
    {
        try {
            $report = ($this->report)();
        } catch (Throwable) {
            throw new ToolError('internal_error');
        }
        $checks = [];
        foreach ($report->results as $r) {
            if (!in_array($r->name, self::NAMES, true)) {
                continue; // post_test and observe lines are not part of this contract
            }
            $checks[] = [
                'name' => $r->name,
                'status' => OutputGuard::enum($r->status, ['pass', 'fail', 'skip']),
                'reason' => $r->reason === '' ? null : (self::inVocabulary($r->reason) ? $r->reason : 'unavailable'),
            ];
        }

        return ['ok' => $report->ok(), 'checks' => $checks];
    }

    private static function inVocabulary(string $reason): bool
    {
        foreach (self::REASONS as $re) {
            if (preg_match($re, $reason) === 1) {
                return true;
            }
        }

        return false;
    }
}
