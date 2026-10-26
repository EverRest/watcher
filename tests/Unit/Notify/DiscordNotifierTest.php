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
use Watcher\Notify\DeliveryProgress;
use Watcher\Notify\Destination;
use Watcher\Notify\DiscordNotifier;
use Watcher\Notify\Message;

final class DiscordNotifierTest extends TestCase
{
    private const HOOK = 'https://discord.com/api/webhooks/123456789012345678/SECRETwebhookTOKEN_abc-123';

    /** @var array<int, array{request: Request}> */
    private array $history = [];
    /** @var list<int> */
    private array $slept = [];

    /** @param array<string, string> $headers */
    private static function fixture(string $name, int $status, array $headers = []): Response
    {
        return new Response($status, $headers, (string) file_get_contents(__DIR__ . "/../../Fixtures/discord/$name.json"));
    }

    /** @param list<Response|\Throwable> $queue */
    private function notifier(array $queue, int $budget = 60): DiscordNotifier
    {
        $this->history = [];
        $this->slept = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));

        return new DiscordNotifier(new Client(['handler' => $stack]), $budget, function (int $s): void {
            $this->slept[] = $s;
        });
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

    public function testBodyShape(): void
    {
        $n = $this->notifier([self::fixture('204', 204)]);
        $n->send(new Message('fallback', [['kind' => 'header', 'text' => 'Digest'], ['kind' => 'severity', 'text' => '*critical* (1)']]), Destination::discord(self::HOOK));

        self::assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::HOOK, (string) $req->getUri());
        self::assertSame([
            'content' => "**Digest**\n\n**critical** \\(1\\)",
            'allowed_mentions' => ['parse' => []],
            'flags' => 4,
        ], $this->body());
    }

    public function testMentionsAreOffEvenForSeededMentions(): void
    {
        $n = $this->notifier([self::fixture('204', 204)]);
        $n->send(new Message('x <@1> @everyone'), Destination::discord(self::HOOK));

        self::assertSame([], $this->body()['allowed_mentions']['parse']);
        self::assertStringNotContainsString('<@', $this->body()['content']);
        self::assertDoesNotMatchRegularExpression('/@(?!\x{200B})/u', $this->body()['content']);
    }

    public function testLongDigestIsSplitUnder1900(): void
    {
        $n = $this->notifier([self::fixture('204', 204), self::fixture('204', 204)]);
        $n->send(self::msg(str_repeat('a', 1000), str_repeat('b', 1000)), Destination::discord(self::HOOK));

        self::assertCount(2, $this->history);
        self::assertStringStartsWith('(1/2) ', $this->body(0)['content']);
        self::assertStringStartsWith('(2/2) ', $this->body(1)['content']);
        self::assertLessThanOrEqual(1900, mb_strlen($this->body(0)['content']));
    }

    public function testRateLimitBodyThenHeader(): void
    {
        $n = $this->notifier([
            self::fixture('429-body', 429, ['Retry-After' => '9']),
            self::fixture('204', 204),
            self::fixture('429-header', 429, ['Retry-After' => '4']),
            self::fixture('204', 204),
        ]);
        $n->send(self::msg('x'), Destination::discord(self::HOOK));
        $n->send(self::msg('x'), Destination::discord(self::HOOK));

        self::assertSame([3, 4], $this->slept, 'body 2.5 s rounds up to 3 and beats the header; without a body hint the header is used');
    }

    public function testNotFoundReportsStatusOnly(): void
    {
        $n = $this->notifier([self::fixture('404', 404)]);

        $this->expectExceptionObject(new DeliveryFailed('discord delivery failed: HTTP 404'));
        $n->send(self::msg('x'), Destination::discord(self::HOOK));
    }

    public function testServerErrorNotRetried(): void
    {
        $n = $this->notifier([new Response(500), self::fixture('204', 204)]);

        try {
            $n->send(self::msg('x'), Destination::discord(self::HOOK));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('discord delivery failed: HTTP 500', $e->getMessage());
            self::assertCount(1, $this->history);
        }
    }

    public function testFailureOnLaterPartNamesThePart(): void
    {
        $n = $this->notifier([self::fixture('204', 204), self::fixture('404', 404)]);

        $this->expectExceptionMessage('discord delivery failed: HTTP 404 at part 2 of 2');
        $n->send(self::msg(str_repeat('a', 1500), str_repeat('b', 1500)), Destination::discord(self::HOOK));
    }

    public function testRetryAfterPartialDeliverySkipsAcceptedParts(): void
    {
        $saved = 0;
        $n = $this->notifier([self::fixture('204', 204), self::fixture('404', 404), self::fixture('204', 204)]);
        $base = self::msg(str_repeat('a', 1500), str_repeat('b', 1500));
        $message = new Message($base->text, $base->sections, new DeliveryProgress(0, function (int $p) use (&$saved): void {
            $saved = $p;
        }));

        try {
            $n->send($message, Destination::discord(self::HOOK));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed) {
            self::assertSame(1, $saved);
            self::assertCount(2, $this->history);
        }

        $this->history = [];
        $resume = $this->notifier([self::fixture('204', 204)]);
        $resume->send(new Message($base->text, $base->sections, new DeliveryProgress(1)), Destination::discord(self::HOOK));

        self::assertCount(1, $this->history);
        self::assertStringStartsWith('(2/2) ', $this->body(0)['content']);
    }

    /** @return iterable<string, array{string}> */
    public static function badHooks(): iterable
    {
        yield 'lookalike host' => ['https://discord.com.evil.example/api/webhooks/1/abc'];
        yield 'userinfo' => ['https://discord.com@evil.example/api/webhooks/1/abc'];
        yield 'slack url' => ['https://hooks.slack.com/services/T/B/X'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badHooks')]
    public function testHostIsRecheckedBeforeAnyRequest(string $hook): void
    {
        $n = $this->notifier([self::fixture('204', 204)]);

        try {
            $n->send(self::msg('x'), Destination::discord($hook));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('discord delivery failed: invalid destination', $e->getMessage());
            self::assertSame([], $this->history);
        }
    }

    public function testRedirectIsNotFollowed(): void
    {
        $n = $this->notifier([new Response(307, ['Location' => 'https://evil.example/']), self::fixture('204', 204)]);

        try {
            $n->send(self::msg('x'), Destination::discord(self::HOOK));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $e) {
            self::assertSame('discord delivery failed: HTTP 307', $e->getMessage());
            self::assertCount(1, $this->history);
        }
    }

    /** @return iterable<string, array{list<Response|\Throwable>}> */
    public static function failures(): iterable
    {
        yield '404' => [[self::fixture('404', 404)]];
        yield '429 exhausted' => [[self::fixture('429-body', 429), self::fixture('429-body', 429), self::fixture('429-body', 429)]];
        yield 'transport' => [[new ConnectException('boom ' . self::HOOK, new Request('POST', self::HOOK))]];
    }

    /** @param list<Response|\Throwable> $queue */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testNoSecretInAnyFailure(array $queue): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $n = $this->notifier($queue, 60);
            try {
                $n->send(self::msg('é'), Destination::discord(self::HOOK));
                self::fail('expected DeliveryFailed');
            } catch (DeliveryFailed $e) {
                $all = (string) $e;
                self::assertNull($e->getPrevious());
                foreach (['SECRETwebhookTOKEN', '123456789012345678', 'discord.com', '/webhooks/'] as $secret) {
                    self::assertStringNotContainsString($secret, $all);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }
}
