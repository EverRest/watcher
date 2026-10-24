<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\FleetOrder;
use Watcher\Fleet\ServiceEntry;

final class FleetOrderTest extends TestCase
{
    /**
     * @param list<string> $deps names of declared dependencies
     * @param list<string>|null $provides
     */
    private static function entry(string $name, int $priority = 0, array $deps = [], ?array $provides = null): ServiceEntry
    {
        $config = FleetConfigTest::service($name);
        foreach ($deps as $d) {
            $config['dependencies'][$d] = ['stream_tags' => ['app' => $d]];
        }

        return new ServiceEntry(WatchConfig::fromArray($config), $priority, $provides);
    }

    /**
     * @param list<ServiceEntry> $in
     * @return list<string>
     */
    private static function names(array $in): array
    {
        return array_map(static fn (ServiceEntry $e): string => $e->config->service, FleetOrder::of($in));
    }

    public function testPriorityDescendingThenNameAscending(): void
    {
        self::assertSame(['c', 'a', 'b'], self::names([self::entry('b'), self::entry('a'), self::entry('c', 5)]));
    }

    public function testDependenciesInTheFleetComeBeforeTheirConsumers(): void
    {
        // alphabetical and priority would put "a-bff" first
        self::assertSame(['api', 'a-bff'], self::names([self::entry('a-bff', 9, ['api']), self::entry('api')]));
    }

    public function testDependencyIsResolvedThroughProvides(): void
    {
        self::assertSame(['zeta', 'a-bff'], self::names([self::entry('a-bff', 0, ['core']), self::entry('zeta', 0, [], ['core'])]));
    }

    public function testDependencyNotInTheFleetIsIgnored(): void
    {
        self::assertSame(['a', 'b'], self::names([self::entry('b'), self::entry('a', 0, ['payments'])]));
    }

    public function testSelfProvidedDependencyIsIgnored(): void
    {
        self::assertSame(['a'], self::names([self::entry('a', 0, ['a'])]));
    }

    public function testCycleKeepsTheSortedOrderAndTerminates(): void
    {
        self::assertSame(['a', 'b'], self::names([self::entry('b', 0, ['a']), self::entry('a', 0, ['b'])]));
    }

    public function testConsumerOfACycleStillFollowsIt(): void
    {
        $in = [self::entry('c', 9, ['b']), self::entry('b', 0, ['a']), self::entry('a', 0, ['b'])];

        self::assertSame(['a', 'b', 'c'], self::names($in));
    }

    public function testOrderIsDeterministicForShuffledInput(): void
    {
        $in = [
            self::entry('api', 10), self::entry('bff-a', 0, ['api']), self::entry('bff-b', 0, ['api']),
            self::entry('jobs', 3, ['bff-a']), self::entry('docs', 0),
        ];
        $expected = self::names($in);

        mt_srand(7);
        for ($i = 0; $i < 20; ++$i) {
            shuffle($in);
            self::assertSame($expected, self::names($in));
        }
        // dependencies first, then priority, then name
        self::assertSame(['api', 'bff-a', 'jobs', 'bff-b', 'docs'], $expected);
    }
}
