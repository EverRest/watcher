<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;
use Watcher\Config\WatchConfig;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Source\LokiSource;

/** S2-08: https at run time, http only for loopback and only when asked, no redirects, no credentials in a URL. */
final class SecureUrlTest extends TestCase
{
    /** @return array<string, array{string, bool, bool}> url, allow_insecure, ok */
    public static function urls(): array
    {
        return [
            'https' => ['https://hooks.example/a', false, true],
            'http refused' => ['http://hooks.example/a', false, false],
            'http remote even when allowed' => ['http://hooks.example/a', true, false],
            'http loopback not allowed by default' => ['http://127.0.0.1:3100', false, false],
            'http loopback allowed' => ['http://127.0.0.1:3100', true, true],
            'http localhost allowed' => ['http://localhost:3100/x', true, true],
            'http ipv6 loopback allowed' => ['http://[::1]:3100', true, true],
            'http 127.1.2.3' => ['http://127.1.2.3', true, true],
            'rfc1918 10/8 allowed' => ['http://10.1.2.3:3100', true, true],
            'rfc1918 172.16/12 allowed' => ['http://172.31.0.9:3100', true, true],
            'rfc1918 192.168/16 allowed' => ['http://192.168.1.5:3100', true, true],
            'rfc1918 not allowed by default' => ['http://10.1.2.3:3100', false, false],
            '172.32 is public' => ['http://172.32.0.1:3100', true, false],
            'cluster service allowed' => ['http://loki.monitoring.svc:3100', true, true],
            'cluster fqdn allowed' => ['http://loki.monitoring.svc.cluster.local:3100', true, true],
            'svc lookalike' => ['http://loki.svc.evil.example', true, false],
            'localhost lookalike' => ['http://localhost.evil.example', true, false],
            'credentials' => ['https://u:p@hooks.example/a', false, false],
            'user only' => ['https://u@hooks.example/a', false, false],
            'no host' => ['https:///x', false, false],
            'bad port' => ['https://hooks.example:port/x', false, false],
            'other scheme' => ['ftp://hooks.example/x', false, false],
            'empty' => ['', false, false],
        ];
    }

    #[DataProvider('urls')]
    public function testRule(string $url, bool $allowInsecure, bool $ok): void
    {
        $problem = SecureUrl::problem($url, $allowInsecure);

        self::assertSame($ok, $problem === null, (string) $problem);
        self::assertStringNotContainsString('hooks.example', (string) $problem);
    }

    public function testLokiUrlIsCheckedInTheConfigWithoutEchoingIt(): void
    {
        $base = ['service' => 'svc', 'schedule' => ['run_at' => '06:00'], 'source' => ['driver' => 'loki', 'loki' => ['url' => 'http://loki.example/SECRETPATH']],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], 'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]]], 'notify' => ['digest' => ['webhook' => 'https://hooks.example/a']], 'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/w']]];
        try {
            WatchConfig::fromArray($base);
            self::fail('http Loki URL accepted');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('source.loki.url', $e->getMessage());
            self::assertStringNotContainsString('SECRETPATH', $e->getMessage());
        }
        $base['source']['loki'] = ['url' => 'http://127.0.0.1:3100', 'allow_insecure' => true];
        self::assertSame('svc', WatchConfig::fromArray($base)->service);
    }

    public function testLokiSourceRefusesAnInsecureUrlItself(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LokiSource(new Client(), 'http://loki.example');
    }

    public function testSlackAllowInsecureOnlyForLoopback(): void
    {
        $hist = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
        $stack->push(Middleware::history($hist));
        $n = new SlackWebhookNotifier(new Client(['handler' => $stack]));

        $n->send(new Message('x', []), new Destination('http://127.0.0.1:9/hook', 'slack', allowInsecure: true));
        self::assertIsArray($hist);
        self::assertCount(1, $hist);

        $this->expectException(DeliveryFailed::class);
        $n->send(new Message('x', []), new Destination('http://127.0.0.1:9/hook'));
    }

    public function testSlackNeverFollowsARedirect(): void
    {
        $hist = [];
        $stack = HandlerStack::create(new MockHandler([new Response(302, ['Location' => 'https://evil.example/']), new Response(200)]));
        $stack->push(Middleware::history($hist));
        $n = new SlackWebhookNotifier(new Client(['handler' => $stack]));

        try {
            $n->send(new Message('x', []), new Destination('https://hooks.example/a'));
            self::fail('a redirect counted as delivered');
        } catch (DeliveryFailed $e) {
            self::assertStringContainsString('HTTP 302', $e->getMessage());
        }
        self::assertIsArray($hist);
        self::assertCount(1, $hist);
        self::assertFalse($hist[0]['options']['allow_redirects']);
        self::assertTrue($hist[0]['options']['verify']);
    }
}
