<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Laravel;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Console output with a separate stderr buffer, so tests can tell the two streams apart. */
final class SplitOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $stderr;

    public function __construct()
    {
        parent::__construct();
        $this->stderr = new BufferedOutput();
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function section(): \Symfony\Component\Console\Output\ConsoleSectionOutput
    {
        throw new \LogicException('not supported');
    }

    public function stderr(): string
    {
        return $this->stderr instanceof BufferedOutput ? $this->stderr->fetch() : '';
    }
}
