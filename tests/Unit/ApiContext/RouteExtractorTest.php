<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\ApiContext\RouteExtractor;
use Watcher\ApiContext\Segment;

final class RouteExtractorTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string}> template, method, path as seen by the matcher ("!" = unmatchable) */
    public static function routes(): array
    {
        return [
            'method and path' => ['POST /orders/<ID>/refund failed', 'POST', '/orders/:/refund'],
            'no method' => ['/orders/<ID>', null, '/orders/:'],
            'lower-case verb is not a method' => ['post /orders/<ID>', null, '/orders/:'],
            'query cut' => ['GET /health?probe=1', 'GET', '/health'],
            'trailing punctuation' => ['GET /orders/<ID>, retrying', 'GET', '/orders/:'],
            'trailing paren and dot' => ['failed (GET /orders/<ID>).', 'GET', '/orders/:'],
            'trailing slash' => ['GET /health/', 'GET', '/health'],
            'double slash' => ['GET /a//b', 'GET', '/a/b'],
            'root' => ['GET /', 'GET', '/'],
            'quote before path' => ['url "/health" down', null, '/health'],
            'every param placeholder' => ['/<ID>/<N>/<TOKEN>/<EMAIL>/<NAME>/<STR>/<USER>', null, '/:/:/:/:/:/:/:'],
            'no route in prose' => ['a/b/c 10/12', null, null],
            'lone slash in prose' => ['retry 3 / 5 failed', null, null],
            'double slash in prose' => ['a // b', null, null],
            'lone slash then a real route' => ['3 / 5 then GET /health', 'GET', '/health'],
            'null template' => [null, null, null],
            'no slash at all' => ['connection refused', null, null],
            'path placeholder' => ['GET /files/<PATH>', 'GET', '!'],
            'ellipsis' => ['GET /files/<PATH>/…/x.png', 'GET', '!'],
            'mixed segment' => ['GET /orders/order-<N>', 'GET', '!'],
            'placeholder with extension' => ['GET /files/<ID>.json', 'GET', '!'],
            'url placeholder' => ['GET /go/<URL>', 'GET', '!'],
            'host placeholder segment' => ['GET /h/<HOST:x>', 'GET', '!'],
            'path starting inside a placeholder' => ['ECONNREFUSED <HOST:db>/orders/<ID>', null, null],
            'first route only' => ['GET /a then GET /b', 'GET', '/a'],
        ];
    }

    #[DataProvider('routes')]
    public function testExtraction(?string $template, ?string $method, ?string $shape): void
    {
        $route = (new RouteExtractor())->extract($template);
        if ($shape === null) {
            self::assertNull($route);

            return;
        }
        self::assertNotNull($route);
        self::assertSame($method, $route->method);
        self::assertSame($shape === '!', $route->unmatchable);
        if ($shape !== '!') {
            $got = '/' . implode('/', array_map(static fn (Segment $s): string => $s->kind === Segment::PARAM ? ':' : $s->text, $route->segments));
            self::assertSame($shape, $got === '/' ? '/' : $got);
        }
    }
}
