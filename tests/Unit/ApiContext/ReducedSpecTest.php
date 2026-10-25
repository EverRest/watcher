<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use PHPUnit\Framework\TestCase;
use Watcher\ApiContext\OperationSummary;
use Watcher\ApiContext\ReducedSpec;

final class ReducedSpecTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $spec = new ReducedSpec('/api', [new OperationSummary('POST', '/orders/{id}/refund', 'Refund', 'payments', true, '')], 2);
        $back = ReducedSpec::fromCache($spec->toCache('2026-10-24T06:00:00Z'));
        self::assertEquals($spec, $back);
        self::assertSame('2026-10-24T06:00:00Z', $spec->toCache('2026-10-24T06:00:00Z')['readAt']);
    }

    public function testRejectsWrongVersionAndMalformedRows(): void
    {
        $ok = ['v' => 1, 'base' => '', 'ops' => [['GET', '/a', 's', 't', false, '']], 'dropped' => 0, 'readAt' => 'x'];
        self::assertNotNull(ReducedSpec::fromCache($ok));
        self::assertNull(ReducedSpec::fromCache(['v' => 2] + $ok));
        self::assertNull(ReducedSpec::fromCache(['ops' => [['GET', '/a']]] + $ok));
        self::assertNull(ReducedSpec::fromCache(['ops' => [['GET', '/a', 's', 't', 'no', '']]] + $ok));
        self::assertNull(ReducedSpec::fromCache(['ops' => [['FETCH', '/a', 's', 't', false, '']]] + $ok));
        self::assertNull(ReducedSpec::fromCache(['ops' => [['GET', 'a', 's', 't', false, '']]] + $ok));
        self::assertNull(ReducedSpec::fromCache(['base' => 5] + $ok));
        self::assertNull(ReducedSpec::fromCache(['dropped' => -1] + $ok));
        self::assertNull(ReducedSpec::fromCache(['ops' => 'x'] + $ok));
    }

    public function testBySegmentsIndexesStrippedTemplates(): void
    {
        $a = new OperationSummary('GET', '/orders/{id}', '', '', false, '');
        $b = new OperationSummary('GET', '/api/orders/{id}/x', '', '', false, '');
        $idx = (new ReducedSpec('/api', [$a, $b], 0))->bySegments();
        self::assertSame([2, 3], array_keys($idx));
        self::assertSame($a, $idx[2][0][0]);
        self::assertSame($b, $idx[3][0][0]);
    }
}
