<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/** A log call site: a stable key, the masked identifier, and how it was identified (`frame` or `template`). */
final class Site
{
    public function __construct(
        public readonly string $key,
        public readonly string $id,
        public readonly string $kind,
    ) {
    }
}
