<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use RuntimeException;

final class AnalyzerFailed extends RuntimeException
{
    /**
     * @param Usage|null $usage   what the attempts made before failing already cost (null when nothing was billed)
     * @param bool       $refused the model declined to answer (stop_reason refusal): never retried, the window is not re-billed
     */
    public function __construct(string $message = '', public readonly ?Usage $usage = null, public readonly bool $refused = false)
    {
        parent::__construct($message);
    }
}
