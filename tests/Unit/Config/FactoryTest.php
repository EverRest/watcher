<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Schedule\Window;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;
use Watcher\Tests\Fakes\FrozenClock;

final class FactoryTest extends TestCase
{
    public function testSourcesAndSlackKeepTheShortTimeout(): void
    {
        self::assertSame(['timeout' => 30, 'connect_timeout' => 10], Factory::httpOptions());
    }

    public function testTheModelClientWaitsLongerThanSourcesButWithinTheLockBudget(): void
    {
        // Two sequential attempts per model call must fit in a quarter of lock_ttl (default 900 s): 2 x 112 <= 225.
        self::assertSame(['timeout' => 112, 'connect_timeout' => 10], Factory::analyzerHttpOptions());
        self::assertSame(['timeout' => 112, 'connect_timeout' => 10], Factory::analyzerHttpOptions(900));
    }

    public function testTheModelTimeoutIsCappedAndFloored(): void
    {
        self::assertSame(180, Factory::analyzerHttpOptions(86400)['timeout']);
        self::assertSame(10, Factory::analyzerHttpOptions(30)['timeout']);
    }

    public function testTheSdkDoesNotRetryOnItsOwn(): void
    {
        self::assertSame(['maxRetries' => 0], Factory::analyzerRequestOptions());
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function config(array $source): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => $source + ['stream_tags' => ['app' => 'svc']],
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], 'prices' => ['a' => [1, 5, 0.1, 1.25], 'b' => [3, 15, 0.3, 3.75], 'c' => [5, 25, 0.5, 6.25]]],
            'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/watcher-factory-test']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return list<RequestInterface>
     */
    private static function read(array $source, string $body): array
    {
        $seen = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::mapRequest(static function (RequestInterface $r) use (&$seen): RequestInterface {
            $seen[] = $r;

            return $r;
        }));
        $src = Factory::source(WatchConfig::fromArray(self::config($source)), new Client(['handler' => $stack]), new FrozenClock(new \DateTimeImmutable('2026-10-08T06:00:00Z')));
        $window = new Window(new \DateTimeImmutable('2026-10-07T06:00:00Z'), new \DateTimeImmutable('2026-10-08T06:00:00Z'));
        foreach ($src->lines($window, new StreamSelector(['app' => 'svc']), new ReadFilter(Level::Warning, [], 10)) as $_) {
        }

        return $seen;
    }

    public function testLokiLevelModeReachesTheSource(): void
    {
        $seen = self::read(['driver' => 'loki', 'level_mode' => 'label', 'loki' => ['url' => 'http://loki.invalid']], '{"status":"success","data":{"resultType":"streams","result":[]}}');

        parse_str($seen[0]->getUri()->getQuery(), $q);
        self::assertStringContainsString('level=~', is_string($q['query'] ?? null) ? $q['query'] : '');
    }

    public function testDatadogMapsNewestFirstAndLevelMode(): void
    {
        $seen = self::read(['driver' => 'datadog', 'level_mode' => 'all', 'datadog' => ['api_key' => 'a', 'app_key' => 'b', 'newest_first' => true]], '{"data":[],"meta":{"page":{}}}');

        $body = (string) $seen[0]->getBody();
        self::assertStringContainsString('"sort":"-timestamp"', $body);
        self::assertStringNotContainsString('status:(', $body, 'level_mode all pushes no status filter');
    }
}
