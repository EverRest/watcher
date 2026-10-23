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
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\ProviderSchema;
use Watcher\Analyze\TriageRequest;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class AnthropicAnalyzerTriageTest extends TestCase
{
    private const KEY = 'sk-ant-test-SECRET-KEY';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private static function json(string $fixture, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/' . $fixture));
    }

    /** @param list<Response> $responses */
    private function analyzer(array $responses): AnthropicAnalyzer
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new AnthropicAnalyzer(self::KEY, new Client(['handler' => $stack, 'http_errors' => false]), null, ['initialRetryDelay' => 0.0, 'maxRetryDelay' => 0.0]);
    }

    private static function request(): TriageRequest
    {
        $group = new RedactedGroup('fp-aaa', 'Gateway timeout after <N> ms', Level::Error, 'TimeoutException', ['App\\Pay::charge'], 41, 3, GroupStatusLabel::Grown, [], new DateTimeImmutable('2026-10-23T05:00:00+00:00'), new DateTimeImmutable('2026-10-23T05:59:00+00:00'));

        return new TriageRequest([$group], 'Payments API.', 'triage-model-from-request', 321);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testRequestShapeAndCachedSystemPrefix(): void
    {
        $req = self::request();
        $this->analyzer([self::json('message-triage-valid.json')])->triage($req);

        $body = $this->body();
        self::assertSame('triage-model-from-request', $body['model']);
        self::assertSame(515, $body['max_tokens'], 'max(configured 321, 15 * 1 group + 500)');
        self::assertSame(['type' => 'disabled'], $body['thinking'], 'no thinking tokens against max_tokens for triage');
        self::assertSame(ProviderSchema::for('triage'), $body['output_config']['format']['schema']);
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertStringNotContainsString('maxLength', (string) json_encode($body['output_config']));

        $system = $body['system'];
        $text = implode("\n", array_column($system, 'text'));
        self::assertStringContainsString('Payments API.', $text);
        self::assertStringContainsString('noise', $text);
        self::assertStringContainsString('never invent a fingerprint', strtolower($text));
        self::assertStringContainsString('Never write URLs', $text);
        self::assertStringContainsString('data', $text);
        self::assertSame('ephemeral', $system[array_key_last($system)]['cache_control']['type']);

        $content = $body['messages'][0]['content'];
        self::assertSame(CompactPayload::encode($req->groups), is_string($content) ? $content : $content[0]['text']);
    }

    public function testValidResponseMapsVerdictsAndAllUsageFields(): void
    {
        $r = $this->analyzer([self::json('message-triage-valid.json')])->triage(self::request());

        self::assertSame(['fp-aaa' => 'interesting'], $r->verdicts);
        self::assertSame('triage-model-from-request', $r->usage->model, 'priced by the requested id');
        self::assertSame('claude-fixture-triage-1', $r->usage->returnedModel);
        self::assertSame([300, 20, 50, 100], [$r->usage->inputTokens, $r->usage->outputTokens, $r->usage->cacheReadTokens, $r->usage->cacheWriteTokens]);
    }

    public function testBadOutputRetriesOnceThenFails(): void
    {
        $a = $this->analyzer([self::json('message-not-json.json'), self::json('message-triage-valid.json')]);
        $r = $a->triage(self::request());
        self::assertCount(2, $this->history);
        self::assertSame(400, $r->usage->inputTokens, 'usage of both attempts is summed');

        $this->history = [];
        $this->expectException(AnalyzerFailed::class);
        $this->expectExceptionMessage('invalid model output');
        try {
            $this->analyzer([self::json('message-not-json.json'), self::json('message-not-json.json')])->triage(self::request());
        } finally {
            self::assertCount(2, $this->history);
        }
    }

    public function testUnknownFingerprintIsInvalidOutput(): void
    {
        $this->expectException(AnalyzerFailed::class);
        // analysis fixture is a different schema: fails validation both times
        $this->analyzer([self::json('message-valid.json'), self::json('message-valid.json')])->triage(self::request());
    }

    public function testAuthFailureHidesKeyAndIsNotChained(): void
    {
        try {
            $this->analyzer([self::json('error-401.json', 401)])->triage(self::request());
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('authentication failed', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    public function testServerErrorsMeanProviderUnavailable(): void
    {
        try {
            $this->analyzer([self::json('error-529.json', 529), self::json('error-529.json', 529), self::json('error-529.json', 529)])->triage(self::request());
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testMaxTokensNeverBelowConfiguredAndGrowsWithGroups(): void
    {
        $groups = array_fill(0, 100, self::request()->groups[0]);
        try {
            $this->analyzer([self::json('message-not-json.json'), self::json('message-not-json.json')])->triage(new TriageRequest($groups, 'ctx', 'm', 1500));
        } catch (AnalyzerFailed) {
        }
        self::assertSame(2000, $this->body()['max_tokens'], '15 * 100 + 500');

        $this->history = [];
        $this->analyzer([self::json('message-triage-valid.json')])->triage(new TriageRequest($groups, 'ctx', 'm', 9000));
        self::assertSame(9000, $this->body()['max_tokens']);
    }

    public function testTruncatedResponseRetriesOnceWithDoubledCap(): void
    {
        $r = $this->analyzer([self::json('message-triage-truncated.json'), self::json('message-triage-valid.json')])->triage(self::request());

        self::assertCount(2, $this->history);
        $first = json_decode((string) $this->history[0]['request']->getBody(), true);
        $second = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertSame(515, $first['max_tokens']);
        self::assertSame(1030, $second['max_tokens']);
        self::assertSame(['fp-aaa' => 'interesting'], $r->verdicts);
    }

    public function testDoubledCapIsBoundedBy8192(): void
    {
        $a = $this->analyzer([self::json('message-triage-truncated.json'), self::json('message-triage-valid.json')]);
        $a->triage(new TriageRequest(self::request()->groups, 'ctx', 'm', 6000));

        $second = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertSame(8192, $second['max_tokens']);
    }

    public function testFailedAfterTwoPaidAttemptsCarriesTheSummedUsage(): void
    {
        try {
            $this->analyzer([self::json('message-not-json.json'), self::json('message-not-json.json')])->triage(self::request());
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertNotNull($e->usage);
            self::assertSame('triage-model-from-request', $e->usage->model);
            self::assertSame(200, $e->usage->inputTokens);
        }
    }
}
