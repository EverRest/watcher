<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/** The streaming cap every backend gets from ResponseLimit::options() (Loki, Datadog, Kubernetes). */
final class ResponseLimitTest extends TestCase
{
    public function testTheSinkRefusesTheWriteThatCrossesTheCapAndNamesTheCause(): void
    {
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        $o = ResponseLimit::options($tripped, 100);

        self::assertSame(100, $o['sink']->write(str_repeat('x', 100)));
        self::assertArrayNotHasKey('large', $tripped->getArrayCopy());
        // 0 bytes accepted: curl aborts the transfer at once (a thrown exception would let it run to the end of the reply)
        self::assertSame(0, $o['sink']->write('x'));
        self::assertTrue($tripped['large']);
        self::assertTrue($o['decode_content'], 'the count is of decoded bytes');
    }

    public function testAReplyCutByTheSinkStillReportsItsSizeSoADecodeRefusesIt(): void
    {
        // A handler that ignores a short write (a non-curl transport copying the body) stores only the cap. The size still says
        // what was offered, so the reply is refused, not read as a shorter, valid-looking body.
        $o = ResponseLimit::options(new \ArrayObject(), 100);
        $o['sink']->write(str_repeat('x', 100));
        $o['sink']->write(str_repeat('x', 50));

        self::assertSame(150, $o['sink']->getSize());
        $response = new \GuzzleHttp\Psr7\Response(200, [], $o['sink']);
        try {
            ResponseLimit::decode($response, 'test', 100);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertTrue($e->tooLarge);
        }
    }

    public function testEveryCallGetsAFreshSinkSoARetryStartsACleanCount(): void
    {
        $a = ResponseLimit::options(new \ArrayObject(), 10)['sink'];
        $b = ResponseLimit::options(new \ArrayObject(), 10)['sink'];
        $a->write(str_repeat('x', 10));

        $b->write(str_repeat('x', 10));
        self::assertNotSame($a, $b);
    }

    /** @return array<string, array{string}> */
    public static function declaredLengths(): array
    {
        return ['huge digits' => ['99999999999999999999999'], 'just over the cap' => ['101']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('declaredLengths')]
    public function testADeclaredLengthOverTheCapTripsTheCapWithoutACast(string $length): void
    {
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        $on = ResponseLimit::options($tripped, 100)['on_headers'];
        $response = new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => $length]);

        try {
            $on($response);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertTrue($e->tooLarge);
        }
        self::assertTrue($tripped['large']);
    }
}
