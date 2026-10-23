<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Watcher\Context\DependencyResolver;

final class DependencyResolverTest extends TestCase
{
    private const QUIET = ['error' => 1, 'info' => 100];

    public function testReferenceByExactHost(): void
    {
        $plan = (new DependencyResolver())->plan(['GET <URL:payments> failed'], ['payments' => self::QUIET], ['payments' => self::QUIET], 3.0);
        self::assertSame(['payments'], $plan->linesFor);
        self::assertSame(['payments' => [0]], $plan->referencedBy);
        self::assertSame(0, $plan->undeclaredHosts);
    }

    public function testReferenceByDeclaredNameButNotUndeclared(): void
    {
        $plan = (new DependencyResolver())->plan(
            ['x', 'timeout <HOST:payments>', 'timeout <HOST>'],
            ['payments' => self::QUIET],
            ['payments' => self::QUIET],
            3.0,
        );
        self::assertSame(['payments' => [1]], $plan->referencedBy);
        self::assertSame(1, $plan->undeclaredHosts);
    }

    public function testGrowthTriggersLines(): void
    {
        $r = new DependencyResolver();
        $grown = $r->plan([], ['db' => ['error' => 30, 'critical' => 0]], ['db' => ['error' => 10]], 3.0);
        self::assertSame(['db'], $grown->linesFor);
        $below = $r->plan([], ['db' => ['error' => 29]], ['db' => ['error' => 10]], 3.0);
        self::assertSame([], $below->linesFor);
    }

    public function testGrowthCountsErrorAndAboveOnly(): void
    {
        $plan = (new DependencyResolver())->plan([], ['db' => ['info' => 900, 'error' => 1]], ['db' => ['info' => 1, 'error' => 1]], 3.0);
        self::assertSame([], $plan->linesFor);
    }

    public function testFirstSightingTurnsGrowthOff(): void
    {
        $plan = (new DependencyResolver())->plan([], ['db' => ['error' => 999]], ['db' => null], 3.0);
        self::assertSame([], $plan->linesFor);
        self::assertSame(['db' => true], $plan->firstSighting);
    }

    public function testFirstSightingStillReadWhenReferenced(): void
    {
        $plan = (new DependencyResolver())->plan(['<URL:db> down'], ['db' => ['error' => 1]], ['db' => null], 3.0);
        self::assertSame(['db'], $plan->linesFor);
    }

    public function testZeroPreviousGrowsWhenCurrentPositive(): void
    {
        $plan = (new DependencyResolver())->plan([], ['db' => ['error' => 2], 'q' => ['error' => 0]], ['db' => ['error' => 0], 'q' => ['error' => 0]], 3.0);
        self::assertSame(['db'], $plan->linesFor);
        self::assertSame(['db' => false, 'q' => false], $plan->firstSighting);
    }

    public function testQuietRunReadsNoLines(): void
    {
        $plan = (new DependencyResolver())->plan(['plain text'], ['a' => self::QUIET, 'b' => self::QUIET], ['a' => self::QUIET, 'b' => self::QUIET], 3.0);
        self::assertSame([], $plan->linesFor);
        self::assertSame([], $plan->referencedBy);
    }

    public function testHostsOnlyFromPlaceholders(): void
    {
        $plan = (new DependencyResolver())->plan(['calling payments.example.com now', 'payments is down'], ['payments' => self::QUIET], ['payments' => self::QUIET], 3.0);
        self::assertSame([], $plan->linesFor);
        self::assertSame(0, $plan->undeclaredHosts);
    }

    public function testWithheldTemplateSkipped(): void
    {
        $plan = (new DependencyResolver())->plan([null, '<URL:db> x'], ['db' => self::QUIET], ['db' => self::QUIET], 3.0);
        self::assertSame(['db' => [1]], $plan->referencedBy);
    }

    public function testUndeclaredHostsAreCountedPerDistinctTemplate(): void
    {
        $templates = ['POST <URL> timed out', 'POST <URL> timed out', 'dns <HOST> and <URL> down', 'plain', null, '<URL:db> ok'];
        $plan = (new DependencyResolver())->plan($templates, ['db' => self::QUIET], ['db' => self::QUIET], 3.0);

        self::assertSame(3, $plan->undeclaredHosts);
        self::assertSame(['db' => [5]], $plan->referencedBy);
    }
}
