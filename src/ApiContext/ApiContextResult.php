<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/** Outcome of one read: a spec, or a fixed failure token. `cache` = hit|miss|failed|none (RunStats vocabulary). */
final class ApiContextResult
{
    public function __construct(
        public readonly ?ReducedSpec $spec,
        public readonly ?string $failure,
        public readonly string $cache,
    ) {
    }
}
