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
}
