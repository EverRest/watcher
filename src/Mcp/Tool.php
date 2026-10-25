<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/** One read-only operation with a fixed input schema; `call` returns the `data` object (typed arrays only, FR-021). */
interface Tool
{
    public function name(): string;

    /** `inspect`, `onboarding` or `docs` (FR-060). */
    public function group(): string;

    /** Static text from code, no data from state or logs (FR-014). */
    public function description(): string;

    /** @return array<string, mixed> a JSON Schema with every `$ref` resolved */
    public function inputSchema(): array;

    /** `openWorldHint`: the tool reaches the log source, a provider or the state store. */
    public function openWorld(): bool;

    /**
     * @param array<string, mixed> $args already validated against {@see inputSchema()}
     * @return array<string, mixed>
     * @throws ToolError
     */
    public function call(array $args): array;
}
