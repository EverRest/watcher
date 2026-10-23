<?php

declare(strict_types=1);

namespace Watcher\Context;

final class DependencyReadResult
{
    /**
     * @param list<DependencyContext> $contexts          one per declared dependency, in declaration order
     * @param list<string>            $failedDependencies names whose source was unavailable
     */
    public function __construct(
        public readonly array $contexts,
        public readonly array $failedDependencies,
    ) {
    }
}
