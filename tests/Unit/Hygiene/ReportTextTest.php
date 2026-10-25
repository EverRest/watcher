<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\ReportText;

/** Review M2: nothing a log writer controls may carry a terminal or bidi control into a report, a notice or a pasted rule. */
final class ReportTextTest extends TestCase
{
    public function testControlFormatAndInvisibleCharactersAreRemovedAndBreaksBecomeSpaces(): void
    {
        $dirty = "a\x1b[31mred\x1b]0;t\x07\u{2800}\u{3164}\u{115F}\u{1160}\u{FFA0}\u{034F}\u{FE0F}\u{202E}\u{200B}\u{0085}b\nc\td";

        self::assertSame('a[31mred]0;tb c d', ReportText::clean($dirty));
    }

    public function testSafeCleansBeforeEscapingForANotice(): void
    {
        self::assertSame('ab', ReportText::safe(false, "a\u{202E}b"));
        self::assertStringNotContainsString("\x1b", ReportText::safe(true, "x\x1b[2Jy <!channel>"));
    }
}
