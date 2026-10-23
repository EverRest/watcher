<?php

declare(strict_types=1);

namespace Watcher\Operate;

use InvalidArgumentException;

final class RunOptions
{
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly bool $estimate = false,
        public readonly bool $json = false,
    ) {
        if ($estimate && !$dryRun) {
            throw new InvalidArgumentException('--estimate requires --dry-run');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public static function dryRun(bool $estimate = false, bool $json = false): self
    {
        return new self(true, $estimate, $json);
    }
}
