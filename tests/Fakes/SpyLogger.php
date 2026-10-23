<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
