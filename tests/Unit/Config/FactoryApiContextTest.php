<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class FactoryApiContextTest extends TestCase
{
    private const TOKEN = 'CANARY-openapi-token-1';
    private const URL = 'https://docs.example.com/openapi.json?k=CANARY-openapi-url-2';

    /** @param array<string, mixed>|null $api */
    private static function config(?array $api): WatchConfig
    {
        $c = [
            'service' => 'svc', 'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.com']],
            'analysis' => ['api_key' => 'sk', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/X']],
        ];
        if ($api !== null) {
            $c['api_context'] = $api;
        }

        return WatchConfig::fromArray($c);
    }

    /** @param array<string, mixed>|null $api */
    private function reader(?array $api, ?MockHandler $mock = null): ?\Watcher\ApiContext\ApiContextReader
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-25T06:00:00Z'));

        return Factory::apiContextReader(
            config: self::config($api),
            http: new Client(['handler' => HandlerStack::create($mock ?? new MockHandler())]),
            state: new InMemoryStateStore($clock),
            redactor: new Redactor(new Normalizer()),
            clock: $clock,
        );
    }

    public function testAbsentOrDisabledBuildsNothing(): void
    {
        self::assertNull($this->reader(null));
        self::assertNull($this->reader(['enabled' => false, 'source' => self::URL]));
    }

    public function testConfiguredReaderReadsReducesAndCaches(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"openapi":"3.0.0","paths":{"/orders/{id}":{"get":{"summary":"Get an order"}}}}')]);
        $r = $this->reader(['source' => self::URL, 'auth_token' => self::TOKEN], $mock);

        self::assertNotNull($r);
        self::assertSame('miss', $r->spec()->cache);
        self::assertSame('hit', $r->spec()->cache);
        self::assertSame('Get an order', $r->spec()->spec?->ops[0]->summary);
    }

    public function testNoDumpFormatOfTheLiveReaderShowsTheTokenOrTheUrl(): void
    {
        $r = $this->reader(['source' => self::URL, 'auth_token' => self::TOKEN]);
        self::assertNotNull($r);
        $objects = [$r, (new \ReflectionProperty($r, 'source'))->getValue($r)];

        foreach ($objects as $o) {
            $dumps = [print_r($o, true), var_export($o, true), (string) json_encode($o)];
            ob_start();
            var_dump($o);
            $dumps[] = (string) ob_get_clean();
            try {
                $dumps[] = serialize($o);
            } catch (\Throwable $e) {
                $dumps[] = $e->getMessage();
            }
            foreach ($dumps as $d) {
                self::assertStringNotContainsString('CANARY', $d, $o::class);
            }
        }
    }
}
