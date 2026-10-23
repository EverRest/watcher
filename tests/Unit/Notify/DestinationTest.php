<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Notify\Destination;

final class DestinationTest extends TestCase
{
    private const HOOK = 'https://hooks.example/T000/SECRET-TOKEN';

    public function testWebhookIsAvailableToTheNotifier(): void
    {
        self::assertSame(self::HOOK, (new Destination(self::HOOK))->webhook());
    }

    public function testNoDumpFormatContainsTheWebhook(): void
    {
        $d = new Destination(self::HOOK);

        $dumps = [
            print_r($d, true),
            var_export($d, true),
            (string) json_encode($d),
            (string) json_encode([$d]),
            print_r([$d], true),
        ];
        ob_start();
        var_dump($d);
        $dumps[] = (string) ob_get_clean();

        foreach ($dumps as $dump) {
            self::assertStringNotContainsString('SECRET-TOKEN', $dump);
            self::assertStringNotContainsString(self::HOOK, $dump);
        }
    }

    public function testCannotBeSerialised(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new Destination(self::HOOK));
    }

    public function testHasNoStringCast(): void
    {
        self::assertNotInstanceOf(\Stringable::class, new Destination(self::HOOK));
    }

    public function testNoticeUsesItsOwnWebhookWhenSet(): void
    {
        $c = WatchConfig::fromArray(self::config(['notice' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/notice']]));

        self::assertSame('https://hooks.example/notice', (new Destination($c->notify['notice']['webhook']))->webhook());
        self::assertSame('https://hooks.example/digest', (new Destination($c->notify['digest']['webhook']))->webhook());
    }

    public function testNoticeFallsBackToTheDigestWebhook(): void
    {
        $c = WatchConfig::fromArray(self::config([]));

        self::assertSame('https://hooks.example/digest', (new Destination($c->notify['notice']['webhook']))->webhook());
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function config(array $extra): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'http://loki.invalid'], 'stream_tags' => ['app' => 'svc']],
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], 'prices' => ['a' => [1, 1, 1, 1], 'b' => [1, 1, 1, 1], 'c' => [1, 1, 1, 1]]],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/digest']] + $extra,
        ];
    }
}
