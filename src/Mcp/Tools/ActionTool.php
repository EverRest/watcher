<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Closure;
use Throwable;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolError;

/**
 * One `actions` tool: runs a watcher command that may send messages or write state. It needs `confirm: true` when
 * `needsConfirm` says so, is rate limited per MCP session (in memory: the server does not write state), and every run is audited.
 */
final class ActionTool implements Tool
{
    private ?int $last = null;

    /**
     * @param array<string, mixed> $inputSchema
     * @param Closure(array<string, mixed>): array<string, mixed> $run
     * @param Closure(string, array<string, mixed>): void $audit
     * @param Closure(array<string, mixed>): bool $needsConfirm
     * @param Closure(): int $now unix seconds
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
        private readonly Closure $run,
        private readonly Closure $audit,
        private readonly Closure $needsConfirm,
        private readonly int $minIntervalSeconds,
        private readonly Closure $now,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function group(): string
    {
        return 'actions';
    }

    public function description(): string
    {
        return $this->description;
    }

    public function inputSchema(): array
    {
        return $this->inputSchema;
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        if (($this->needsConfirm)($args) && ($args['confirm'] ?? false) !== true) {
            throw new ToolError('confirm_required');
        }
        $t = ($this->now)();
        if ($this->last !== null && $t - $this->last < $this->minIntervalSeconds) {
            throw new ToolError('rate_limited', ['retry_after' => $this->minIntervalSeconds - ($t - $this->last)]);
        }
        $this->last = $t;

        try {
            $data = ($this->run)($args);
        } catch (Throwable $e) {
            ($this->audit)($this->name, ['exit' => 'error']);

            throw $e;
        }
        ($this->audit)($this->name, $data);

        return $data;
    }
}
