<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use PHPUnit\Framework\TestCase;
use Watcher\Reduce\GroupStatus;

final class GroupStatusTest extends TestCase
{
    public function testRules(): void
    {
        self::assertSame(GroupStatus::New, GroupStatus::resolve(5, null, 3.0));
        self::assertSame(GroupStatus::New, GroupStatus::resolve(5, 0, 3.0));
        self::assertSame(GroupStatus::Grown, GroupStatus::resolve(30, 10, 3.0));
        self::assertSame(GroupStatus::Grown, GroupStatus::resolve(31, 10, 3.0));
        self::assertSame(GroupStatus::Unchanged, GroupStatus::resolve(29, 10, 3.0));
        self::assertSame(GroupStatus::Unchanged, GroupStatus::resolve(1, 10, 3.0));
    }

    public function testFloatValueAndExactFactorBoundary(): void
    {
        self::assertSame(GroupStatus::Grown, GroupStatus::resolve(15, 5.0, 3.0));
        self::assertSame(GroupStatus::Unchanged, GroupStatus::resolve(14, 5.0, 3.0));
        self::assertSame(GroupStatus::Grown, GroupStatus::resolve(4, 1.3333, 3.0));
        self::assertSame(GroupStatus::Unchanged, GroupStatus::resolve(3, 1.3333, 3.0));
    }

    public function testExpected(): void
    {
        // value 0 but the fingerprint was seen in an earlier window: not new, not grown
        self::assertSame(GroupStatus::Expected, GroupStatus::resolve(5, 0.0, 3.0, true));
        self::assertSame(GroupStatus::Expected, GroupStatus::resolve(5, null, 3.0, true));
        self::assertSame(GroupStatus::New, GroupStatus::resolve(5, 0.0, 3.0, false));
        // seen with a positive value: normal rules apply
        self::assertSame(GroupStatus::Grown, GroupStatus::resolve(30, 10.0, 3.0, true));
        self::assertSame(GroupStatus::Unchanged, GroupStatus::resolve(5, 10.0, 3.0, true));
    }
}
