<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\NoticeRenderer;

final class NoticeRendererTest extends TestCase
{
    private const KEY = 'sk-ant-api03-FAKEFAKEFAKEFAKEFAKE0123456789';
    private const HOOK = 'https://hooks.slack.com/services/T000/B000/WEBHOOKSECRET123';

    public function testSourceFailureNotice(): void
    {
        $m = (new NoticeRenderer())->failure('svc', 'stg', 'source', 'loki 401 Unauthorized');

        self::assertSame('❌ watcher · svc · stg — source failed: loki 401 Unauthorized', $m->text);
        self::assertSame([['kind' => 'notice', 'text' => $m->text]], $m->sections);
    }

    public function testAnalysisFailureNotice(): void
    {
        $m = (new NoticeRenderer())->failure('svc', 'stg', 'analysis', 'provider unavailable');

        self::assertSame('❌ watcher · svc · stg — analysis failed: provider unavailable', $m->text);
    }

    public function testBudgetExhaustedNotice(): void
    {
        $m = (new NoticeRenderer())->budgetExhausted('svc', 'stg');

        self::assertSame('⚠️ watcher · svc · stg — budget exhausted: triage only today', $m->text);
    }

    public function testBudgetExhaustedNamesTheCap(): void
    {
        $r = new NoticeRenderer();

        self::assertSame('⚠️ watcher · svc · stg — budget exhausted (fleet cap): triage only today', $r->budgetExhausted('svc', 'stg', 'fleet')->text);
        self::assertSame('⚠️ watcher · svc · stg — budget exhausted (service cap): triage only today', $r->budgetExhausted('svc', 'stg', 'service')->text);
    }

    public function testUnknownKindIsAGenericRunFailure(): void
    {
        $m = (new NoticeRenderer())->failure('svc', 'stg', 'whatever', 'internal error (PDOException)');

        self::assertSame('❌ watcher · svc · stg — run failed: internal error (PDOException)', $m->text);
    }

    public function testReasonIsCappedAtTwoHundredCharacters(): void
    {
        $m = (new NoticeRenderer())->failure('svc', 'stg', 'source', str_repeat('boom ', 200));

        $reason = substr($m->text, strlen('❌ watcher · svc · stg — source failed: '));
        self::assertLessThanOrEqual(200, mb_strlen($reason));
        self::assertStringEndsWith('…', $reason);
    }

    public function testReasonCannotPingOrFormat(): void
    {
        $m = (new NoticeRenderer())->failure('svc', 'stg', 'source', "<@U123> *bold* `code`\nsecond line");

        $section = $m->sections[0]['text'];
        self::assertStringNotContainsString('<@', $section);
        self::assertStringNotContainsString('`', $section);
        self::assertStringNotContainsString("\n", $section);
    }

    public function testExceptionMessageWithKeyAndWebhookLeaksNeither(): void
    {
        $reason = 'request to ' . self::HOOK . ' failed, x-api-key: ' . self::KEY . ' rejected';

        foreach ([new NoticeRenderer(), new NoticeRenderer([self::KEY, self::HOOK])] as $renderer) {
            $m = $renderer->failure('svc', 'stg', 'analysis', $reason);

            foreach ([$m->text, $m->sections[0]['text']] as $text) {
                self::assertStringNotContainsString('FAKEFAKE', $text);
                self::assertStringNotContainsString('WEBHOOKSECRET', $text);
                self::assertStringNotContainsString('hooks.slack.com', $text);
            }
        }
    }

    public function testShortSecretsAreNotMaskedInsideOrdinaryWords(): void
    {
        $m = (new NoticeRenderer(['k']))->failure('svc', 'stg', 'source', 'token invalid');

        self::assertSame('❌ watcher · svc · stg — source failed: token invalid', $m->text);
    }

    public function testWeeklySummaryCarriesCountsAndCostOnly(): void
    {
        $m = (new NoticeRenderer())->weekly('svc', 'stg', '2026-W43', ['reported' => 1, 'quiet' => 5, 'baselined' => 0, 'failed' => 2, 'skipped' => 3], 0.12345);

        self::assertSame('📊 watcher · svc · stg — week 2026-W43: reported 1, quiet 5, baselined 0, failed 2, skipped 3, cost $0.1235', $m->text);
        self::assertSame([['kind' => 'notice', 'text' => $m->text]], $m->sections);
    }

    public function testWeeklySummaryEscapesServiceAndEnvironment(): void
    {
        $m = (new NoticeRenderer())->weekly('a<b>', 'x&y', '2026-W01', [], 0.0);

        self::assertStringNotContainsString("<b>", $m->sections[0]["text"]);
        self::assertStringContainsString('reported 0, quiet 0, baselined 0, failed 0, skipped 0, cost $0.0000', $m->text);
    }
}
