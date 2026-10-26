<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Metrics\MetricDefinition;
use Watcher\Metrics\MetricResult;
use Watcher\Metrics\MetricsReader;
use Watcher\Metrics\QueryTemplate;
use Watcher\Notify\Destination;
use Watcher\Notify\DiscordNotifier;
use Watcher\Notify\Message;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Notify\TelegramNotifier;
use Watcher\Schedule\Window;
use Watcher\Tests\Fakes\FakeMetricSource;

/** Review M3: hostile metric labels never reach the Metrics: line that Slack, Discord and Telegram receive. */
final class HostileLabelsNotifiersTest extends TestCase
{
    private const HOSTILE = ["pay\x1fments", "pay\u{FFFE}ments", "pay\u{200B}ments", '<!channel>', '*bold*', '[a](b)', '||spoiler||', '`code`', '@here'];

    /** @return iterable<string, array{string}> */
    public static function channels(): iterable
    {
        yield 'slack' => ['slack'];
        yield 'discord' => ['discord'];
        yield 'telegram' => ['telegram'];
    }

    #[DataProvider('channels')]
    public function testTheBodyPostedToTheChannelCarriesOnlyThePlainLabel(string $channel): void
    {
        $source = new FakeMetricSource(static fn (): MetricResult => new MetricResult([1.0], 1, ['payments.eu-west', ...self::HOSTILE]));
        $defs = [new MetricDefinition('m', QueryTemplate::compile('m', 'up', 'svc', []), 'max')];
        $line = (new MetricsReader($source, $defs))->read(new Window(new DateTimeImmutable('2026-10-07T06:00:00Z'), new DateTimeImmutable('2026-10-08T06:00:00Z')))->line();
        $log = [];
        $stack = HandlerStack::create(new MockHandler([new Response($channel === 'discord' ? 204 : 200, [], $channel === 'telegram' ? '{"ok":true,"result":{}}' : 'ok')]));
        $stack->push(Middleware::history($log));
        $http = new Client(['handler' => $stack]);
        $message = new Message('fallback', [['kind' => 'notice', 'text' => '📊 ' . $line]]);

        match ($channel) {
            'slack' => (new SlackWebhookNotifier($http))->send($message, Destination::slack('https://hooks.slack.com/services/T000/B000/XXXX')),
            'discord' => (new DiscordNotifier($http, 60, static function (int $s): void {
            }))->send($message, Destination::discord('https://discord.com/api/webhooks/123456789012345678/SECRETwebhookTOKEN_abc-123')),
            'telegram' => (new TelegramNotifier($http, 60, static function (int $s): void {
            }))->send($message, Destination::telegram('123456789' . ':' . 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw_', '-1001234567890')),
            default => throw new \InvalidArgumentException('unknown channel'),
        };

        self::assertArrayHasKey(0, $log);
        self::assertArrayNotHasKey(1, $log);
        $request = $log[0]['request'] ?? throw new \LogicException('the notifier sent no request');
        $raw = json_encode(json_decode((string) $request->getBody(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($raw);
        self::assertStringContainsString('payments.eu-west', $raw);
        foreach (["\x1f", "\u{FFFE}", "\u{200B}", '<!channel>', '*bold*', '[a](b)', '||spoiler||', '@here'] as $bad) {
            self::assertStringNotContainsString($bad, $raw, $channel);
        }
    }
}
