<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Source\GuardedRequest;
use Watcher\Source\ResponseLimit;
use Watcher\Source\Retry;

/**
 * CHARACTERISATION (spec 009 owner decision 2026-10-25, same pattern as HttpTargetSharedClassifierTest): ResponseLimit::options has
 * no streaming sink yet, so a chunked or gzip body without Content-Length is only bounded by the call budget and the post-decode check.
 * ponytail: the sink lands with lane C (feat/k8s-c-020); this test then becomes real and proves GuardedRequest sends it. No second sink here.
 */
final class GuardedRequestStreamingCapTest extends TestCase
{
    public function testEveryGuardedRequestCarriesTheStreamingCap(): void
    {
        /** @var array<string, mixed> $options */
        $options = ResponseLimit::options(new \ArrayObject());
        if (!array_key_exists('sink', $options)) {
            self::markTestSkipped('characterisation: ResponseLimit::options has no streaming sink yet (chunked/gzip-bomb case)');
        }

        $seen = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $stack = HandlerStack::create(static function ($request, array $options) use ($mock, &$seen) {
            $seen = $options;

            return $mock($request, $options);
        });
        (new GuardedRequest(new Client(['handler' => $stack]), new Retry(static function (): void {
        }, 1), 'metrics'))->send('GET', 'https://prom.example.com/x', []);

        self::assertArrayHasKey('sink', $seen);
    }
}
