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
            'enabled' => true,
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
        $seen = self::read(['driver' => 'loki', 'level_mode' => 'label', 'loki' => ['url' => 'https://loki.invalid']], '{"status":"success","data":{"resultType":"streams","result":[]}}');

        parse_str($seen[0]->getUri()->getQuery(), $q);
        self::assertStringContainsString('level=~', is_string($q['query'] ?? null) ? $q['query'] : '');
    }

    public function testMaxResponseMbReachesTheSource(): void
    {
        $body = '{"status":"success","data":{"result":[]},"pad":"' . str_repeat('x', 1_500_000) . '"}';
        $src = Factory::source(WatchConfig::fromArray(self::config(['driver' => 'loki', 'max_response_mb' => 1, 'loki' => ['url' => 'https://loki.invalid']])), new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)]))]));

        $this->expectException(\Watcher\Source\SourceUnavailable::class);
        $this->expectExceptionMessage('too large');
        $src->totals(new Window(new \DateTimeImmutable('2026-10-07T06:00:00Z'), new \DateTimeImmutable('2026-10-08T06:00:00Z')), new StreamSelector(['app' => 'svc']));
    }

    public function testDatadogMapsNewestFirstAndLevelMode(): void
    {
        $seen = self::read(['driver' => 'datadog', 'level_mode' => 'all', 'datadog' => ['api_key' => 'a', 'app_key' => 'b', 'newest_first' => true]], '{"data":[],"meta":{"page":{}}}');

        $body = (string) $seen[0]->getBody();
        self::assertStringContainsString('"sort":"-timestamp"', $body);
        self::assertStringNotContainsString('status:(', $body, 'level_mode all pushes no status filter');
    }

    public function testModelCatalogExistsOnlyForAnthropic(): void
    {
        $c = WatchConfig::fromArray(self::config(['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid']]));

        self::assertInstanceOf(\Watcher\Operate\AnthropicModelCatalog::class, Factory::modelCatalog($c, new Client()));
    }

    public function testDoctorIsBuiltFromConfigWithoutAnyCall(): void
    {
        $handler = new MockHandler([]);
        $doctor = Factory::doctor(self::config(['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid']]), new Client(['handler' => HandlerStack::create($handler)]));

        self::assertInstanceOf(\Watcher\Operate\Doctor::class, $doctor);
        self::assertCount(0, $handler);
    }

    public function testDoctorOnAnInvalidConfigThrowsInvalidConfigNamingTheSetting(): void
    {
        $this->expectException(\Watcher\Config\InvalidConfig::class);

        Factory::doctor(['service' => 'svc']);
    }

    public function testHeartbeatStatusReadsTheConfiguredStore(): void
    {
        $dir = sys_get_temp_dir() . '/watcher-hb-' . bin2hex(random_bytes(4));
        $config = self::config(['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid']]);
        $config['state'] = ['driver' => 'file', 'file' => ['path' => $dir]];
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-08T06:00:00Z'));

        self::assertSame('unknown', Factory::heartbeatStatus($config, $clock)->state);

        $hb = new \Watcher\Operate\Heartbeat(Factory::state(WatchConfig::fromArray($config), $clock), $clock, 'svc', new \DateTimeZone('UTC'), 2592000);
        $hb->recordRun(\Watcher\RunOutcome::Quiet, $clock->now()->modify('-1 day'), $clock->now(), $clock->now());
        self::assertSame('ok', Factory::heartbeatStatus($config, $clock)->state);
        $clock->advance('+37 hours');
        self::assertSame('stale', Factory::heartbeatStatus($config, $clock)->state);
    }

    public function testTheFileStoreGetsScheduleLockTtlAsItsLockLifetime(): void
    {
        $dir = sys_get_temp_dir() . '/watcher-lt-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $config = self::config(['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid']]);
        $config['state'] = ['driver' => 'file', 'file' => ['path' => $dir]];
        $config['schedule'] = ['run_at' => '06:00', 'lock_ttl' => 60];
        $sidecar = $dir . '/' . str_repeat('a', 64) . '.json.lock';
        touch($sidecar, time() - 120); // older than 60 s, younger than the 900 s default
        $store = Factory::state(WatchConfig::fromArray($config), new FrozenClock(new \DateTimeImmutable()));

        self::assertInstanceOf(\Watcher\State\Purgeable::class, $store);
        $store->purge();

        self::assertFileDoesNotExist($sidecar);
        @unlink($dir . '/.purge');
        @rmdir($dir);
    }

    public function testNotifierRoutesAndBoundsTheRetryWaitByLockTtl(): void
    {
        $body = '{"ok":false,"error_code":429,"parameters":{"retry_after":30}}';
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(429, [], $body)]))]);
        $notifier = Factory::notifier($client, 100); // budget min(60, max(1, 100/10)) = 10 s < 30 s: no sleep, fails at once

        self::assertInstanceOf(\Watcher\Notify\RoutingNotifier::class, $notifier);
        $this->expectException(\Watcher\Notify\DeliveryFailed::class);
        $this->expectExceptionMessage('telegram delivery failed: HTTP 429');
        $notifier->send(new \Watcher\Notify\Message('x'), \Watcher\Notify\Destination::telegram('123456:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789', '-100123'));
    }

    public function testTheStateStoreOfARedisConfigShowsNoPasswordInAnyDumpOrExport(): void
    {
        $config = self::config(['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid']]);
        $config['state'] = ['driver' => 'redis', 'redis' => ['host' => '127.0.0.1', 'password' => 'REDISPWzqkvcanary']];
        $c = WatchConfig::fromArray($config);
        $clock = new \Watcher\Schedule\SystemClock();
        $objects = [Factory::state($c, $clock), Factory::state($c, $clock, null)];

        foreach ($objects as $store) {
            ob_start();
            var_dump($store);
            var_dump((array) $store);
            print_r($store);
            print_r((array) $store);
            $dump = (string) ob_get_clean() . var_export($store, true) . var_export((array) $store, true) . json_encode($store);

            self::assertStringNotContainsString('REDISPWzqkvcanary', $dump);
        }
    }
}
