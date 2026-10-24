<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\Correlator;
use Watcher\Fleet\ServiceEntry;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

/** Quickstart 18-23: the link rules, decided in code from already-redacted groups. */
final class CorrelatorTest extends TestCase
{
    private const T0 = '2026-10-07T10:00:00+00:00';
    private const T1 = '2026-10-07T12:00:00+00:00';

    /**
     * @param list<string> $deps
     * @param list<string>|null $provides
     * @param array<string, mixed> $patch
     */
    private static function entry(string $name, array $deps = [], ?array $provides = null, array $patch = []): ServiceEntry
    {
        $config = FleetConfigTest::service($name, $patch);
        foreach ($deps as $d) {
            $config['dependencies'][$d] = ['stream_tags' => ['app' => $d]];
        }

        return new ServiceEntry(WatchConfig::fromArray($config), 0, $provides);
    }

    private static function group(string $fp, string $template, GroupStatusLabel $status = GroupStatusLabel::New, int $count = 10, string $first = self::T0, string $last = self::T1, Level $level = Level::Error, ?int $prev = null): RedactedGroup
    {
        return new RedactedGroup($fp, $template, $level, null, [], $count, $prev, $status, [], new DateTimeImmutable($first), new DateTimeImmutable($last));
    }

    private static function origin(string $fp = 'aaaaaaaaaaaaaaaaaaaa'): RedactedGroup
    {
        return self::group($fp, 'SQLSTATE connection refused to <HOST>');
    }

    private static function consumer(string $fp = 'bbbbbbbbbbbbbbbbbbbb', string $template = 'GET <URL:api>/items returned <N>'): RedactedGroup
    {
        return self::group($fp, $template, count: 7);
    }

    /** @return list<ServiceEntry> */
    private static function fleet(): array
    {
        return [self::entry('api'), self::entry('bff-a', ['api']), self::entry('bff-b', ['api'])];
    }

    public function testConsumerReferencingAnOriginInTheSameWindowIsLinked(): void
    {
        $links = (new Correlator(self::fleet()))->links(['api' => [self::origin()], 'bff-a' => [self::consumer()]]);

        self::assertCount(1, $links);
        self::assertSame('api', $links[0]->originService);
        self::assertSame('bff-a', $links[0]->consumerService);
        self::assertSame('api', $links[0]->dependency);
    }

    public function testNoReferenceNoClaim(): void
    {
        $c = new Correlator(self::fleet());

        self::assertSame([], $c->links(['api' => [self::origin()], 'bff-a' => [self::consumer(template: 'Unrelated failure <N>')]]));
    }

    public function testHostReferenceCountsToo(): void
    {
        $links = (new Correlator(self::fleet()))->links(['api' => [self::origin()], 'bff-a' => [self::consumer(template: 'connect to <HOST:api> timed out')]]);

        self::assertCount(1, $links);
    }

    public function testOriginOutsideTheFleetOrNotRunMakesNoLink(): void
    {
        $c = new Correlator(self::fleet());

        self::assertSame([], $c->links(['bff-a' => [self::consumer()]]), 'api did not run');
        self::assertSame([], $c->links(['svc-x' => [self::origin()], 'bff-a' => [self::consumer(template: 'GET <URL:svc-x> failed')]]), 'svc-x is not a dependency of bff-a');
    }

    public function testUnchangedOriginOrBelowMinLevelOrNonOverlappingWindowMakesNoLink(): void
    {
        $c = new Correlator(self::fleet());
        $consumer = ['bff-a' => [self::consumer()]];

        self::assertSame([], $c->links(['api' => [self::group('aaaaaaaaaaaaaaaaaaaa', 'x', GroupStatusLabel::Unchanged)]] + $consumer));
        self::assertSame([], $c->links(['api' => [self::group('aaaaaaaaaaaaaaaaaaaa', 'x', level: Level::Warning)]] + $consumer));
        self::assertSame([], $c->links(['api' => [self::group('aaaaaaaaaaaaaaaaaaaa', 'x', first: '2026-10-06T01:00:00+00:00', last: '2026-10-06T02:00:00+00:00')]] + $consumer));
    }

    public function testGrownOriginLinksAndToleranceBridgesAGap(): void
    {
        $gap = self::group('aaaaaaaaaaaaaaaaaaaa', 'x', GroupStatusLabel::Grown, first: '2026-10-07T08:00:00+00:00', last: '2026-10-07T09:30:00+00:00', prev: 3);
        $registry = ['api' => [$gap], 'bff-a' => [self::consumer()]];

        self::assertSame([], (new Correlator(self::fleet()))->links($registry));
        self::assertCount(1, (new Correlator(self::fleet(), 1800))->links($registry));
    }

    public function testProvidesMapsADependencyNameToAFleetService(): void
    {
        $fleet = [self::entry('core-api', provides: ['api']), self::entry('bff-a', ['api'])];

        $links = (new Correlator($fleet))->links(['core-api' => [self::origin()], 'bff-a' => [self::consumer()]]);

        self::assertSame('core-api', $links[0]->originService);
        self::assertSame('api', $links[0]->dependency);
    }

    public function testManyConsumersOneFinding(): void
    {
        $registry = ['api' => [self::origin()], 'bff-a' => [self::consumer('b1'), self::consumer('b2', 'POST <URL:api>/x returned <N>')], 'bff-b' => [self::consumer('b3')]];

        $findings = (new Correlator(self::fleet()))->findings('platform', $registry);

        self::assertCount(1, $findings);
        $a = $findings[0]->toArray();
        self::assertSame('api', $a['origin']['service']);
        self::assertTrue($a['hypothesis']);
        self::assertSame(
            [['service' => 'bff-a', 'groups' => 2, 'count' => 14, 'dependency' => 'api'], ['service' => 'bff-b', 'groups' => 1, 'count' => 7, 'dependency' => 'api']],
            $a['consumers'],
        );
        self::assertSame(['host_reference', 'window_overlap', 'origin_new'], $a['basis']);
        self::assertSame(['start' => '2026-10-07T10:00:00Z', 'end' => '2026-10-07T12:00:00Z'], $a['window']);
    }

    public function testCycleHasOneOriginTheEarlierFirstSeen(): void
    {
        $fleet = [self::entry('a-svc', ['b-svc']), self::entry('b-svc', ['a-svc'])];
        $registry = [
            'a-svc' => [self::group('fpa', 'call <URL:b-svc> failed', first: '2026-10-07T11:00:00+00:00')],
            'b-svc' => [self::group('fpb', 'call <URL:a-svc> failed', first: '2026-10-07T10:00:00+00:00')],
        ];

        $findings = (new Correlator($fleet))->findings('platform', $registry);

        self::assertCount(1, $findings);
        self::assertSame('b-svc', $findings[0]->toArray()['origin']['service']);
        self::assertSame('a-svc', $findings[0]->toArray()['consumers'][0]['service']);
    }

    public function testCycleTieBreaksOnTheServiceThatSortsFirst(): void
    {
        $fleet = [self::entry('a-svc', ['b-svc']), self::entry('b-svc', ['a-svc'])];
        $registry = ['a-svc' => [self::group('fpa', 'call <URL:b-svc> failed')], 'b-svc' => [self::group('fpb', 'call <URL:a-svc> failed')]];

        $findings = (new Correlator($fleet))->findings('platform', $registry);

        self::assertSame('a-svc', $findings[0]->toArray()['origin']['service']);
    }

    public function testDisabledCorrelatesNothing(): void
    {
        $c = new Correlator(self::fleet(), 0, false);

        self::assertSame([], $c->links(['api' => [self::origin()], 'bff-a' => [self::consumer()]]));
    }

    public function testOutputIsDeterministicWhateverTheRegistryOrder(): void
    {
        $a = ['api' => [self::origin()], 'bff-b' => [self::consumer('b3')], 'bff-a' => [self::consumer('b1')]];
        $b = ['bff-a' => [self::consumer('b1')], 'bff-b' => [self::consumer('b3')], 'api' => [self::origin()]];
        $c = new Correlator(self::fleet());

        self::assertEquals($c->findings('platform', $a), $c->findings('platform', $b));
    }

    public function testOnlyRedactedGroupsAreAccepted(): void
    {
        $doc = (string) (new \ReflectionMethod(Correlator::class, 'links'))->getDocComment();
        self::assertStringContainsString('RedactedGroup', $doc);

        $this->expectException(\TypeError::class);
        (new Correlator(self::fleet()))->links(['api' => [new \stdClass()]]); // @phpstan-ignore argument.type
    }

    public function testTheFindingCarriesNoConsumerTemplate(): void
    {
        $f = (new Correlator(self::fleet()))->findings('platform', ['api' => [self::origin()], 'bff-a' => [self::consumer(template: 'GET <URL:api>/SECRETSHAPE returned <N>')]]);

        self::assertStringNotContainsString('SECRETSHAPE', json_encode($f[0]->toArray(), JSON_THROW_ON_ERROR));
    }
}
