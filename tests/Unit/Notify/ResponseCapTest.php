<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\DiscordNotifier;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Notify\TelegramNotifier;
use Watcher\Source\Retry;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;

/**
 * The notifiers read a reply through the same streaming cap as every backend ({@see \Watcher\Source\ResponseLimit}). Real cURL
 * handler, real bytes: a `php -S` child sends one hostile reply (NOTIFY_CAP_MODE), and every request is redirected to it, so the
 * HTTPS destinations need no network. A MockHandler cannot exercise a cap that counts bytes as they arrive.
 */
final class ResponseCapTest extends TestCase
{
    private const ROUTER = __DIR__ . '/../../Fixtures/notify/cap-router.php';
    private const SLACK = 'https://hooks.slack.com/services/T000/B000/SECRET-HOOK';
    private const DISCORD = 'https://discord.com/api/webhooks/1234/SECRET-HOOK';
    private const TELEGRAM_TOKEN = '123456:SECRET-BOT-TOKEN-0123456789abcdefgh';

    /** @var resource|null */
    private $server = null;
    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    public function testAHealthyReplyWithinTheCapIsDelivered(): void
    {
        $this->expectNotToPerformAssertions();
        $this->serve('ok');

        foreach (['slack', 'discord', 'telegram'] as $driver) {
            $this->send($driver); // throws on a failed delivery
        }
    }

    /** @dataProvider drivers */
    public function testADeclaredOversizeBodyIsRefusedAtTheHeaders(string $driver): void
    {
        $this->serve('large');

        $this->assertFailsFast($driver, "$driver delivery failed: transport error");
    }

    /** @dataProvider drivers */
    public function testAChunkedOversizeBodyIsCutWhileStreaming(string $driver): void
    {
        $this->serve('chunked');

        $this->assertFailsFast($driver, "$driver delivery failed: transport error");
    }

    /** @dataProvider drivers */
    public function testASlowTrickleIsCutAtTheCapNotAtTheEndOfTheTrickle(string $driver): void
    {
        $this->serve('trickle');

        $this->assertFailsFast($driver, "$driver delivery failed: transport error", maxSeconds: 5.0);
    }

    public function testARetriedRequestIsCutOnceAndNotDownloadedAgain(): void
    {
        $this->serve('trickle');
        $slept = [];
        $retry = new Retry(function (int $s) use (&$slept): void {
            $slept[] = $s;
        });
        $tripped = new \ArrayObject();

        $start = microtime(true);
        try {
            $retry->send(fn (): ResponseInterface => $this->client()->request('GET', 'https://source.example/', ResponseLimit::options($tripped, Notifier::MAX_RESPONSE_BYTES)), 'source', true, $tripped);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertTrue($e->tooLarge);
        }
        self::assertLessThan(5.0, microtime(true) - $start);
        self::assertSame([], $slept, 'a cut reply is not retried');
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return ['slack' => ['slack'], 'discord' => ['discord'], 'telegram' => ['telegram']];
    }

    private function assertFailsFast(string $driver, string $message, float $maxSeconds = 2.0): void
    {
        $start = microtime(true);
        try {
            $this->send($driver);
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertLessThan($maxSeconds, microtime(true) - $start, 'the cap must cut the reply, not wait for it');
            self::assertSame($message, $e->getMessage());
            self::assertNull($e->getPrevious(), 'never chained: a Guzzle message carries the URL');
            self::assertStringNotContainsString('xxxx', $e->getMessage(), 'no body text in the failure');
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    private function send(string $driver): void
    {
        $message = new Message('fallback', []);
        match ($driver) {
            'slack' => (new SlackWebhookNotifier($this->client()))->send($message, Destination::slack(self::SLACK)),
            'discord' => (new DiscordNotifier($this->client()))->send($message, Destination::discord(self::DISCORD)),
            'telegram' => (new TelegramNotifier($this->client()))->send($message, Destination::telegram(self::TELEGRAM_TOKEN, '42')),
            default => throw new \LogicException('unknown driver ' . $driver),
        };
    }

    /** Real cURL handler; the request keeps its path and query and is sent to the local server over plain HTTP. */
    private function client(): Client
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest(fn (RequestInterface $r): RequestInterface => $r->withUri(
            $r->getUri()->withScheme('http')->withHost('127.0.0.1')->withPort($this->port),
        )));

        return new Client(['handler' => $stack, 'timeout' => 30]);
    }

    private function serve(string $mode): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new \RuntimeException('no free local port');
        }
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, self::ROUTER],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['NOTIFY_CAP_MODE' => $mode, 'NOTIFY_CAP_BYTES' => (string) Notifier::MAX_RESPONSE_BYTES],
        );
        if ($proc === false) {
            throw new \RuntimeException('the local server did not start');
        }
        $this->server = $proc;

        for ($i = 0; $i < 100; ++$i) {
            $conn = @fsockopen('127.0.0.1', $this->port);
            if (is_resource($conn)) {
                fclose($conn);

                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException('the local server did not answer');
    }
}
