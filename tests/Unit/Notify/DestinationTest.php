<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Notify\Destination;

final class DestinationTest extends TestCase
{
    private const HOOK = 'https://hooks.example/T000/SECRET-TOKEN';
    private const DISCORD = 'https://discord.com/api/webhooks/123456/SECRETTOKEN';

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
        self::assertNotContains(\Stringable::class, class_implements(new Destination(self::HOOK)) ?: []);
    }

    private const TOKEN = '123456789' . ':' . 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw_';

    public function testFactoriesAndDriver(): void
    {
        self::assertSame('slack', (new Destination(self::HOOK))->driver());
        self::assertSame('slack', Destination::slack(self::HOOK)->driver());
        self::assertSame('telegram', Destination::telegram(self::TOKEN, '-100123', 7)->driver());
        self::assertSame('discord', Destination::discord(self::DISCORD)->driver());
    }

    public function testAccessors(): void
    {
        $t = Destination::telegram(self::TOKEN, '-100123', 7);
        self::assertSame(self::TOKEN, $t->botToken());
        self::assertSame('-100123', $t->chatId());
        self::assertSame(7, $t->threadId());
        self::assertNull(Destination::telegram(self::TOKEN, '-100123')->threadId());
        self::assertSame(self::DISCORD, Destination::discord(self::DISCORD)->webhook());
    }

    public function testWrongDriverAccessorThrows(): void
    {
        foreach ([
            static fn () => Destination::telegram(self::TOKEN, '1')->webhook(),
            static fn () => Destination::slack(self::HOOK)->botToken(),
            static fn () => Destination::discord(self::DISCORD)->chatId(),
            static fn () => Destination::discord(self::DISCORD)->threadId(),
        ] as $call) {
            try {
                $call();
                self::fail('expected LogicException');
            } catch (\LogicException $e) {
                self::assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }
    }

    public function testSameAs(): void
    {
        $a = Destination::telegram(self::TOKEN, '-100123', 7);
        self::assertTrue($a->sameAs(Destination::telegram(self::TOKEN, '-100123', 7)));
        self::assertFalse($a->sameAs(Destination::telegram(self::TOKEN, '-100123', 8)));
        self::assertFalse($a->sameAs(Destination::telegram(self::TOKEN, '-100123')));
        self::assertFalse($a->sameAs(Destination::telegram(self::TOKEN, '-100124', 7)));
        self::assertTrue((new Destination(self::HOOK))->sameAs(Destination::slack(self::HOOK)));
        self::assertFalse(Destination::slack(self::DISCORD)->sameAs(Destination::discord(self::DISCORD)));
    }

    /** @return iterable<string, array{Destination, ?string}> */
    public static function keys(): iterable
    {
        yield 'slack ok' => [Destination::slack(self::HOOK), null];
        yield 'slack http' => [Destination::slack('http://hooks.example/x'), 'webhook'];
        yield 'slack no host' => [Destination::slack('https://'), 'webhook'];
        yield 'tg ok' => [Destination::telegram(self::TOKEN, '-1001234567890', 3), null];
        yield 'tg ok username' => [Destination::telegram(self::TOKEN, '@my_channel'), null];
        yield 'tg positive id' => [Destination::telegram(self::TOKEN, '42'), null];
        yield 'tg token short secret' => [Destination::telegram('123:abc', '1'), 'bot_token'];
        yield 'tg token slash' => [Destination::telegram('123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PA/../x', '1'), 'bot_token'];
        yield 'tg token query' => [Destination::telegram(self::TOKEN . '?x=1', '1'), 'bot_token'];
        yield 'tg chat letters' => [Destination::telegram(self::TOKEN, 'abc'), 'chat_id'];
        yield 'tg chat short username' => [Destination::telegram(self::TOKEN, '@ab'), 'chat_id'];
        yield 'tg chat empty' => [Destination::telegram(self::TOKEN, ''), 'chat_id'];
        yield 'tg thread zero' => [Destination::telegram(self::TOKEN, '1', 0), 'thread_id'];
        yield 'dc ok' => [Destination::discord(self::DISCORD), null];
        yield 'dc discordapp' => [Destination::discord('https://discordapp.com/api/v10/webhooks/1/abc_DEF-1'), null];
        yield 'dc ptb' => [Destination::discord('https://ptb.discord.com/api/webhooks/1/abc'), null];
        yield 'dc lookalike' => [Destination::discord('https://discord.com.evil.example/api/webhooks/1/abc'), 'webhook'];
        yield 'dc userinfo' => [Destination::discord('https://discord.com@evil.example/api/webhooks/1/abc'), 'webhook'];
        yield 'dc http' => [Destination::discord('http://discord.com/api/webhooks/1/abc'), 'webhook'];
        yield 'dc slack url' => [Destination::discord(self::HOOK), 'webhook'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function testInvalidKey(Destination $d, ?string $expected): void
    {
        self::assertSame($expected, $d->invalidKey());
    }

    public function testNewDriversHideEveryDump(): void
    {
        foreach ([Destination::telegram(self::TOKEN, '-100999888777', 5), Destination::discord(self::DISCORD)] as $d) {
            $dumps = [print_r($d, true), var_export($d, true), (string) json_encode([$d])];
            ob_start();
            var_dump($d);
            $dumps[] = (string) ob_get_clean();
            foreach ($dumps as $dump) {
                foreach (['AAHdqTcv', '100999888777', 'SECRETTOKEN', '/api/webhooks'] as $secret) {
                    self::assertStringNotContainsString($secret, $dump);
                }
            }
            try {
                serialize($d);
                self::fail('expected LogicException');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNoticeUsesItsOwnWebhookWhenSet(): void
    {
        $c = WatchConfig::fromArray(self::config(['notice' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/notice']]));

        self::assertSame('https://hooks.example/notice', (new Destination(WatchConfig::reveal($c->notify['notice']['webhook'])))->webhook());
        self::assertSame('https://hooks.example/digest', (new Destination(WatchConfig::reveal($c->notify['digest']['webhook'])))->webhook());
    }

    public function testNoticeFallsBackToTheDigestWebhook(): void
    {
        $c = WatchConfig::fromArray(self::config([]));

        self::assertSame('https://hooks.example/digest', (new Destination(WatchConfig::reveal($c->notify['notice']['webhook'])))->webhook());
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
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid'], 'stream_tags' => ['app' => 'svc']],
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/digest']] + $extra,
        ];
    }
}
