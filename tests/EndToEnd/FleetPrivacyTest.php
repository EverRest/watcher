<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\SpyLogger;

/** Principle I for the fleet (SC-007, quickstart 25): the correlated finding and the combined digest carry no seeded value. */
final class FleetPrivacyTest extends TestCase
{
    private const API_DEP = ['dependencies' => ['api' => ['stream_tags' => ['app' => 'api', 'env' => 'stg']]]];

    /** @return list<LogEvent> */
    private static function corpus(): array
    {
        $parser = new LineParser();
        $events = [];
        foreach (file(__DIR__ . '/../Fixtures/privacy/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }

        return $events;
    }

    public function testNoSeededValueReachesTheCorrelatedFindingTheCombinedDigestStateOrHostLog(): void
    {
        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../Fixtures/privacy/seeded-values.php';
        $h = new FleetHarness(['api', 'bff-a', 'bff-b'], ['digest' => 'combined'], ['bff-a' => self::API_DEP, 'bff-b' => self::API_DEP]);
        $h->hostLog = $log = new SpyLogger();
        $baseline = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, $baseline);
        }
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();

        // every service logs the seeded corpus; the BFFs also call api with personal values in the URL and message
        $personal = array_fill(0, 6, new LogEvent(new \DateTimeImmutable('2026-10-07T21:12:00+00:00'), Level::Error, 'GET http://api/v1/orders returned 500 for Anna Kowalski anna.kowalski@example.org +380501234567 token=tk_7f3a9c1e5b2d4086', 'UpstreamError', [], [], []));
        $h->logs('api', [...$baseline, ...self::corpus()]);
        $h->logs('bff-a', [...$baseline, ...self::corpus(), ...$personal]);
        $h->logs('bff-b', [...$baseline, ...self::corpus(), ...$personal]);
        $h->fleetNotifier->sent = [];

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertNotSame([], $r->correlated, 'the scenario must correlate, or the assertions below prove nothing');
        self::assertTrue($r->correlatedPosted);
        self::assertGreaterThanOrEqual(2, count($h->fleetNotifier->sent), 'combined digest and correlated finding');
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $payloads = [
            'correlated array' => array_map(static fn ($c): array => $c->toArray(), $r->correlated),
            'fleet messages' => array_map(static fn (array $s) => $s['message'], $h->fleetNotifier->sent),
            'state store' => $h->state->all(),
            'host log' => $log->records,
            'result lines' => $r->lines(),
            'result json' => $r->toJson(),
        ];
        foreach ($payloads as $where => $payload) {
            $haystack = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                . json_encode($payload, JSON_THROW_ON_ERROR);
            foreach ($seeded as $value) {
                self::assertStringNotContainsString($value, $haystack, "seeded value leaked into $where: $value");
            }
        }
    }
}
