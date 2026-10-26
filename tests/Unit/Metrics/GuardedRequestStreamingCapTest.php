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
 * Review M1 (spec 009): a chunked or gzip body without Content-Length is capped while it streams, through the sink of ResponseLimit::options.
 */
final class GuardedRequestStreamingCapTest extends TestCase
{
    public function testEveryGuardedRequestCarriesTheStreamingCap(): void
    {
        $seen = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $stack = HandlerStack::create(static function ($request, array $options) use ($mock, &$seen) {
            $seen = $options;

            return $mock($request, $options);
        });
        (new GuardedRequest(new Client(['handler' => $stack]), new Retry(static function (): void {
        }, 1), 'metrics'))->send('GET', 'https://prom.invalid/x', []);

        self::assertArrayHasKey('sink', $seen);
    }
}
