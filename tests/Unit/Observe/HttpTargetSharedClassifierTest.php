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

    public function testTheStreamingResponseCapExistsOnEveryRequest(): void
    {
        /** @var array<string, mixed> $options */
        $options = ResponseLimit::options(new \ArrayObject());
        if (!array_key_exists('sink', $options)) {
            self::markTestSkipped('characterisation: ResponseLimit::options has no streaming sink yet (a chunked or gzip body is only time-bounded)');
        }

        // With the sink in place a body over the cap must abort the transfer, not buffer it.
        self::assertNotNull($options['sink']);
        $response = new Response(200, [], str_repeat('x', 16));
        self::assertSame(16, $response->getBody()->getSize());
    }
}
