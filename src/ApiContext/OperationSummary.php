<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/** The six fields kept per operation; nothing else of an OpenAPI document is ever stored. Strings are already capped and masked. */
final class OperationSummary
{
    public function __construct(
        public readonly string $method,
        public readonly string $template,
        public readonly string $summary,
        public readonly string $tag,
        public readonly bool $deprecated,
        public readonly string $operationId,
    ) {
    }
}
