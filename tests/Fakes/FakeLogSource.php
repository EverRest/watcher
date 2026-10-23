<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use DateTimeImmutable;
use Watcher\Schedule\Window;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;

final class FakeLogSource implements LogSource
{
    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];
    private ?SourceUnavailable $failure = null;

    /**
     * @param array<string, int> $totals
     * @param list<LogEvent> $events
     * @param array<string, DateTimeImmutable> $versions
     */
    public function __construct(
        private array $totals = [],
        private array $events = [],
        private array $versions = [],
    ) {
    }

    public function failWith(SourceUnavailable $e): void
    {
        $this->failure = $e;
    }

    /** @return list<list<mixed>> argument lists of every call to $method */
    public function callsTo(string $method): array
    {
        return array_values(array_map(
            static fn (array $c): array => $c['args'],
            array_filter($this->calls, static fn (array $c): bool => $c['method'] === $method),
        ));
    }

    public function totals(Window $window, StreamSelector $selector): array
    {
        $this->record('totals', [$window, $selector]);

        return $this->totals;
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): \Generator
    {
        $this->record('lines', [$window, $selector, $filter]);

        $n = 0;
        foreach ($this->events as $event) {
            if (!$event->level->atLeast($filter->minLevel) && !$this->matchesWatch($event, $filter)) {
                continue;
            }
            if ($n++ >= $filter->eventCap) {
                return;
            }
            yield $event;
        }
    }

    public function versions(Window $window, StreamSelector $selector, string $label): array
    {
        $this->record('versions', [$window, $selector, $label]);

        return $this->versions;
    }

    /** @param list<mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    private function matchesWatch(LogEvent $event, ReadFilter $filter): bool
    {
        foreach ($filter->watchPatterns as $re) {
            if (preg_match($re, $event->message) === 1) {
                return true;
            }
        }

        return false;
    }
}
