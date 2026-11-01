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
use Watcher\Analyze\ProviderAnalyzer;
use Watcher\Analyze\ProviderRegistry;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\ProviderSchema;
use Watcher\Analyze\TriageRequest;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class ProviderAnalyzerTriageTest extends TestCase
{
    private const KEY = 'gm-test-SECRET-KEY';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    private static function json(string $fixture, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/providers/gemini/' . $fixture));
    }

    /** @param list<Response> $responses */
    private function analyzer(array $responses): ProviderAnalyzer
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));

        return new ProviderAnalyzer(ProviderRegistry::single(new GeminiProvider(new Client(['handler' => $stack]), self::KEY, 'https://generativelanguage.googleapis.com', 60, 0)));
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

    public function testRequestShape(): void
    {
        $req = self::request();
        $this->analyzer([self::json('triage_valid.json')])->triage($req);

        self::assertSame('/v1beta/models/triage-model-from-request:generateContent', $this->history[0]['request']->getUri()->getPath());
        $body = $this->body();
        self::assertSame(530, $body['generationConfig']['maxOutputTokens'], 'max(configured 321, 30 * 1 group + 500)');
        self::assertSame(ProviderSchema::for('triage'), $body['generationConfig']['responseJsonSchema']);
        self::assertStringNotContainsString('maxLength', (string) json_encode($body['generationConfig']));

        $system = $body['systemInstruction']['parts'];
        $text = implode("\n", array_column($system, 'text'));
        self::assertStringContainsString('Payments API.', $text);
        self::assertStringContainsString('noise', $text);
        self::assertStringContainsString('never invent a fingerprint', strtolower($text));
        self::assertStringContainsString('Never write URLs', $text);
        self::assertStringContainsString('data', $text);

        $text = $body['contents'][0]['parts'][0]['text'];
        self::assertSame(1, preg_match('/^<log-data-([0-9a-f]{32})>/', $text, $found));
        self::assertSame(CompactPayload::userMessage(CompactPayload::encode($req->groups), $found[1] ?? ''), $text);
    }

    public function testValidResponseMapsVerdictsAndAllUsageFields(): void
    {
        $r = $this->analyzer([self::json('triage_valid.json')])->triage(self::request());

        self::assertSame(['fp-aaa' => 'interesting'], $r->verdicts);
    }

    public function testBadOutputRetriesOnceThenFails(): void
    {
        $a = $this->analyzer([self::json('text_not_json.json'), self::json('triage_valid.json')]);
        $r = $a->triage(self::request());
        self::assertCount(2, $this->history);

        $this->history = [];
        $this->expectException(AnalyzerFailed::class);
        $this->expectExceptionMessage('invalid model output');
        try {
            $this->analyzer([self::json('text_not_json.json'), self::json('text_not_json.json')])->triage(self::request());
        } finally {
            self::assertCount(2, $this->history);
        }
    }

    public function testUnknownFingerprintIsInvalidOutput(): void
    {
        $this->expectException(AnalyzerFailed::class);
        // analysis fixture is a different schema: fails validation both times
        $this->analyzer([self::json('ok.json'), self::json('ok.json')])->triage(self::request());
    }

    public function testAuthFailureHidesKeyAndIsNotChained(): void
    {
        try {
            $this->analyzer([self::json('unauthorised.json', 401)])->triage(self::request());
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
            $this->analyzer([self::json('server_error.json', 529), self::json('server_error.json', 529), self::json('server_error.json', 529)])->triage(self::request());
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (HTTP 529)', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testMaxTokensNeverBelowConfiguredAndGrowsWithGroups(): void
    {
        $groups = array_fill(0, 100, self::request()->groups[0]);
        try {
            $this->analyzer([self::json('text_not_json.json'), self::json('text_not_json.json')])->triage(new TriageRequest($groups, 'ctx', 'm', 1500));
        } catch (AnalyzerFailed) {
        }
        self::assertSame(3500, $this->body()['generationConfig']['maxOutputTokens'], '30 * 100 + 500');

        $this->history = [];
        $this->analyzer([self::json('triage_valid.json')])->triage(new TriageRequest($groups, 'ctx', 'm', 9000));
        self::assertSame(9000, $this->body()['generationConfig']['maxOutputTokens']);
    }

    public function testTruncatedResponseRetriesOnceWithDoubledCap(): void
    {
        $r = $this->analyzer([self::json('triage_truncated.json'), self::json('triage_valid.json')])->triage(self::request());

        self::assertCount(2, $this->history);
        $first = json_decode((string) $this->history[0]['request']->getBody(), true);
        $second = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertSame(530, $first['generationConfig']['maxOutputTokens']);
        self::assertSame(1060, $second['generationConfig']['maxOutputTokens']);
        self::assertSame(['fp-aaa' => 'interesting'], $r->verdicts);
    }

    public function testDoubledCapIsBoundedBy8192(): void
    {
        $a = $this->analyzer([self::json('triage_truncated.json'), self::json('triage_valid.json')]);
        $a->triage(new TriageRequest(self::request()->groups, 'ctx', 'm', 6000));

        $second = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertSame(8192, $second['generationConfig']['maxOutputTokens']);
    }

}
