<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Hygiene\PrivacyReport;
use Watcher\Hygiene\ScanResult;
use Watcher\Hygiene\SiteRegistry;
use Watcher\Hygiene\SiteStats;

final class PrivacyReportTest extends TestCase
{
    private const NOW = '2026-10-08T06:00:00+00:00';

    /** @param array<string, int> $cats */
    private static function stats(string $key, string $id, string $level, int $lines, int $anyHit, array $cats, string $kind = 'frame', string $fp = 'a1b2c3d4e5f6'): SiteStats
    {
        $s = new SiteStats($key, $id, $kind, $fp, $level, new DateTimeImmutable('2026-10-07T10:00:00+00:00'), new DateTimeImmutable('2026-10-07T18:00:00+00:00'));
        $s->lines = $lines;
        $s->anyHit = $anyHit;
        $s->cats = $cats;

        return $s;
    }

    /** @param list<SiteStats> $sites */
    private static function scan(array $sites, int $events = 1000, int $other = 0): ScanResult
    {
        return new ScanResult($events, $other, $sites, []);
    }

    /** @param array<string, array<string, mixed>> $stored */
    private static function registry(array $stored = []): SiteRegistry
    {
        return new SiteRegistry($stored, new DateTimeImmutable(self::NOW), new HygieneSettings(), 2592000);
    }

    /**
     * @param list<array{tpl: string, th?: string, positions: int, distinct_messages: int}> $variable
     * @param array<string, ?string> $labels
     */
    private static function build(ScanResult $scan, ?SiteRegistry $registry = null, array $variable = [], array $labels = []): PrivacyReport
    {
        return PrivacyReport::build($scan, $labels, $variable, $registry ?? self::registry());
    }

    public function testOnlySitesWithAHitAreListedOneRowPerCategory(): void
    {
        $report = self::build(self::scan([
            self::stats('k1', '…/app/Auth/AuthService.php:118', 'error', 1900, 412, ['email' => 412, 'address' => 100]),
            self::stats('k2', '…/app/Orders/OrderService.php:20', 'info', 500, 0, []),
        ]));

        $body = $report->toArray();
        self::assertCount(2, $body['sites']);
        self::assertSame(
            ['site' => '…/app/Auth/AuthService.php:118', 'kind' => 'frame', 'label' => null, 'level' => 'error', 'category' => 'email', 'count' => 412,
                'lines' => 1900, 'share_of_lines' => 0.2168, 'first_seen' => '2026-10-07T10:00:00+00:00', 'last_seen' => '2026-10-07T18:00:00+00:00', 'trend' => 'new'],
            $body['sites'][0],
        );
        self::assertSame(['email', 'address'], array_column($body['sites'], 'category'));
        self::assertFalse($report->isEmpty());
    }

    public function testRowsAreOrderedByCountThenSite(): void
    {
        $report = self::build(self::scan([
            self::stats('k1', 'b.php:1', 'info', 10, 3, ['email' => 3]),
            self::stats('k2', 'a.php:1', 'info', 10, 3, ['email' => 3]),
            self::stats('k3', 'c.php:1', 'info', 10, 9, ['email' => 9]),
        ]));

        self::assertSame(['c.php:1', 'a.php:1', 'b.php:1'], array_column($report->toArray()['sites'], 'site'));
    }

    public function testTheLabelIsTheAllowedTemplateOfTheSitesFingerprint(): void
    {
        $report = self::build(self::scan([self::stats('k1', 'a.php:1', 'info', 10, 3, ['email' => 3], fp: 'a1b2c3d4e5f6')]), labels: ['a1b2c3d4e5f6' => 'Receipt sent to <EMAIL>']);

        self::assertSame('Receipt sent to <EMAIL>', $report->toArray()['sites'][0]['label']);
    }

    public function testVariableFreeTextIsListedWithCountsOnly(): void
    {
        $report = self::build(self::scan([]), variable: [['tpl' => 'Payment declined for <*>', 'positions' => 1, 'distinct_messages' => 87]]);

        self::assertSame([['template' => 'Payment declined for <*>', 'positions' => 1, 'distinct_messages' => 87, 'trend' => 'new']], $report->toArray()['variable_text']);
        self::assertFalse($report->isEmpty());
    }

    public function testNothingToSayIsEmpty(): void
    {
        $report = self::build(self::scan([self::stats('k1', 'a.php:1', 'info', 10, 0, [])]));

        self::assertTrue($report->isEmpty());
        self::assertSame(['sites' => [], 'variable_text' => [], 'gone' => [], 'other_events' => 0, 'unscannable' => 0], $report->toArray());
        self::assertSame('privacy: nothing to report', $report->text());
    }

    public function testTheNoticeKeepsOnlyWhatChangedOrIsDue(): void
    {
        $sent = self::registry();
        $sent->observe(['k1' => ['id' => 'a.php:1', 'kind' => 'frame', 'count' => 10, 'first' => new DateTimeImmutable('2026-10-01T00:00:00+00:00')]]);
        $sent->markReported(['k1']);
        $stored = $sent->toArray();
        $registry = new SiteRegistry($stored, new DateTimeImmutable('2026-10-10T06:00:00+00:00'), new HygieneSettings(), 2592000);

        $report = PrivacyReport::build(self::scan([
            self::stats('k1', 'a.php:1', 'info', 100, 11, ['email' => 11]),
            self::stats('k2', 'b.php:1', 'info', 100, 5, ['email' => 5]),
        ]), [], [], $registry);

        self::assertSame(['stable', 'new'], array_column($report->toArray()['sites'], 'trend'));
        self::assertSame(['b.php:1'], array_column($report->notice()->toArray()['sites'], 'site'));
        self::assertSame(['k2'], $report->notice()->sentKeys());
    }

    public function testASiteThatStoppedAppearingIsReportedAsGoneInTheNotice(): void
    {
        $sent = self::registry();
        $sent->observe(['k1' => ['id' => 'a.php:1', 'kind' => 'frame', 'count' => 10, 'first' => new DateTimeImmutable('2026-10-01T00:00:00+00:00')]]);
        $sent->markReported(['k1']);
        $first = new SiteRegistry($sent->toArray(), new DateTimeImmutable('2026-10-09T06:00:00+00:00'), new HygieneSettings(repeatDays: 2), 2592000);
        $first->observe([]);
        $registry = new SiteRegistry($first->toArray(), new DateTimeImmutable('2026-10-10T06:00:00+00:00'), new HygieneSettings(repeatDays: 2), 2592000);

        $report = PrivacyReport::build(self::scan([]), [], [], $registry);

        self::assertSame(['a.php:1'], $report->toArray()['gone']);
        self::assertSame(['a.php:1'], $report->notice()->toArray()['gone']);
        self::assertStringContainsString('a.php:1', $report->text());
    }

    public function testTheTextCarriesIdentifiersAndNumbersOnly(): void
    {
        $text = self::build(self::scan([self::stats('k1', '…/app/Auth/AuthService.php:118', 'error', 1900, 412, ['email' => 412])], 5000, 3))->text();

        self::assertStringContainsString('…/app/Auth/AuthService.php:118', $text);
        self::assertStringContainsString('error', $text);
        self::assertStringContainsString('email', $text);
        self::assertStringContainsString('412 of 1900 lines', $text);
        self::assertStringContainsString('[new]', $text);
        self::assertStringContainsString('3 events outside the site table', $text);
    }

    public function testBodyKeysAreAnAllowlist(): void
    {
        $body = self::build(self::scan([self::stats('k1', 'a.php:1', 'info', 10, 3, ['email' => 3])]), variable: [['tpl' => 't <*>', 'positions' => 1, 'distinct_messages' => 30]])->toArray();

        self::assertSame(['sites', 'variable_text', 'gone', 'other_events', 'unscannable'], array_keys($body));
        self::assertSame(['site', 'kind', 'label', 'level', 'category', 'count', 'lines', 'share_of_lines', 'first_seen', 'last_seen', 'trend'], array_keys($body['sites'][0]));
        self::assertSame(['template', 'positions', 'distinct_messages', 'trend'], array_keys($body['variable_text'][0]));
    }

    public function testTheVariableRowKeepsItsRegistryKeyWhenAWordCrossesTheFloor(): void
    {
        $registry = self::registry();
        self::build(self::scan([]), $registry, [['tpl' => 'sync <*> finished for <w>', 'th' => 'abcdef012345678', 'positions' => 1, 'distinct_messages' => 30]]);
        $first = array_keys($registry->toArray());

        $registry2 = self::registry();
        self::build(self::scan([]), $registry2, [['tpl' => 'sync <*> finished for tenant', 'th' => 'abcdef012345678', 'positions' => 1, 'distinct_messages' => 30]]);

        self::assertSame($first, array_keys($registry2->toArray()), 'keyed on the unfloored template, not on the words that happen to pass the floor today');
    }
}
