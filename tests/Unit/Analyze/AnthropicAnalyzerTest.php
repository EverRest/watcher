<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\ProviderSchema;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class AnthropicAnalyzerTest extends TestCase
{
    private const KEY = 'sk-ant-test-SECRET-KEY';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/' . $name);
    }

    private static function json(string $fixture, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], self::fixture($fixture));
    }

    /** @param list<Response> $responses */
    private function analyzer(array $responses): AnthropicAnalyzer
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        // zero retry delay keeps the SDK's own retries instant
        return new AnthropicAnalyzer(self::KEY, new Client(['handler' => $stack, 'http_errors' => false]), null, ['initialRetryDelay' => 0.0, 'maxRetryDelay' => 0.0]);
    }

    private static function request(): AnalysisRequest
    {
        $group = new RedactedGroup(
            'fp-aaa',
            'Gateway timeout after <N> ms',
            Level::Error,
            'TimeoutException',
            ['App\\Pay::charge'],
            41,
            3,
            GroupStatusLabel::Grown,
            [],
            new DateTimeImmutable('2026-10-23T05:00:00+00:00'),
            new DateTimeImmutable('2026-10-23T05:59:00+00:00'),
        );

        return new AnalysisRequest([$group], 'Payments API; PHP 8.2 on Laravel.', 'model-from-request', 1234);
    }

    /** @return array<string, mixed> */
    private function body(int $i = 0): array
    {
        $decoded = json_decode((string) $this->history[$i]['request']->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testRequestShape(): void
    {
        $this->analyzer([self::json('message-valid.json')])->analyze(self::request());

        self::assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        self::assertSame('/v1/messages', $req->getUri()->getPath());
        // SDK 1.1.0 sends the key as a Bearer token
        self::assertSame('Bearer ' . self::KEY, $req->getHeaderLine('Authorization'));

        $body = $this->body();
        self::assertSame('model-from-request', $body['model']);
        self::assertSame(1234, $body['max_tokens']);
        self::assertSame(ProviderSchema::for('analysis'), $body['output_config']['format']['schema']);
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertStringNotContainsString('maxLength', (string) json_encode($body['output_config']));
        self::assertStringNotContainsString('"minimum"', (string) json_encode($body['output_config']));
        self::assertStringNotContainsString('"maximum"', (string) json_encode($body['output_config']));
    }

    public function testSystemBlockCarriesRulesAndServiceContext(): void
    {
        $this->analyzer([self::json('message-valid.json')])->analyze(self::request());

        $system = $this->body()['system'];
        self::assertIsArray($system);
        $text = implode("\n", array_column($system, 'text'));
        self::assertStringContainsString('Payments API; PHP 8.2 on Laravel.', $text);
        self::assertStringContainsString('Do not state any fact that is not present in the input', $text);
        self::assertStringContainsString('hypotheses', $text);
        self::assertStringContainsString('already masked', $text);
        self::assertSame(['type' => 'ephemeral'], array_filter($system[array_key_last($system)]['cache_control'] ?? [], static fn ($k) => $k === 'type', ARRAY_FILTER_USE_KEY));
    }

    public function testUserMessageIsCompactJsonOfRedactedGroups(): void
    {
        $req = self::request();
        $this->analyzer([self::json('message-valid.json')])->analyze($req);

        $messages = $this->body()['messages'];
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role']);
        $content = $messages[0]['content'];
        $text = (string) (is_string($content) ? $content : $content[0]['text']);
        self::assertSame(json_encode($req->groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $text);
        $groups = json_decode($text, true);
        self::assertSame(array_keys($req->groups[0]->toArray()), array_keys($groups[0]));
        self::assertStringNotContainsString("\n", $text);
    }

    public function testValidResponseMapsFindingsAndUsage(): void
    {
        $result = $this->analyzer([self::json('message-valid.json')])->analyze(self::request());

        self::assertCount(1, $result->findings);
        self::assertSame(['fp-aaa'], $result->findings[0]->fingerprints);
        self::assertSame(FindingKind::Regression, $result->findings[0]->kind);
        self::assertSame(Severity::Error, $result->findings[0]->severity);
        self::assertSame(0.7, $result->findings[0]->confidence);

        $u = $result->usage;
        self::assertSame('claude-fixture-model-1', $u->model);
        self::assertSame(1200, $u->inputTokens);
        self::assertSame(150, $u->outputTokens);
        self::assertSame(400, $u->cacheReadTokens);
        self::assertSame(800, $u->cacheWriteTokens);
        self::assertSame(0.0, $u->costUsd);
    }

    public function testInvalidJsonThenValidRetriesOnceAndSucceeds(): void
    {
        $result = $this->analyzer([self::json('message-not-json.json'), self::json('message-valid.json')])->analyze(self::request());

        self::assertCount(2, $this->history);
        self::assertCount(1, $result->findings);
        // usage of both attempts is accounted for
        self::assertSame(1300, $result->usage->inputTokens);
        self::assertSame(160, $result->usage->outputTokens);
    }

    public function testInvalidTwiceFailsAfterExactlyTwoRequests(): void
    {
        $analyzer = $this->analyzer([self::json('message-not-json.json'), self::json('message-not-json.json')]);

        try {
            $analyzer->analyze(self::request());
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('invalid model output', $e->getMessage());
            self::assertStringNotContainsString('prose', $e->getMessage());
        }
        self::assertCount(2, $this->history);
    }

    public function testFingerprintNotInRequestIsInvalidOutput(): void
    {
        $analyzer = $this->analyzer([self::json('message-unknown-fingerprint.json'), self::json('message-unknown-fingerprint.json')]);

        $this->expectException(AnalyzerFailed::class);
        $this->expectExceptionMessage('invalid model output');
        try {
            $analyzer->analyze(self::request());
        } finally {
            self::assertCount(2, $this->history);
        }
    }

    public function testAuthenticationFailureHidesTheKey(): void
    {
        $analyzer = $this->analyzer([self::json('error-401.json', 401)]);

        try {
            $analyzer->analyze(self::request());
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('authentication failed', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertCount(1, $this->history, '401 is not retried');
            // The SDK exception carries the request (and its Authorization header): never chain it.
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    /** @dataProvider unavailableStatuses */
    public function testRepeatedServerErrorsMeanProviderUnavailable(int $status, string $fixture): void
    {
        // SDK default: 1 request + 2 retries
        $analyzer = $this->analyzer([self::json($fixture, $status), self::json($fixture, $status), self::json($fixture, $status)]);

        try {
            $analyzer->analyze(self::request());
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertCount(3, $this->history);
        }
    }

    /** @return array<string, array{int, string}> */
    public static function unavailableStatuses(): array
    {
        return ['529 overloaded' => [529, 'error-529.json'], '503 unavailable' => [503, 'error-529.json']];
    }

    public function testTriageIsNotImplementedYet(): void
    {
        $this->expectException(\LogicException::class);
        $this->analyzer([])->triage(new TriageRequest([], '', 'm', 1));
    }
}
