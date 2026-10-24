<?php

declare(strict_types=1);

namespace Watcher\Privacy;

enum GroupStatusLabel: string
{
    case New = 'new';
    case Grown = 'grown';
    case Unchanged = 'unchanged';
    case Expected = 'expected';
}
