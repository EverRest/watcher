<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use RuntimeException;

final class AnalyzerFailed extends RuntimeException
{
    /**
     * @param bool        $refused the model declined to answer (stop_reason refusal): never retried, the window is not re-run
     * @param string|null $skip    spec 019: the closed reason word when the model cannot be used at all (`no key`,
     *                             `authentication failed`, `key expired`, `quota exhausted`); null for every other failure
     */
    public function __construct(string $message = '', public readonly bool $refused = false, public readonly ?string $skip = null)
    {
        parent::__construct($message);
    }
}
