<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use PHPUnit\Framework\TestCase;
use Watcher\ApiContext\OperationSummary;
use Watcher\ApiContext\ReducedSpec;
use Watcher\ApiContext\RouteExtractor;
use Watcher\ApiContext\RouteMatcher;
use Watcher\Reduce\Normalizer;

/**
 * Raw log line -> Normalizer -> RouteExtractor -> RouteMatcher. Documents a hard limit (decision A): the Normalizer
 * collapses paths of three or more segments into `<PATH>/…/tail`, so such routes are not matched. No guessing.
 */
final class RouteRoundTripTest extends TestCase
{
    private function match(string $rawLine): ?OperationSummary
    {
        $route = (new RouteExtractor())->extract((new Normalizer())->normalize($rawLine));
        $spec = new ReducedSpec('', [
            new OperationSummary('GET', '/orders/{id}', 'Get an order', '', false, ''),
            new OperationSummary('POST', '/orders/{id}/refund', 'Refund', '', false, ''),
            new OperationSummary('GET', '/health', 'Health', '', false, ''),
            new OperationSummary('GET', '/users/{id}/orders', 'User orders', '', false, ''),
        ], 0);

        return $route === null ? null : (new RouteMatcher())->match($route, $spec);
    }

    public function testShortRoutesSurviveMasking(): void
    {
        self::assertSame('/orders/{id}', $this->match('GET /orders/8812')?->template);
        self::assertSame('/health', $this->match('GET /health')?->template);
    }

    public function testRoutesOfThreeOrMoreSegmentsAreNotMatched(): void
    {
        self::assertNull($this->match('POST /orders/8812/refund failed'), 'Normalizer: POST <PATH>/…/<N>/refund');
        self::assertNull($this->match('GET /users/42/orders'));
    }
}
