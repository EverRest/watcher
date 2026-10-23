<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;

final class ReadFilterSelectorTest extends TestCase
{
    public function testReadFilterRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadFilter(Level::Error, [], 0);
    }

    public function testReadFilterKeepsValues(): void
    {
        $f = new ReadFilter(Level::Warning, ['/x/'], 5);

        self::assertSame(Level::Warning, $f->minLevel);
        self::assertSame(['/x/'], $f->watchPatterns);
        self::assertSame(5, $f->eventCap);
    }

    public function testSelectorRejectsEmptyTagKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StreamSelector(['' => 'v']);
    }

    public function testSelectorKeepsTags(): void
    {
        self::assertSame(['app' => 'a'], (new StreamSelector(['app' => 'a']))->tags);
    }
}
