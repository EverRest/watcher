<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use RuntimeException;

/** FR-064: a secret-shaped value. Carries no value, ever (stderr gets the class name and the tool name). */
final class SecretRefused extends RuntimeException
{
    public const MESSAGE = 'Give the name of the environment variable, not its value.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
