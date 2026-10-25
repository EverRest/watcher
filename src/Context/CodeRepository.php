<?php

declare(strict_types=1);

namespace Watcher\Context;

interface CodeRepository
{
    /** @throws CodeRepositoryException */
    public function readFile(string $commit, string $path, int $maxBytes): string;
}
