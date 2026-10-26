<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Watcher\Level;

final class KnownLine
{
    public function __construct(
        public readonly string $ticket,
        public readonly Level $level,
        public readonly int $count,
        public readonly string $fingerprint,
    ) {
    }
}
