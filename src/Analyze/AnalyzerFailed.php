<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use RuntimeException;

final class AnalyzerFailed extends RuntimeException
{
    /** @param bool $refused the model declined to answer (stop_reason refusal): never retried, the window is not re-run */
    public function __construct(string $message = '', public readonly bool $refused = false)
    {
        parent::__construct($message);
    }
}
