<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\HygieneSource;
use Watcher\Mcp\Tool;

/** FR-034: the hygiene report of spec 006 when there is one; until 006 exists the source is empty and the answer is `hygiene_not_enabled`. */
final class HygieneTool implements Tool
{
    /** @param bool $enabled false: the master switch is off, nothing is read (FR-007) */
    public function __construct(private readonly HygieneSource $source, private readonly bool $enabled = true)
    {
    }

    public function name(): string
    {
        return 'watcher_hygiene_report';
    }

    public function group(): string
    {
        return 'inspect';
    }

    public function description(): string
    {
        return 'Returns the latest log hygiene report when the hygiene feature is enabled.';
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
            return ['available' => false, 'reason' => 'disabled'];
        }
        $report = $this->source->latest();

        return $report === null ? ['available' => false, 'reason' => 'hygiene_not_enabled'] : ['available' => true, 'report' => $report];
        // INTEGRATOR (T023): spec 006 defines the field table; every string of $report must then pass OutputGuard.
    }
}
