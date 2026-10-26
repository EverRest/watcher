<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;

final class RunCapTest extends TestCase
{
    public function testSeventhRunIsCapped(): void
    {
        $h = new Harness([
            'schedule' => [
                'every' => 'PT1H',
                'timezone' => 'UTC',
                'run_at' => null,
            ],
            'analysis' => ['max_runs_per_day' => 6],
        ]);
        $h->clock->set(new DateTimeImmutable('2026-10-08T09:30:00+00:00'));
        $events = Harness::events(Level::Error, 'cap test', 2);
        $source = new FakeLogSource(Harness::totalsOf($events), $events);
        for ($i = 0; $i < 6; ++$i) {
            $h->watcher($source)->run();
            $h->clock->advance('+1 hour');
        }
        $callsBefore = count($source->callsTo('lines'));
        $analyzerBefore = count($h->analyzer->allRequests());
        $r = $h->watcher($source)->run();
        self::assertSame(RunOutcome::Capped, $r->outcome);
        self::assertSame($callsBefore, count($source->callsTo('lines')));
        self::assertSame($analyzerBefore, count($h->analyzer->allRequests()));
    }

    public function testSingleDailyRunHasNoGuards(): void
    {
        $h = new Harness(['schedule' => ['run_at' => '06:00', 'timezone' => 'UTC']]);
        $h->clock->set(new DateTimeImmutable('2026-10-08T06:30:00+00:00'));
        $events = Harness::events(Level::Error, 'daily', 2);
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $hasRunsKey = false;
        self::assertInstanceOf(\Watcher\Tests\Fakes\InMemoryStateStore::class, $h->state);
        foreach ($h->state->all() as $key => $_) {
            if (str_starts_with($key, 'runs:')) {
                $hasRunsKey = true;
            }
        }
        self::assertFalse($hasRunsKey);
    }
}
