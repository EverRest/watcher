<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\TelegramNotifier;

final class TelegramNotifierTest extends TestCase
{
    private const TOKEN = '123456789' . ':' . 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw_';
    private const CHAT = '-1001234567890';

    /** @var array<int, array{request: Request}> */
    private array $history = [];
    /** @var list<int> */
    private array $slept = [];

    private static function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, [], (string) file_get_contents(__DIR__ . "/../../Fixtures/telegram/$name.json"));
    }

    /** @param list<Response|\Throwable> $queue */
    private function notifier(array $queue, int $budget = 60): TelegramNotifier
    {
        $this->history = [];
        $this->slept = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));

        return new TelegramNotifier(new Client(['handler' => $stack]), $budget, function (int $s): void {
            $this->slept[] = $s;
        });
    }

    private static function dest(?int $thread = null): Destination
    {
        return Destination::telegram(self::TOKEN, self::CHAT, $thread);
    }

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        return json_decode((string) $this->history[$i]['request']->getBody(), true);
    }

    private static function msg(string ...$texts): Message
    {
        return new Message('fallback', array_values(array_map(static fn (string $t): array => ["kind" => "finding", "text" => $t], $texts)));
    }

    public function testShortDigestIsOnePost(): void
    {
        $n = $this->notifier([self::fixture('ok')]);
        $n->send(new Message('fallback', [['kind' => 'header', 'text' => 'Digest &amp; co'], ['kind' => 'severity', 'text' => '*critical* (1)']]), self::dest(7));

        self::assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', (string) $req->getUri());
        self::assertSame([
            'chat_id' => self::CHAT,
            'text' => "<b>Digest &amp; co</b>\n\n<b>critical</b> (1)",
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'message_thread_id' => 7,
        ], $this->body());
    }

    public function testThreadIdOnlyWhenSet(): void
    {
        $n = $this->notifier([self::fixture('ok')]);
        $n->send(self::msg('x'), self::dest());

        self::assertArrayNotHasKey('message_thread_id', $this->body());
    }

    public function testSectionlessMessageUsesDefangedPlainText(): void
    {
        $n = $this->notifier([self::fixture('ok')]);
        $n->send(new Message('test <b> http://a.com'), self::dest());

        self::assertSame("test &lt;b&gt; http:\u{200B}//a.\u{200B}com", $this->body()['text']);
    }

    public function testLongDigestIsSplitAndNumbered(): void
    {
        $n = $this->notifier([self::fixture('ok'), self::fixture('ok'), self::fixture('ok')]);
        $n->send(self::msg(str_repeat('a', 1500), str_repeat('b', 1500), str_repeat('c', 1500)), self::dest());

        self::assertCount(2, $this->history);
        self::assertStringStartsWith('(1/2) ', $this->body(0)['text']);
        self::assertStringStartsWith('(2/2) ', $this->body(1)['text']);
        foreach ([0, 1] as $i) {
            self::assertLessThanOrEqual(4096, mb_strlen($this->body($i)['text']));
        }
    }

    public function testRateLimitHonoured(): void
    {
        $n = $this->notifier([self::fixture('429', 429), self::fixture('ok')]);
        $n->send(self::msg('x'), self::dest());

        self::assertCount(2, $this->history);
        self::assertSame([2], $this->slept);
    }

    public function testHintAboveBudgetFailsFast(): void
    {
        $n = $this->notifier([new Response(429, [], '{"ok":false,"error_code":429,"parameters":{"retry_after":600}}')]);

        $this->expectException(DeliveryFailed::class);
        $this->expectExceptionMessage('telegram delivery failed: HTTP 429');
        try {
            $n->send(self::msg('x'), self::dest());
        } finally {
            self::assertSame([], $this->slept);
            self::assertCount(1, $this->history);
        }
    }

    public function testBudgetIsSharedByThePartsOfOneMessage(): void
    {
        $n = $this->notifier([
            self::fixture('429', 429), self::fixture('ok'),
            self::fixture('429', 429), self::fixture('ok'),
        ], 3);

        try {
            $n->send(self::msg(str_repeat('a', 3000), str_repeat('b', 3000)), self::dest());
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('telegram delivery failed: HTTP 429 at part 2 of 2', $e->getMessage());
            self::assertSame([2], $this->slept);
        }
    }

    public function testServerErrorNotRetried(): void
    {
        $n = $this->notifier([new Response(502), self::fixture('ok')]);

        try {
            $n->send(self::msg('x'), self::dest());
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('telegram delivery failed: HTTP 502', $e->getMessage());
            self::assertCount(1, $this->history);
        }
    }

    public function testTransportErrorUnchained(): void
    {
        $n = $this->notifier([new ConnectException('cURL error for https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', new Request('POST', 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage'))]);

        try {
            $n->send(self::msg('x'), self::dest());
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('telegram delivery failed: transport error', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testBadRequestReportsStatusOnly(): void
    {
        $n = $this->notifier([self::fixture('400', 400)]);

        $this->expectExceptionObject(new DeliveryFailed('telegram delivery failed: HTTP 400'));
        $n->send(self::msg('x'), self::dest());
    }

    public function testMalformedTokenIsRefusedBeforeAnyRequest(): void
    {
        $n = $this->notifier([self::fixture('ok')]);

        try {
            $n->send(self::msg('x'), Destination::telegram('123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PA/../../x', self::CHAT));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('telegram delivery failed: invalid destination', $e->getMessage());
            self::assertSame([], $this->history);
        }
    }

    public function testWrongDriverIsRefused(): void
    {
        $n = $this->notifier([self::fixture('ok')]);

        $this->expectException(DeliveryFailed::class);
        $n->send(self::msg('x'), new Destination('https://hooks.example/x'));
    }

    public function testRedirectsAreNotFollowed(): void
    {
        // a 302 must surface as a failure, not be followed
        $n = $this->notifier([new Response(302, ['Location' => 'https://evil.com/']), self::fixture('ok')]);
        try {
            $n->send(self::msg('x'), self::dest());
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('telegram delivery failed: HTTP 302', $e->getMessage());
            self::assertCount(1, $this->history);
        }
    }

    /** @return iterable<string, array{list<Response|\Throwable>, Destination}> */
    public static function failures(): iterable
    {
        $url = 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage';
        yield '403' => [[self::fixture('403', 403)], self::dest()];
        yield '429 exhausted' => [[self::fixture('429', 429), self::fixture('429', 429), self::fixture('429', 429)], self::dest()];
        yield '429 hint above budget' => [[new Response(429, [], '{"parameters":{"retry_after":900}}')], self::dest()];
        yield 'transport' => [[new ConnectException("boom $url " . self::CHAT, new Request('POST', $url))], self::dest(9)];
        yield 'malformed token' => [[], Destination::telegram(self::TOKEN . '/x?', self::CHAT)];
        yield 'malformed chat' => [[], Destination::telegram(self::TOKEN, 'not a chat')];
    }

    /**
     * PHP traces print string arguments of active frames: the exception must be thrown from a frame that holds
     * neither the token nor the URL, and must not chain the Guzzle exception.
     *
     * @param list<Response|\Throwable> $queue
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testNoSecretInAnyFailure(array $queue, Destination $to): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $n = $this->notifier($queue, 60);
            try {
                $n->send(self::msg(str_repeat('é', 10)), $to);
                self::fail('expected DeliveryFailed');
            } catch (DeliveryFailed $e) {
                $all = (string) $e;
                self::assertNull($e->getPrevious());
                self::assertStringContainsString('#0', $e->getTraceAsString());
                foreach (['AAHdqTcv', '123456789:', self::CHAT, 'api.telegram.org', '/sendMessage'] as $secret) {
                    self::assertStringNotContainsString($secret, $all);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }
}
