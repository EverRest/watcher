<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\ProposalReport;

final class ProposalReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function data(): array
    {
        return ['history_days' => 16, 'days_needed' => 0, 'proposals' => [
            ['id' => 'a1b2c3d4e5', 'type' => 'mute', 'status' => 'open', 'fragment' => "['fingerprint' => 'a1b2c3d4e5f6', 'until' => null],",
                'evidence' => ['days_seen' => 16, 'events_per_day' => 8100], 'expected_saving' => ['events_per_day' => 8100],
                'replay' => ['days' => 16, 'candidates_removed' => 0]],
            ['id' => 'f6e5d4c3b2', 'type' => 'watch', 'status' => 'open', 'fragment' => "'precursor-pppppp' => ['template' => '/Pool exhausted .*/'],",
                'evidence' => ['days_with_precursor' => 10, 'days_with_severe' => 10, 'lead_seconds' => 1800]],
        ]];
    }

    public function testTheBodyIsTheStableSchema(): void
    {
        $body = (new ProposalReport(/** @phpstan-ignore argument.type */ self::data()))->toArray();

        self::assertSame(['history_days', 'days_needed', 'proposals'], array_keys($body));
        self::assertSame(self::data()['proposals'], $body['proposals']);
    }

    public function testTheTextGivesReadyToPasteFragmentsWithEvidenceAndReplay(): void
    {
        $text = (new ProposalReport(/** @phpstan-ignore argument.type */ self::data()))->text();

        self::assertStringContainsString("mute  a1b2c3d4e5  ['fingerprint' => 'a1b2c3d4e5f6', 'until' => null],", $text);
        self::assertStringContainsString('seen 16/16 days', $text);
        self::assertStringContainsString('8100 events/day', $text);
        self::assertStringContainsString('replay: 0 candidates removed over 16 days', $text);
        self::assertStringContainsString("watch f6e5d4c3b2  'precursor-pppppp' => ['template' => '/Pool exhausted .*/'],", $text);
        self::assertStringContainsString('precedes a severe family on 10 of 10 days', $text);
        self::assertStringContainsString('--decline=<id>', $text);
    }

    public function testShortHistoryNamesTheMissingDaysAndProposesNothing(): void
    {
        $r = new ProposalReport(['history_days' => 10, 'days_needed' => 4, 'proposals' => []]);

        self::assertTrue($r->isEmpty(), 'nothing to send');
        self::assertSame('proposals: 4 more day(s) of history needed (have 10)', $r->text());
    }

    public function testNoProposalsOnALongHistory(): void
    {
        $r = new ProposalReport(['history_days' => 20, 'days_needed' => 0, 'proposals' => []]);

        self::assertTrue($r->isEmpty());
        self::assertSame('proposals: nothing to report', $r->text());
        self::assertSame('proposals', $r->name());
    }
}
