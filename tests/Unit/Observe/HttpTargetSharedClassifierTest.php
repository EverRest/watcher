<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;
use Watcher\Observe\HttpTarget;
use Watcher\Source\ResponseLimit;

/**
 * CHARACTERISATION tests for the two review findings (M2, M5) that need the shared helpers of develop (SecureUrl::problem with
 * the internal-host classifier, a streaming cap in ResponseLimit::options). Each one skips while the helper is absent and
 * becomes a real assertion once it is merged: do not delete them, they are the acceptance test of that merge.
 */
final class HttpTargetSharedClassifierTest extends TestCase
{
    private static function classifierPresent(): bool
    {
        return (new \ReflectionMethod(SecureUrl::class, 'problem'))->getNumberOfParameters() >= 4;
    }

    /** @return array<string, array{string, bool}> url, allow_private_network */
    public static function internalTargets(): array
    {
        return [
            'Oracle metadata 192.0.0.192' => ['https://192.0.0.192/', false],
            'benchmark range 198.18.0.1' => ['https://198.18.0.1/', false],
            'IPv6 [::7f00:1] (loopback in disguise)' => ['https://[::7f00:1]/', false],
            'IPv4-compatible [::127.0.0.1]' => ['https://[::127.0.0.1]/', false],
            'ECS credentials 169.254.170.2, even with private networks on' => ['https://169.254.170.2/', true],
            'Alibaba metadata 100.100.100.200, even with private networks on' => ['https://100.100.100.200/', true],
        ];
    }

    #[DataProvider('internalTargets')]
    public function testInternalAndMetadataAddressesAreRefused(string $url, bool $allowPrivate): void
    {
        if (!self::classifierPresent()) {
            self::markTestSkipped('characterisation: the shared SecureUrl classifier (4-argument problem()) is not on this branch yet');
        }

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessageMatches('/^observe\.http\.url (host is not allowed|must be an https URL with a host)$/');
        HttpTarget::parse($url, [], false, $allowPrivate, null);
    }

    public function testTheStreamingResponseCapAbortsAnOversizedBodyAtTheCapInOneAttempt(): void
    {
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        $attempts = 0;
        $written = 0;

        try {
            (new \Watcher\Source\Retry(static function (int $s): void {
            }, 30))->send(function () use (&$attempts, &$written, $tripped): \Psr\Http\Message\ResponseInterface {
                ++$attempts;
                $sink = ResponseLimit::options($tripped, 1024)['sink']; // built inside the retry closure, so a retry starts a fresh count
                for ($i = 0; $i < 1000; ++$i) { // 512 KB offered in 512-byte chunks, as a chunked reply without Content-Length would
                    if ($sink->write(str_repeat('x', 512)) < 512) { // a short write: curl ends the transfer and Guzzle reports it
                        throw new \GuzzleHttp\Exception\RequestException('cURL error 23', new \GuzzleHttp\Psr7\Request('GET', 'http://x/'));
                    }
                    $written += 512;
                }

                return new Response(200);
            }, 'target', true, $tripped);
            self::fail('expected the abort');
        } catch (\Watcher\Source\SourceUnavailable $e) {
            self::assertTrue($e->tooLarge, 'named as the response cap');
        }

        self::assertSame(1, $attempts, 'the abort is not retried');
        self::assertLessThanOrEqual(1024, $written, 'the write that crossed the cap is the last one: the body is not read to the end');
        self::assertTrue($tripped['large']);
    }
}
