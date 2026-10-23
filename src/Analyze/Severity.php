<?php

declare(strict_types=1);

namespace Watcher\Analyze;

enum Severity: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
    case Critical = 'critical';
}
