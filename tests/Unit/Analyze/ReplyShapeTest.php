<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\ModelReply;

/** A reply carries text, stop reason and model only; token counts are checked and discarded (research R2). Red until US1. */
final class ReplyShapeTest extends TestCase
{
    public function testModelReplyHasNoTokenCount(): void
    {
        $names = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(ModelReply::class))->getProperties());

        self::assertSame(['text', 'stopReason', 'model'], $names);
    }
}
