<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\ModelProvider;
use Watcher\Analyze\NoKeyProvider;
use Watcher\Analyze\OpenAiProvider;

/**
 * Spec 019 (behaviours 1, 6, 8): which failures carry a skip reason (run without AI), which stay transient, and that a billing 429
 * is sent once. No network: each case queues its replies on a Guzzle MockHandler and counts what was requested.
 */
final class SkipReasonTest extends TestCase
{
    private const KEY = 'sk-skip-test-secret';
    private const BASE = 'https://provider.example.test';
    private const FIXTURES = __DIR__ . '/../../Fixtures/providers/';

    /** @return array<string, array{0: string, 1: string}> provider dir, provider name */
    public static function providers(): array
    {
        return ['openai' => ['openai', 'openai'], 'gemini' => ['gemini', 'gemini']];
    }

    /** @param list<float> $sleeps receives each wait the provider takes */
    private function provider(string $name, MockHandler $mock, int $retries = 0, array &$sleeps = []): ModelProvider
    {
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $sleeper = static function (int $s) use (&$sleeps): void {
            $sleeps[] = (float) $s;
        };

        return $name === 'openai'
            ? new OpenAiProvider($client, self::KEY, self::BASE, 60, $retries, $sleeper)
            : new GeminiProvider($client, self::KEY, self::BASE, 60, $retries, $sleeper);
    }

    private static function body(string $dir, string $fixture): string
    {
        return (string) file_get_contents(self::FIXTURES . $dir . '/' . $fixture);
    }

    /**
     * @param list<float> $sleeps receives each wait the provider takes
     *
     * @return array{0: string|null, 1: string} the skip reason and the message of the failure
     */
    private function attempt(string $name, MockHandler $mock, int $retries = 0, array &$sleeps = []): array
    {
        $provider = $this->provider($name, $mock, $retries, $sleeps);
        try {
            $provider->create('model-under-test', 100, 'system', 'user', ['type' => 'object'], true);
        } catch (AnalyzerFailed $e) {
            return [$e->skip, $e->getMessage()];
        }
        self::fail('expected AnalyzerFailed');
    }

    #[DataProvider('providers')]
    public function testUnauthorisedAndForbiddenCarryTheAuthenticationReason(string $dir, string $name): void
    {
        foreach ([401, 403] as $status) {
            $mock = new MockHandler([new Response($status, [], self::body($dir, 'unauthorised.json'))]);

            [$skip, $message] = $this->attempt($name, $mock);

            self::assertSame('authentication failed', $skip, "$name $status");
            self::assertSame('authentication failed', $message, "$name $status");
        }
    }

    public function testOpenAiInsufficientQuotaIsNotRetriedAndCarriesTheQuotaReason(): void
    {
        // Three replies queued: a retry would consume a second one. Retries allowed (3), no wait may be taken.
        $mock = new MockHandler([
            new Response(429, [], self::body('openai', 'insufficient_quota.json')),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ]);
        $sleeps = [];

        [$skip, $message] = $this->attempt('openai', $mock, 3, $sleeps);

        self::assertSame('quota exhausted', $skip);
        self::assertSame('provider unavailable (quota exhausted)', $message);
        self::assertSame([], $sleeps);
        self::assertSame(2, $mock->count(), 'the 429 was sent exactly once');
    }

    public function testGeminiPaymentRequiredCarriesTheQuotaReason(): void
    {
        $mock = new MockHandler([new Response(402, [], self::body('gemini', 'payment_required.json'))]);

        [$skip] = $this->attempt('gemini', $mock);

        self::assertSame('quota exhausted', $skip);
    }

    public function testGeminiRateLimitStaysTransientAndIsRetried(): void
    {
        // A Gemini 429 cannot be told apart as a daily or billing quota for generateContent (research.md): transient, retried.
        $mock = new MockHandler([
            new Response(429, [], self::body('gemini', 'rate_limited.json')),
            new Response(200, [], self::body('gemini', 'ok.json')),
        ]);
        $sleeps = [];

        $provider = $this->provider('gemini', $mock, 1, $sleeps);
        $reply = $provider->create('model-under-test', 100, 'system', 'user', ['type' => 'object'], true);

        self::assertNotNull($reply->text);
        self::assertCount(1, $sleeps, 'one retry after the 429');
    }

    #[DataProvider('providers')]
    public function testPlainRateLimitStaysTransient(string $dir, string $name): void
    {
        $mock = new MockHandler([new Response(429, [], self::body($dir, 'rate_limited.json'))]);

        [$skip, $message] = $this->attempt($name, $mock);

        self::assertNull($skip);
        self::assertSame('provider unavailable (HTTP 429)', $message);
    }

    #[DataProvider('providers')]
    public function testServerErrorStaysTransient(string $dir, string $name): void
    {
        $mock = new MockHandler([new Response(500, [], self::body($dir, 'server_error.json'))]);

        [$skip] = $this->attempt($name, $mock);

        self::assertNull($skip);
    }

    public function testGeminiExpiredKeyStaysTransientBecauseNoDocumentedCodeIsVerified(): void
    {
        // research.md: the expired-key shape is UNVERIFIED for generateContent, so the failure keeps today's message and no reason.
        $mock = new MockHandler([new Response(400, [], self::body('gemini', 'key_expired.json'))]);

        [$skip, $message] = $this->attempt('gemini', $mock);

        self::assertNull($skip);
        self::assertSame('provider unavailable (HTTP 400)', $message);
    }

    public function testNoKeyProviderFailsWithTheNoKeyReasonAndSendsNothing(): void
    {
        try {
            (new NoKeyProvider())->create('m', 100, 's', 'u', [], true);
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('no key', $e->skip);
            self::assertSame('no key', $e->getMessage());
        }
    }

    #[DataProvider('providers')]
    public function testNoBodyTextOrKeyReachesTheReasonOrMessage(string $dir, string $name): void
    {
        $body = '{"error":{"message":"leaked text ' . self::KEY . ' https://x.test","type":"insufficient_quota","code":"insufficient_quota"}}';
        $mock = new MockHandler([new Response(429, [], $body)]);

        [$skip, $message] = $this->attempt($name, $mock);

        foreach ([$skip ?? '', $message] as $out) {
            self::assertStringNotContainsString(self::KEY, $out);
            self::assertStringNotContainsString('leaked', $out);
            self::assertStringNotContainsString('https://', $out);
        }
    }
}
