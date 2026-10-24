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
use Watcher\Notify\SlackWebhookNotifier;

final class SlackWebhookNotifierTest extends TestCase
{
    private const HOOK = 'https://hooks.example/T000/SECRET-TOKEN';

    /** @var array<int, array{request: Request}> */
    private array $history = [];

    /** @param list<Response|\Throwable> $queue */
    private function notifier(array $queue): SlackWebhookNotifier
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new SlackWebhookNotifier(new Client(['handler' => $stack]));
    }

    public function testPostsExactBlockKitBody(): void
    {
        $n = $this->notifier([new Response(200, [], 'ok')]);
        $n->send(new Message('fallback', [
            ['kind' => 'header', 'text' => '🔎 a &amp; b'],
            ['kind' => 'severity', 'text' => '🔴 *critical* (1)'],
            ['kind' => 'finding', 'text' => '• f'],
            ['kind' => 'footer', 'text' => '— tokens'],
        ]), new Destination(self::HOOK));

        self::assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::HOOK, (string) $req->getUri());
        self::assertSame([
            'text' => 'fallback',
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '🔎 a & b']],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '🔴 *critical* (1)']],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '• f']],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => '— tokens']]],
            ],
        ], json_decode((string) $req->getBody(), true));
    }

    public function testFallbackTextAndBlocksAreCapped(): void
    {
        $n = $this->notifier([new Response(200, [], 'ok')]);
        $sections = array_fill(0, 70, ['kind' => 'finding', 'text' => 'x']);
        $n->send(new Message(str_repeat('é', 5000), $sections), new Destination(self::HOOK));

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame(4000, mb_strlen($body['text']));
        self::assertCount(50, $body['blocks']);
    }

    public function testNon2xxThrowsWithoutWebhook(): void
    {
        $n = $this->notifier([new Response(404, [], 'no_service')]);

        try {
            $n->send(new Message('t'), new Destination(self::HOOK));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('slack delivery failed: HTTP 404', $e->getMessage());
            self::assertStringNotContainsString('SECRET', (string) $e);
        }
    }

    public function testTransportFailureThrowsWithoutWebhook(): void
    {
        $n = $this->notifier([new ConnectException('cURL error for ' . self::HOOK, new Request('POST', self::HOOK))]);

        try {
            $n->send(new Message('t'), new Destination(self::HOOK));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertStringNotContainsString('hooks.example', $e->getMessage());
        }
    }

    public function testAMalformedUriNeverLeaksTheWebhook(): void
    {
        $hook = 'https://hooks.slack.com:port/services/T0FAKE000/B0FAKE000/FAKESECRETFAKE';
        $n = $this->notifier([new Response(200)]);

        try {
            $n->send(new Message('x', []), new Destination($hook));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertStringNotContainsString('FAKESECRET', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertSame([], $this->history, 'nothing was sent');
    }

    public function testAnInvalidArgumentFromTheClientBecomesADeliveryFailureWithoutTheUrl(): void
    {
        $n = $this->notifier([new \InvalidArgumentException('Unable to parse URI: ' . self::HOOK)]);

        try {
            $n->send(new Message('x', []), new Destination(self::HOOK));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertStringNotContainsString('SECRET-TOKEN', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testPlainHttpAndCredentialsInTheUrlAreRefusedAtRunTime(): void
    {
        foreach (['http://hooks.example/T0/B0/SECRET-TOKEN', 'https://user:pw@hooks.example/T0/B0/SECRET-TOKEN', 'ftp://hooks.example/x', 'https:///no-host'] as $hook) {
            $n = $this->notifier([new Response(200)]);
            try {
                $n->send(new Message('x', []), new Destination($hook));
                self::fail("sent to $hook");
            } catch (DeliveryFailed $e) {
                self::assertStringNotContainsString('SECRET-TOKEN', $e->getMessage());
            }
            self::assertSame([], $this->history);
        }
    }
}
