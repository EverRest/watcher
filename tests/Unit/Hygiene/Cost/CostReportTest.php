<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene\Cost;

use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\Cost\CostReport;

final class CostReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function data(bool $ledger = true, bool $flagged = false): array
    {
        return [
            'days' => [
                ['date' => '2026-10-07', 'runs' => 1, 'tokens_in' => 1200, 'tokens_out' => 300, 'tokens_cached' => 0, 'tokens' => 1500, 'cost_usd' => 0.0421,
                    'calls' => $ledger ? ['triage' => 1, 'analyze' => 1, 'escalate' => 0] : null, 'cap_share' => ['usd' => 0.0842, 'tokens' => null], 'flagged' => false, 'causes' => [], 'unbooked' => 0],
                ['date' => '2026-10-08', 'runs' => 1, 'tokens_in' => 9000, 'tokens_out' => 900, 'tokens_cached' => 10, 'tokens' => 9910, 'cost_usd' => 0.9,
                    'calls' => $ledger ? ['triage' => 1, 'analyze' => 1, 'escalate' => 2] : null, 'cap_share' => ['usd' => 1.8, 'tokens' => 0.0991], 'flagged' => $flagged,
                    'causes' => $flagged ? [['cause' => 'new_families', 'count' => 12], ['cause' => 'escalations', 'count' => 2]] : [], 'unbooked' => 3],
            ],
            'total' => ['runs' => 2, 'tokens' => 11410, 'cost_usd' => 0.9421, 'unbooked' => 3],
            'top_spenders' => $ledger ? [['fingerprint' => 'a1b2c3d4e5f6', 'template' => 'Boom <N>', 'tokens' => 900, 'runs' => 4]] : [],
            'median_cost_usd' => 0.4711,
            'ledger' => $ledger,
        ];
    }

    public function testTheBodyIsTheStableSchema(): void
    {
        $body = (new CostReport(/** @phpstan-ignore argument.type */ self::data()))->toArray();

        self::assertSame(['days', 'total', 'top_spenders', 'median_cost_usd', 'ledger'], array_keys($body));
        self::assertSame(['date', 'runs', 'tokens_in', 'tokens_out', 'tokens_cached', 'tokens', 'cost_usd', 'calls', 'cap_share', 'flagged', 'causes', 'unbooked'], array_keys($body['days'][0]));
        self::assertSame(['fingerprint', 'template', 'tokens', 'runs'], array_keys($body['top_spenders'][0]));
    }

    public function testTheTextShowsEachDayTheSpendersAndTheFlag(): void
    {
        $text = (new CostReport(/** @phpstan-ignore argument.type */ self::data(flagged: true)))->text();

        self::assertStringContainsString('2026-10-07', $text);
        self::assertStringContainsString('$0.0421', $text);
        self::assertStringContainsString('1 triage, 1 analyze, 0 escalate', $text);
        self::assertStringContainsString('SPIKE', $text);
        self::assertStringContainsString('12 new_families', $text);
        self::assertStringContainsString('a1b2c3d4e5f6', $text);
        self::assertStringContainsString('Boom <N>', $text);
        self::assertStringContainsString('total: 2 runs, 11410 tokens, $0.9421', $text);
        self::assertStringContainsString('3 call(s) not booked in the ledger', $text, 'the per-step numbers of that day are understated');
    }

    public function testWithoutALedgerTheTextSaysWhatIsNotRecorded(): void
    {
        $text = (new CostReport(/** @phpstan-ignore argument.type */ self::data(ledger: false)))->text();

        self::assertStringContainsString('calls per step and top spenders are not recorded', $text);
        self::assertStringNotContainsString('triage', $text);
    }

    public function testNoRunsIsEmpty(): void
    {
        $data = self::data();
        $data['total'] = ['runs' => 0, 'tokens' => 0, 'cost_usd' => 0.0];
        $report = new CostReport(/** @phpstan-ignore argument.type */ $data);

        self::assertTrue($report->isEmpty());
        self::assertSame('cost: nothing to report', $report->text());
        self::assertFalse((new CostReport(/** @phpstan-ignore argument.type */ self::data()))->isEmpty());
        self::assertSame('cost', $report->name());
    }
}
