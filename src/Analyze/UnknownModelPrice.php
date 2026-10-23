<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use RuntimeException;

/** No `analysis.prices` entry for a model. Config validation should make this unreachable. */
final class UnknownModelPrice extends RuntimeException
{
}
