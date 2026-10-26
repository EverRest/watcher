<?php

declare(strict_types=1);

namespace Watcher\Analyze;

enum Severity: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
    case Critical = 'critical';

    /** Position on the ladder: info 0, warn 1, error 2, critical 3. */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warn => 1,
            self::Error => 2,
            self::Critical => 3,
        };
    }
}
