<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Schedule\Window;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;

/**
 * Behaviours every LogSource must have. Subclasses script their backend's HTTP responses in the hooks.
 */
abstract class LogSourceContractTest extends TestCase
{
    /** Window every hook's data falls inside. */
    protected function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
    }

    protected function selector(): StreamSelector
    {
        return new StreamSelector(['app' => 'svc', 'env' => 'stg']);
    }

    /** @param array<string, int> $totals level name => count */
    abstract protected function makeSourceWithTotals(array $totals): LogSource;

    /** @param list<string> $rawLines oldest first, all inside window() */
    abstract protected function makeSourceWithLines(array $rawLines): LogSource;

    /** A source holding $n error lines inside window(). */
    abstract protected function makeSourceWithManyLines(int $n): LogSource;

    /** @param array<string, DateTimeImmutable> $versions version => first seen */
    abstract protected function makeSourceWithVersions(array $versions): LogSource;

    /** A source whose backend answers $httpStatus, or fails at the network level when 0. */
    abstract protected function makeSourceFailing(int $httpStatus): LogSource;

    /** The credential the failing source was built with; it must never appear in an error. */
    abstract protected function secret(): string;

    public function testTotalsReturnsLevelToCount(): void
    {
        $totals = ['error' => 12, 'warning' => 35, 'info' => 900];

        $this->assertEquals($totals, $this->makeSourceWithTotals($totals)->totals($this->window(), $this->selector()));
    }

    public function testVersionsReturnsVersionToFirstSeen(): void
    {
        $versions = [
            '1.4.0' => new DateTimeImmutable('2026-10-22T01:00:00Z'),
            '1.5.0' => new DateTimeImmutable('2026-10-22T10:00:00Z'),
        ];

        $got = $this->makeSourceWithVersions($versions)->versions($this->window(), $this->selector(), 'version');

        $this->assertSame(['1.4.0', '1.5.0'], array_keys($got));
        $this->assertEquals($versions['1.4.0'], $got['1.4.0']);
        $this->assertEquals($versions['1.5.0'], $got['1.5.0']);
    }

    public function testVersionsIsEmptyWhenLabelAbsent(): void
    {
        $this->assertSame([], $this->makeSourceWithVersions([])->versions($this->window(), $this->selector(), 'version'));
    }

    public function testLinesYieldsLogEventsLazily(): void
    {
        $source = $this->makeSourceWithLines([$this->line('ERROR', 'boom')]);

        $lines = $source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 100));

        $this->assertInstanceOf(Generator::class, $lines);
        $events = $this->collect($lines);
        $this->assertCount(1, $events);
        $this->assertContainsOnlyInstancesOf(LogEvent::class, $events);
        $this->assertSame('boom', $events[0]->message);
        $this->assertSame(Level::Error, $events[0]->level);
    }

    public function testLinesAppliesMinLevel(): void
    {
        $source = $this->makeSourceWithLines([
            $this->line('DEBUG', 'd'),
            $this->line('INFO', 'i'),
            $this->line('WARNING', 'w'),
            $this->line('ERROR', 'e'),
        ]);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 100)));

        $this->assertSame(['w', 'e'], array_map(static fn (LogEvent $e): string => $e->message, $events));
    }

    public function testLinesStopsAtEventCapAndReportsTruncation(): void
    {
        $raw = array_map(fn (int $i): string => $this->line('ERROR', "e{$i}"), range(1, 5));

        $source = $this->makeSourceWithLines($raw);
        $this->assertFalse($source->lastReadTruncated());
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 3)));
        $this->assertCount(3, $events);
        $this->assertTrue($source->lastReadTruncated());

        // A cap of 5 or more reserves a newest share (NewestShareTest); below that the read is the plain oldest-first one.
        $source = $this->makeSourceWithLines(array_slice($raw, 0, 4));
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 4)));
        $this->assertCount(4, $events);
        $this->assertFalse($source->lastReadTruncated());
    }

    public function testLinesPagesPastOneBackendPage(): void
    {
        $source = $this->makeSourceWithManyLines(5100);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 100000)));

        $this->assertCount(5100, $events);
        $this->assertFalse($source->lastReadTruncated());
    }

    /** @return array<string, array{int}> */
    public static function failures(): array
    {
        return ['unauthorized' => [401], 'forbidden' => [403], 'server error' => [500], 'unavailable' => [503], 'network' => [0]];
    }

    #[DataProvider('failures')]
    public function testFailuresBecomeSourceUnavailableWithoutLeakingTheCredential(int $status): void
    {
        $calls = [
            fn (LogSource $s) => $s->totals($this->window(), $this->selector()),
            fn (LogSource $s) => $this->collect($s->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 10))),
            fn (LogSource $s) => $s->versions($this->window(), $this->selector(), 'version'),
        ];

        foreach ($calls as $call) {
            try {
                $call($this->makeSourceFailing($status));
                $this->fail('SourceUnavailable expected');
            } catch (SourceUnavailable $e) {
                $this->assertStringNotContainsString($this->secret(), $e->getMessage());
            }
        }
    }

    /**
     * @param iterable<LogEvent> $events
     *
     * @return list<LogEvent>
     */
    protected function collect(iterable $events): array
    {
        return $events instanceof \Traversable ? iterator_to_array($events, false) : array_values($events);
    }

    protected function line(string $level, string $message): string
    {
        return "[2026-10-22 10:00:00] production.{$level}: {$message} {}";
    }
}
