<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Context\HttpCodeRepository;

final class HttpCodeRepositoryTest extends TestCase
{
    public function testGitHubRawUsesGetOnly(): void
    {
        $mock = new MockHandler([new Response(200, [], 'class X {}')]);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $repo = new HttpCodeRepository([
            'provider' => 'github',
            'web_url' => 'https://github.com/acme/api',
            'path_map' => [],
            'api_token' => 'secret',
        ], $http);
        self::assertSame('class X {}', $repo->readFile('abc', 'src/Foo.php', 10000));
        $req = $mock->getLastRequest();
        self::assertNotNull($req);
        self::assertSame('GET', $req->getMethod());
        self::assertStringContainsString('raw.githubusercontent.com/acme/api/abc/src/Foo.php', (string) $req->getUri());
    }
}
