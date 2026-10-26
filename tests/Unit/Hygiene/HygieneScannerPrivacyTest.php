<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneScanner;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LogEvent;

final class HygieneScannerPrivacyTest extends TestCase
{
    private static function scanner(int $maxSites = 5000): HygieneScanner
    {
        $n = new Normalizer();

        return new HygieneScanner($n, new Redactor($n), new HygieneSettings(enabled: true, siteAllowDirs: ['auth', 'audit', 'mail', 'orders']), [], $maxSites, ['/var/www/html']);
    }

    /** @param array<string, scalar|null> $fields */
    private static function event(Level $level, string $message, array $fields = [], string $time = '2026-10-07T10:00:00+00:00'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable($time), $level, $message, null, [], $fields, []);
    }

    /** @return array<string, scalar> */
    private static function at(string $file, int $line): array
    {
        return ['file' => '/var/www/html/app/' . $file, 'line' => $line];
    }

    public function testCallSitesAreCountedPerCategoryAndLevel(): void
    {
        $s = self::scanner();
        foreach (['a@example.org', 'b@example.org', 'c@example.org'] as $mail) {
            $s->observe(self::event(Level::Error, 'SSO login failed', self::at('Auth/AuthService.php', 118) + ['user.email' => $mail, 'user.ip' => '203.0.113.9']));
        }
        $s->observe(self::event(Level::Info, 'Order created', self::at('Orders/OrderService.php', 20)));

        $r = $s->result();
        self::assertSame(4, $r->events);
        $sites = [];
        foreach ($r->sites as $site) {
            $sites[$site->id] = $site;
        }
        $auth = $sites['…/app/Auth/AuthService.php:118'];
        self::assertSame('error', $auth->level);
        self::assertSame(3, $auth->lines);
        self::assertSame(3, $auth->anyHit);
        self::assertSame(['email' => 3, 'address' => 3], $auth->cats);
        $order = $sites['…/app/Orders/OrderService.php:20'];
        self::assertSame(1, $order->lines);
        self::assertSame(0, $order->anyHit);
        self::assertSame([], $order->cats);
    }

    public function testASiteThatWritesBothCleanAndPersonalLinesKeepsTheShareAffected(): void
    {
        $s = self::scanner();
        $at = self::at('Mail/MailService.php', 64);
        $s->observe(self::event(Level::Info, 'Receipt sent to a@example.org', $at));
        foreach (range(1, 3) as $i) {
            $s->observe(self::event(Level::Info, 'Receipt sent to <queued>', $at));
        }

        [$site] = $s->result()->sites;
        self::assertSame(4, $site->lines);
        self::assertSame(1, $site->anyHit);
    }

    public function testFieldValuesAreScannedButFieldNamesMeanNothing(): void
    {
        $s = self::scanner();
        $at = self::at('A.php', 1);
        $s->observe(self::event(Level::Info, 'audit', $at + ['email' => 'n/a', 'name' => 'redacted', 'note' => 'ping x@example.org']));
        $s->observe(self::event(Level::Info, 'audit', $at + ['count' => 5, 'flag' => true, 'none' => null]));

        [$site] = $s->result()->sites;
        self::assertSame(['email' => 1], $site->cats);
        self::assertSame(1, $site->anyHit);
    }

    public function testOnlyTheFirstFortyFieldsAreRead(): void
    {
        $fields = [];
        for ($i = 0; $i < 60; ++$i) {
            $fields['f' . $i] = $i === 50 ? 'late@example.org' : 'x';
        }
        $s = self::scanner();
        $s->observe(self::event(Level::Info, 'wide', $fields));

        [$site] = $s->result()->sites;
        self::assertSame(0, $site->anyHit);
    }

    public function testASiteWithoutAFrameIsKeyedByTheFingerprint(): void
    {
        $s = self::scanner();
        $s->observe(self::event(Level::Warning, 'Payment declined for a@example.org'));
        $s->observe(self::event(Level::Warning, 'Payment declined for b@example.org'));

        [$site] = $s->result()->sites;
        self::assertSame('template', $site->kind);
        self::assertStringStartsWith('tpl:', $site->id);
        self::assertSame(2, $site->lines);
        self::assertSame(['email' => 2], $site->cats);
    }

    public function testTimesAreTheFirstAndLastSeen(): void
    {
        $s = self::scanner();
        $at = self::at('A.php', 1);
        $s->observe(self::event(Level::Info, 'x a@example.org', $at, '2026-10-07T12:00:00+00:00'));
        $s->observe(self::event(Level::Info, 'x a@example.org', $at, '2026-10-07T09:00:00+00:00'));

        [$site] = $s->result()->sites;
        self::assertSame('2026-10-07T09:00:00+00:00', $site->first->format(DATE_ATOM));
        self::assertSame('2026-10-07T12:00:00+00:00', $site->last->format(DATE_ATOM));
    }

    public function testTheSiteTableIsBoundedAndTheOverflowIsCounted(): void
    {
        $s = self::scanner(maxSites: 2);
        // Digits in a path are masked, so the files differ by letters.
        foreach (['Fa', 'Fb', 'Fc', 'Fd', 'Fe'] as $i => $file) {
            $s->observe(self::event(Level::Info, 'm', self::at("$file.php", $i + 1)));
        }
        $s->observe(self::event(Level::Info, 'm', self::at('Fa.php', 1)));

        $r = $s->result();
        self::assertCount(2, $r->sites);
        self::assertSame(3, $r->otherEvents);
        self::assertSame(6, $r->events);
        self::assertSame(2, $r->sites[0]->lines);
    }

    public function testNothingOfTheInputSurvivesInTheResult(): void
    {
        $s = self::scanner();
        $s->observe(self::event(Level::Error, 'Payment declined for Anna Kowalski anna.kowalski@example.org', self::at('P.php', 3) + ['phone' => '+380 67 123 45 67', 'tok' => 'ghp_16C7e42F292c6912E7710c838347A']));

        $dump = json_encode($s->result()->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['Anna', 'Kowalski', 'anna.kowalski', '+380', 'ghp_16C7', 'declined'] as $needle) {
            self::assertStringNotContainsString($needle, $dump);
        }
    }

    public function testAMessageThatCannotBeNormalisedDoesNotStopTheScan(): void
    {
        $s = self::scanner();
        $s->observe(self::event(Level::Info, "bad \xB1 bytes", self::at('A.php', 1)));
        $s->observe(self::event(Level::Info, 'fine a@example.org', self::at('B.php', 1)));

        self::assertSame(2, $s->result()->events);
    }

    public function testACardOrPhoneStoredAsAnIntegerIsStillCounted(): void
    {
        $s = self::scanner();
        $s->observe(self::event(Level::Info, 'charge', self::at('Pay.php', 1) + ['pan' => 4111111111111111, 'ratio' => 0.5, 'ok' => true, 'none' => null]));

        [$site] = $s->result()->sites;
        self::assertSame(['card' => 1], $site->cats);
    }

    public function testUnscannableLinesAreCountedNotSilentlyDropped(): void
    {
        $s = self::scanner();
        $s->observe(self::event(Level::Info, "bad \xB1 bytes", self::at('A.php', 1)));
        $s->observe(self::event(Level::Info, 'fine', self::at('A.php', 1) + ['v' => "also \xB2 bad"]));
        $s->observe(self::event(Level::Info, 'fine', self::at('A.php', 1)));

        self::assertSame(2, $s->result()->unscannable);
    }

    public function testManyUniqueCleanFilesCannotCrowdARealSiteOut(): void
    {
        $n = new Normalizer();
        $s = new HygieneScanner($n, new Redactor($n), new HygieneSettings(enabled: true), [], 3, ['/var/www/html']);
        foreach (range('a', 'j') as $i => $c) {
            $s->observe(self::event(Level::Info, 'noise', self::at("Job" . strtoupper($c) . $c . ".php", $i + 1)));
        }
        $s->observe(self::event(Level::Error, 'leak a@example.org', self::at('Mail.php', 9)));

        $ids = array_map(static fn ($x): string => $x->id, $s->result()->sites);
        self::assertContains('…/app/Mail.php:9', $ids, 'a site with a hit always gets a place');
        self::assertCount(4, $ids, '3 clean + 1 flagged');
        self::assertSame(7, $s->result()->otherEvents);
    }

    /** @return array<string, array{string}> messages whose SECOND word varies: each variant used to look like a site of its own */
    public static function varyingSecondWord(): array
    {
        return ['name after the varying word' => ['failed %s for Ivan Petrenko'], 'tenant prefix' => ['[Acmecorp] %s error'], 'tenant inside' => ['sync %s finished for tenant Acmecorp']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('varyingSecondWord')]
    public function testOneCodeLocationIsOneSiteHoweverManyMessageVariantsItWrites(string $format): void
    {
        $s = self::scanner();
        foreach (range(0, 29) as $i) {
            $s->observe(self::event(Level::Warning, sprintf($format, 'w' . chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'x'), self::at('Auth/Service.php', 5)));
        }

        $common = $s->commonWords();

        self::assertArrayNotHasKey('ivan', $common);
        self::assertArrayNotHasKey('petrenko', $common);
        self::assertArrayNotHasKey('acmecorp', $common);
    }

    public function testAWordAtEnoughDifferentFilesIsCommonButNotAtDifferentLinesOfOneFile(): void
    {
        $s = self::scanner();
        foreach (range(1, 8) as $line) {
            $s->observe(self::event(Level::Info, 'lookup Acmecorp done', self::at('Auth/Service.php', $line)));
        }
        self::assertArrayNotHasKey('acmecorp', $s->commonWords(), 'eight lines of one file are one site');

        foreach (['Audit/Logger.php', 'Orders/OrderService.php', 'Mail/MailService.php', 'Auth/AuthService.php'] as $file) {
            $s->observe(self::event(Level::Info, 'lookup Acmecorp done', self::at($file, 3)));
        }
        self::assertArrayHasKey('acmecorp', $s->commonWords(), 'five files');
    }

    public function testAMessageFamilyWithoutAnyCodeLocationCountsAsOneSource(): void
    {
        $s = self::scanner();
        foreach (range(0, 29) as $i) {
            $s->observe(self::event(Level::Warning, 'failed w' . chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'x for Ivan Petrenko'));
        }

        self::assertArrayNotHasKey('ivan', $s->commonWords());
    }
}
