<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/** Resources and prompts of the `docs` group (FR-092). Failures are {@see InvalidParams}. */
interface DocsEndpoint
{
    /** @return list<array{uri: string, name: string, description: string, mimeType: string}> */
    public function resources(): array;

    /** @return array{uri: string, mimeType: string, text: string} */
    public function read(string $uri): array;

    /** @return list<array<string, mixed>> */
    public function prompts(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function prompt(string $name, array $arguments): array;
}
