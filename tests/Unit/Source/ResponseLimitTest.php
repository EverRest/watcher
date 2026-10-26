<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/** The streaming cap every backend gets from ResponseLimit::options() (Loki, Datadog, Kubernetes). */
final class ResponseLimitTest extends TestCase
{
    public function testTheSinkAbortsTheWriteThatCrossesTheCapAndNamesTheCause(): void
    {
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        $o = ResponseLimit::options($tripped, 100);

        $o['sink']->write(str_repeat('x', 100));
        self::assertArrayNotHasKey('large', $tripped->getArrayCopy());
        try {
            $o['sink']->write('x');
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertTrue($e->tooLarge);
        }
        self::assertTrue($tripped['large']);
        self::assertTrue($o['decode_content'], 'the count is of decoded bytes');
    }

    public function testEveryCallGetsAFreshSinkSoARetryStartsACleanCount(): void
    {
        $a = ResponseLimit::options(new \ArrayObject(), 10)['sink'];
        $b = ResponseLimit::options(new \ArrayObject(), 10)['sink'];
        $a->write(str_repeat('x', 10));

        $b->write(str_repeat('x', 10));
        self::assertNotSame($a, $b);
    }
}
