<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\ClientInterface;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\ModelProvider;
use Watcher\Analyze\ModelReply;
use Watcher\Analyze\OpenAiProvider;

/**
 * One contract, three adapters: every ModelProvider is run against recorded replies through a Guzzle MockHandler. No test
 * reaches the network (Principle III). Each failure asserts that the message carries no URL, no key and no body text.
 */
final class ModelProviderContractTest extends TestCase
{
    private const KEY = 'sk-contract-test-key';
    private const BASE = 'https://provider.example.test';

    /** @return array<string, array{0: string, 1: callable(ClientInterface): ModelProvider, 2: string, 3: string}> */
    public static function providers(): array
    {
        return [
            'openai' => ['openai', static fn (ClientInterface $h): ModelProvider => new OpenAiProvider($h, self::KEY, self::BASE, 60, 0), 'gpt-fixture-model', 'openai'],
            'gemini' => ['gemini', static fn (ClientInterface $h): ModelProvider => new GeminiProvider($h, self::KEY, self::BASE, 60, 0), 'gemini-fixture-model', 'gemini'],
        ];
    }

    #[DataProvider('providers')]
    public function testValidReplyIsNormalised(string $name, callable $make, string $model, string $dir): void
    {
        $reply = $this->call($make, $dir, 'ok.json', 200);

        self::assertInstanceOf(ModelReply::class, $reply);
        self::assertNotNull($reply->text);
        self::assertSame('end', $reply->stopReason);
        // OpenAI and Gemini count the cached part inside the prompt.
        self::assertStringContainsString($model, $reply->model);
    }

    #[DataProvider('providers')]
    public function testRefusalIsAReplyNotAFailure(string $name, callable $make, string $model, string $dir): void
    {
        $reply = $this->call($make, $dir, 'refusal.json', 200);

        self::assertSame('refusal', $reply->stopReason);
    }

    #[DataProvider('providers')]
    public function testMaxTokensIsNormalised(string $name, callable $make, string $model, string $dir): void
    {
        $reply = $this->call($make, $dir, 'max_tokens.json', 200);

        self::assertSame('max_tokens', $reply->stopReason);
    }

    #[DataProvider('providers')]
    public function testUnauthorisedFailsWithAuthenticationMessage(string $name, callable $make, string $model, string $dir): void
    {
        $this->assertFailure($make, $dir, 'unauthorised.json', 401, 'authentication failed');
    }

    #[DataProvider('providers')]
    public function testRateLimitNamesTheStatus(string $name, callable $make, string $model, string $dir): void
    {
        $this->assertFailure($make, $dir, 'rate_limited.json', 429, 'provider unavailable (HTTP 429)');
    }

    #[DataProvider('providers')]
    public function testServerErrorNamesTheStatus(string $name, callable $make, string $model, string $dir): void
    {
        $this->assertFailure($make, $dir, 'server_error.json', 500, 'provider unavailable (HTTP 500)');
    }

    #[DataProvider('providers')]
    public function testNonJsonIsAnInvalidReply(string $name, callable $make, string $model, string $dir): void
    {
        $this->assertFailure($make, $dir, 'non_json.txt', 200, 'provider unavailable (invalid reply)');
    }

    #[DataProvider('providers')]
    public function testMissingUsageCountIsAnInvalidReply(string $name, callable $make, string $model, string $dir): void
    {
        $this->assertFailure($make, $dir, 'missing_usage.json', 200, 'provider unavailable (invalid reply)');
    }

    /** @param callable(ClientInterface): ModelProvider $make */
    private function call(callable $make, string $dir, string $fixture, int $status): ModelReply
    {
        $body = (string) file_get_contents(__DIR__ . '/../Fixtures/providers/' . $dir . '/' . $fixture);
        $mock = new MockHandler([new Response($status, ['Content-Type' => 'application/json'], $body)]);
        $provider = $make(new Client(['handler' => HandlerStack::create($mock)]));

        return $provider->create('model-under-test', 1000, 'system text', 'user text', ['type' => 'object'], true);
    }

    /** @param callable(ClientInterface): ModelProvider $make */
    private function assertFailure(callable $make, string $dir, string $fixture, int $status, string $message): void
    {
        $body = (string) file_get_contents(__DIR__ . '/../Fixtures/providers/' . $dir . '/' . $fixture);

        try {
            $this->call($make, $dir, $fixture, $status);
            self::fail("expected AnalyzerFailed: $message");
        } catch (AnalyzerFailed $e) {
            self::assertSame($message, $e->getMessage());
            foreach ([self::KEY, self::BASE, 'https://', trim(substr($body, 0, 40))] as $leak) {
                if ($leak !== '') {
                    self::assertStringNotContainsString($leak, $e->getMessage(), 'failure message must not carry URL, key or body');
                }
            }
        }
    }

    /** Rule 6 of contracts/model-provider.md: the key never appears in a dump of the adapter. */
    #[DataProvider('providers')]
    public function testTheKeyDoesNotAppearInADump(string $name, callable $make, string $model, string $dir): void
    {
        $provider = $make(new Client());

        self::assertStringNotContainsString(self::KEY, var_export($provider, true), "$name dump");
        self::assertStringNotContainsString(self::KEY, print_r($provider, true), "$name print_r");
    }
}
