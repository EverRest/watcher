<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;
use Watcher\Tests\Fakes\FrozenClock;

/** The run deadline reaches the level-anomaly read pass too, not only the main read. */
final class AnomalyDeadlineTest extends TestCase
{
    public function testTheAnomalyPassIsStoppedByTheRunDeadline(): void
    {
        $h = new Harness(['schedule' => ['lock_ttl' => 900, 'run_deadline' => 10]]);
        $baseline = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $h->watcher(new \Watcher\Tests\Fakes\FakeLogSource(['error' => 5], $baseline))->run();
        $h->nextWindow();

        $source = new class ($h->clock) implements LogSource {
            /** @var list<ReadFilter> */
            public array $filters = [];
            /** @var list<bool> */
            public array $stopped = [];

            public function __construct(private readonly FrozenClock $clock)
            {
            }

            public function totals(Window $window, StreamSelector $selector): array
            {
                return ['error' => 5, 'info' => 3];
            }

            public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): \Generator
            {
                $this->filters[] = $filter;
                $this->clock->advance('+60 seconds'); // past the 10 s deadline
                $this->stopped[] = $filter->shouldStop();

                return;
                yield;
            }

            public function lastReadTruncated(): bool
            {
                return false;
            }

            public function versions(Window $window, StreamSelector $selector, string $label): array
            {
                return [];
            }
        };
        $h->watcher($source)->run();

        self::assertSame(Level::Info, $source->filters[0]->minLevel, 'the first read is the anomaly pass');
        self::assertTrue($source->stopped[0]);
        self::assertSame('deadline', $source->filters[0]->truncationReason);
    }
}
