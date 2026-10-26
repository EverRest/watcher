<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use Closure;
use Watcher\Mcp\Tool;

final class StubTool implements Tool
{
    /**
     * @param array<string, mixed> $schema
     * @param Closure(array<string, mixed>): array<string, mixed> $fn
     */
    public function __construct(private readonly string $name, private readonly string $group, private readonly array $schema, private readonly Closure $fn, private readonly bool $open = false)
    {
    }

    public static function plain(string $name = 'watcher_status', string $group = 'inspect'): self
    {
        return new self($name, $group, ['type' => 'object', 'additionalProperties' => false, 'properties' => []], static fn (array $a): array => ['ok' => true]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function description(): string
    {
        return 'stub';
    }

    public function inputSchema(): array
    {
        return $this->schema;
    }

    public function openWorld(): bool
    {
        return $this->open;
    }

    public function call(array $args): array
    {
        return ($this->fn)($args);
    }
}
