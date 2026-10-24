<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use BackedEnum;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\RunResult;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingStateStore;

/**
 * Characterisation of Watcher::run() for the decomposition (Lane A): digest text, every state write in order, the final
 * state with its TTLs, and the RunResult, for eight scenarios. The files in tests/Fixtures/golden were captured from the
 * code before the refactor; a refactor that changes any of them changed behaviour.
 * Regenerate deliberately with: WATCHER_GOLDEN_UPDATE=1 vendor/bin/phpunit --filter RefactorGoldenTest
 */
final class RefactorGoldenTest extends TestCase
{
    private const DELIVERY = 'slack delivery failed: HTTP 500';

    /** One dollar a day; only the analyze model costs money ($5 per million input tokens). */
    private const BUDGET = [
        'analysis' => [
            'daily_budget_usd' => 1.0,
            'prices' => ['m-t' => [0, 0, 0, 0], 'm-a' => [5, 0, 0, 0], 'm-e' => [0, 0, 0, 0]],
        ],
    ];

    /** @return list<LogEvent> */
    private static function alpha(): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
    }

    /** @return list<LogEvent> */
    private static function withBeta(): array
    {
        return [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')];
    }

    /** @param list<LogEvent> $events */
    private static function source(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** A harness that has baselined the first window and stands at the second, with a spy on the store. */
    private static function ready(Harness $h): RecordingStateStore
    {
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        $h->state = $spy = new RecordingStateStore($h->state);

        return $spy;
    }

    /** @return array<string, mixed> */
    private static function capture(Harness $h, RunResult $r, ?RecordingStateStore $spy, string $printed = ''): array
    {
        self::assertInstanceOf(InMemoryStateStore::class, $spy === null ? $h->state : self::inner($h));

        return self::plain([
            'result' => ['outcome' => $r->outcome, 'failureReason' => $r->failureReason, 'usage' => $r->usage, 'summary' => $r->summaryData, 'summaryLine' => $r->summary(), 'exitCode' => $r->exitCode()],
            'sent' => array_map(static fn (array $s): array => ['text' => $s['message']->text, 'sections' => $s['message']->sections, 'to' => $s['to']], $h->notifier->sent),
            'attempts' => $h->notifier->attempts,
            'printed' => $printed,
            'writes' => $spy?->writes() ?? [],
            'state' => self::inner($h)->all(),
            'ttls' => self::inner($h)->ttls(),
        ]);
    }

    private static function inner(Harness $h): InMemoryStateStore
    {
        // The spy wraps the in-memory store; the harness keeps a handle on neither, so the store is found through the spy.
        $state = $h->state;
        if ($state instanceof RecordingStateStore) {
            $inner = (new \ReflectionProperty($state, 'inner'))->getValue($state);
            self::assertInstanceOf(InMemoryStateStore::class, $inner);

            return $inner;
        }
        self::assertInstanceOf(InMemoryStateStore::class, $state);

        return $state;
    }

    /** Objects become arrays (enums their value, dates ATOM) so the comparison is plain JSON. */
    private static function plain(mixed $v): mixed
    {
        return match (true) {
            $v instanceof BackedEnum => $v->value,
            $v instanceof DateTimeInterface => $v->format(DATE_ATOM),
            $v instanceof JsonSerializable => self::plain($v->jsonSerialize()),
            is_object($v) => ['@' . $v::class => self::plain(get_object_vars($v))],
            is_array($v) => array_map(self::plain(...), $v),
            default => $v,
        };
    }

    /** @param array<string, mixed> $actual */
    private static function assertGolden(string $name, array $actual, RunOutcome $expected): void
    {
        self::assertSame($expected->value, $actual['result']['outcome'] ?? null, "scenario $name did not reach the outcome it is meant to pin");
        $path = __DIR__ . '/../Fixtures/golden/' . $name . '.json';
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (getenv('WATCHER_GOLDEN_UPDATE') === '1') {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $json);
        }
        self::assertFileExists($path, 'golden file missing: capture it from the pre-refactor code');
        self::assertSame(file_get_contents($path), $json, "scenario $name changed behaviour");
    }

    public function testReported(): void
    {
        $h = new Harness();
        $spy = self::ready($h);
        $r = $h->watcher(self::source(self::withBeta()))->run();

        self::assertGolden('reported', self::capture($h, $r, $spy), RunOutcome::Reported);
    }

    public function testQuiet(): void
    {
        $h = new Harness();
        $spy = self::ready($h);
        $r = $h->watcher(self::source([]))->run();

        self::assertGolden('quiet', self::capture($h, $r, $spy), RunOutcome::Quiet);
    }

    public function testBaselined(): void
    {
        $h = new Harness();
        $h->state = $spy = new RecordingStateStore($h->state);
        $r = $h->watcher(self::source(self::alpha()))->run();

        self::assertGolden('baselined', self::capture($h, $r, $spy), RunOutcome::Baselined);
    }

    public function testFailedDelivery(): void
    {
        $h = new Harness();
        $spy = self::ready($h);
        $h->notifier->failWith(new DeliveryFailed(self::DELIVERY));
        $r = $h->watcher(self::source(self::withBeta()))->run();

        self::assertGolden('failed-delivery', self::capture($h, $r, $spy), RunOutcome::Failed);
    }

    public function testBudgetExhausted(): void
    {
        $h = new Harness(self::BUDGET + ['schedule' => ['window' => 'PT1H']]);
        $h->watcher(self::source(self::alpha()))->run();
        $h->clock->advance('+1 hour');
        (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->add(new Usage('m-a', 1, 1), 5.0);
        $h->state = $spy = new RecordingStateStore($h->state);
        $r = $h->watcher(self::source(self::withBeta()))->run();

        self::assertGolden('budget-exhausted', self::capture($h, $r, $spy), RunOutcome::Reported);
    }

    public function testDryRun(): void
    {
        $h = new Harness();
        $spy = self::ready($h);
        $out = '';
        $r = $h->watcher(self::source(self::withBeta()), RunOptions::dryRun(), static function (string $s) use (&$out): void {
            $out .= $s;
        })->run();

        self::assertGolden('dry-run', self::capture($h, $r, $spy, $out), RunOutcome::Reported);
    }

    public function testDisabled(): void
    {
        $h = new Harness(['enabled' => false]);
        $h->state = $spy = new RecordingStateStore($h->state);
        $r = $h->watcher(self::source(self::alpha()))->run();

        self::assertGolden('disabled', self::capture($h, $r, $spy), RunOutcome::Disabled);
    }

    public function testFleetSeam(): void
    {
        $h = new FleetHarness(['api']);
        $h->logs('api', self::alpha());
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
        $h->logs('api', self::withBeta());
        $result = $h->runner()->run(null, RunOptions::default());

        $service = $result->services[0] ?? null;
        self::assertNotNull($service);
        $actual = self::plain([
            'result' => ['outcome' => $service->outcome, 'fleet' => $result],
            'api' => array_map(static fn (array $s): array => ['text' => $s['message']->text, 'sections' => $s['message']->sections, 'to' => $s['to']], $h->notifiers['api']->sent),
            'fleet' => array_map(static fn (array $s): array => ['text' => $s['message']->text, 'sections' => $s['message']->sections, 'to' => $s['to']], $h->fleetNotifier->sent),
            'printed' => $h->printed,
            'state' => $h->state->all(),
            'ttls' => $h->state->ttls(),
        ]);

        self::assertGolden('fleet-seam', $actual, RunOutcome::Reported);
    }
}
