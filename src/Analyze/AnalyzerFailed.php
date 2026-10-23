<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use RuntimeException;

final class AnalyzerFailed extends RuntimeException
{
    /** @param Usage|null $usage what the attempts made before failing already cost (null when nothing was billed) */
    public function __construct(string $message = '', public readonly ?Usage $usage = null)
    {
        parent::__construct($message);
    }
}
