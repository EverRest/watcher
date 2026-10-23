<?php

declare(strict_types=1);

namespace Watcher\Analyze;

enum FindingKind: string
{
    case Bug = 'bug';
    case Regression = 'regression';
    case Anomaly = 'anomaly';
    case Noise = 'noise';
}
