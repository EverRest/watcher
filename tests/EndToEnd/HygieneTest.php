<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\CostCalculator;
use Watcher\Hygiene\Cost\CostLedger;
use Watcher\Hygiene\DayRecord;
use Watcher\Hygiene\HygieneHistory;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Config\WatchConfig;
use Watcher\Hygiene\Hygiene;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Source\SourceUnavailable;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Unit\Hygiene\DayFactory;
use Watcher\Watcher;

final class HygieneTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function sites(\Watcher\Hygiene\HygieneResult $r): array
    {
        $out = [];
        foreach ($r->reports['privacy']->toArray()['sites'] as $row) {
            $out[$row['site']][$row['category']] = $row;
        }

        return $out;
    }

    public function testTheKnownCallSitesAreListedWithTheRightCategoriesAndCounts(): void
    {
        $h = new HygieneHarness();

        $r = $h->hygiene(HygieneHarness::source(HygieneHarness::fixture('hygiene.log')))->run(new HygieneOptions());

        self::assertSame(0, $r->exitCode());
        $sites = self::sites($r);
        self::assertCount(3, $sites, 'the clean site is not listed');
        self::assertSame(['email'], array_keys($sites['app/Auth/AuthService.php:118']));
        self::assertSame(['email', 'phone'], array_keys($sites['app/Mail/MailService.php:64']));
        self::assertSame(['token', 'address', 'card'], array_keys($sites['app/Audit/AuditLogger.php:33']));
        foreach ($sites as $categories) {
            foreach ($categories as $row) {
                self::assertSame(3, $row['count']);
                self::assertSame(3, $row['lines']);
                self::assertSame('new', $row['trend']);
            }
        }
        self::assertSame('error', $sites['app/Auth/AuthService.php:118']['email']['level']);
        self::assertSame('info', $sites['app/Mail/MailService.php:64']['email']['level']);
        self::assertSame('2026-10-07T10:00:01+00:00', $sites['app/Auth/AuthService.php:118']['email']['first_seen']);
    }

    public function testACleanStreamSaysNothingAndSendsNothing(): void
    {
        $h = new HygieneHarness();
        $events = [];
        foreach (range(1, 30) as $i) {
            $events[] = HygieneHarness::event(Level::Info, 'Order created', ['file' => '/var/www/html/app/Orders/O' . chr(96 + ($i % 6) + 1) . '.php', 'line' => 1]);
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy', 'shape'], notify: true));

        self::assertTrue($r->reports['privacy']->isEmpty());
        self::assertTrue($r->reports['shape']->isEmpty());
        self::assertSame([], $h->notifier->sent);
        self::assertStringContainsString('privacy: nothing to report', $r->text());
    }

    public function testNotifySendsTheNoticeOnceAndUnchangedSitesAreNotRepeated(): void
    {
        $h = new HygieneHarness();
        $events = HygieneHarness::fixture('hygiene.log');

        $first = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));
        self::assertCount(1, $h->notifier->sent);
        self::assertStringContainsString('AuthService.php:118', $h->notifier->sent[0]['message']->text);

        $h->clock->advance('+24 hours');
        $second = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));

        self::assertSame(0, $first->exitCode() + $second->exitCode());
        self::assertCount(1, $h->notifier->sent, 'unchanged, sent less than a week ago');
        self::assertSame('stable', self::sites($second)['app/Auth/AuthService.php:118']['email']['trend']);

        $h->clock->advance('+7 days');
        $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));
        self::assertCount(2, $h->notifier->sent, 'repeated once a week');
    }

    public function testAFailedDeliveryIsLoudAndTheSitesAreSentNextTime(): void
    {
        $h = new HygieneHarness();
        $events = HygieneHarness::fixture('hygiene.log');
        $h->notifier->failOnce(new DeliveryFailed('slack delivery failed: HTTP 500'));

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));

        self::assertSame(1, $r->exitCode());
        self::assertSame('notice delivery failed', $r->failure);

        $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));
        self::assertCount(1, $h->notifier->sent);
    }

    public function testASourceFailureIsLoud(): void
    {
        $h = new HygieneHarness();
        $source = new FakeLogSource();
        $source->failWith(new SourceUnavailable('loki 503 for https://secret.example/token'));

        $r = $h->hygiene($source)->run(new HygieneOptions());

        self::assertSame(1, $r->exitCode());
        self::assertSame('source unavailable', $r->failure);
        self::assertStringNotContainsString('secret', $r->text() . $r->toJson());
    }

    public function testAboveTheEventCapTheReportSaysItSampled(): void
    {
        $h = new HygieneHarness(['hygiene' => ['event_cap' => 5]]);
        $events = HygieneHarness::fixture('hygiene.log');

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));

        $json = json_decode($r->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($json['sampled']);
        self::assertSame(5, $json['events_read']);
        self::assertStringContainsString('sampled', $r->text());
    }

    public function testTheJsonHasTheDocumentedEnvelope(): void
    {
        $h = new HygieneHarness();

        $r = $h->hygiene(HygieneHarness::source(HygieneHarness::fixture('hygiene.log')))->run(new HygieneOptions(reports: ['privacy', 'shape']));

        $json = json_decode($r->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['schema', 'service', 'environment', 'generated_at', 'window', 'events_read', 'sampled', 'failure', 'notes', 'reports'], array_keys($json));
        self::assertNull($json['failure']);
        self::assertSame([], $json['notes']);
        self::assertSame(1, $json['schema']);
        self::assertSame('svc', $json['service']);
        self::assertSame(15, $json['events_read']);
        self::assertSame(['privacy', 'shape'], array_keys($json['reports']));
        self::assertSame(['start' => '2026-10-07T06:00:00+00:00', 'end' => '2026-10-08T06:00:00+00:00'], $json['window']);
    }

    public function testThePassStoresItsDayRecordWithAnExpiry(): void
    {
        $h = new HygieneHarness();
        $h->hygiene(HygieneHarness::source(HygieneHarness::fixture('hygiene.log')))->run(new HygieneOptions(reports: ['privacy']));

        $history = new HygieneHistory($h->state, 'svc', 2592000, new DateTimeZone('UTC'));
        $day = $history->day('2026-10-08');

        self::assertInstanceOf(DayRecord::class, $day);
        self::assertSame(15, $day->events);
        self::assertSame(3, count(array_filter($day->sites, static fn (array $s): bool => $s['any_hit'] > 0)));
        self::assertNotSame([], $day->families);
        self::assertSame(2592000, $h->state->ttls()[Keys::hygieneDay('svc', '2026-10-08')]);
        self::assertSame(2592000, $h->state->ttls()[Keys::hygieneSites('svc')]);
    }

    public function testWhenHygieneIsOffOnlyTheCostReportRunsAndNothingIsRead(): void
    {
        $h = new HygieneHarness(['hygiene' => ['enabled' => false]]);
        $source = HygieneHarness::source(HygieneHarness::fixture('hygiene.log'));

        $r = $h->hygiene($source)->run(new HygieneOptions());

        self::assertSame(0, $r->exitCode());
        self::assertSame(['cost'], array_keys($r->reports));
        self::assertTrue($r->skipped);
        self::assertSame([], $source->callsTo('lines'));
        self::assertSame([], $h->state->all(), 'nothing written');
    }

    public function testNotifyAndDeclineAreRefusedWhenHygieneIsOff(): void
    {
        $h = new HygieneHarness(['hygiene' => ['enabled' => false]]);

        self::assertSame(2, $h->hygiene(new FakeLogSource())->run(new HygieneOptions(notify: true))->exitCode());
        self::assertSame(2, $h->hygiene(new FakeLogSource())->run(new HygieneOptions(decline: 'abc'))->exitCode());
    }

    public function testTheMasterSwitchOffReadsNothing(): void
    {
        $h = new HygieneHarness(['enabled' => false]);
        $source = new FakeLogSource();

        $r = $h->hygiene($source)->run(new HygieneOptions());

        self::assertSame(0, $r->exitCode());
        self::assertTrue($r->disabled);
        self::assertSame([], $source->calls);
    }

    public function testCostOnlyDoesNotReadTheSourceAndSumsTheRunRecords(): void
    {
        $h = new HygieneHarness();
        $source = new FakeLogSource();
        $h->state->put(Keys::run('svc', Watcher::windowKey(new DateTimeImmutable('2026-10-08T06:00:00+00:00'))), [
            'outcome' => 'reported',
            'usage' => ['model' => 'mixed', 'inputTokens' => 1000, 'outputTokens' => 200, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'costUsd' => 0.05, 'returnedModel' => null],
            'startedAt' => '2026-10-08T06:00:00+00:00', 'finishedAt' => '2026-10-08T06:01:00+00:00',
        ], 3600);

        $r = $h->hygiene($source)->run(new HygieneOptions(reports: ['cost'], days: 3));

        self::assertSame([], $source->calls);
        self::assertSame(1200, $r->reports['cost']->toArray()['total']['tokens']);
        $ledger = new CostLedger($h->state, 'svc', 2592000, new DateTimeZone('UTC'), $h->clock, new CostCalculator([]));
        self::assertNull($ledger->day('2026-10-08'));
    }

    public function testProposalsComeFromTheStoredDaysAndADeclineSilencesThem(): void
    {
        $h = new HygieneHarness();
        $history = new HygieneHistory($h->state, 'svc', 2592000, new DateTimeZone('UTC'));
        foreach (DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'tpl' => 'GET /health', 'n' => 500]]) as $day) {
            $history->putDay($day);
        }

        $r = $h->hygiene(new FakeLogSource())->run(new HygieneOptions(reports: ['proposals']));
        $proposals = $r->reports['proposals']->toArray()['proposals'];
        self::assertCount(1, $proposals);

        $declined = $h->hygiene(new FakeLogSource())->run(new HygieneOptions(decline: $proposals[0]['id']));
        self::assertSame(0, $declined->exitCode());
        self::assertStringContainsString($proposals[0]['id'], $declined->text());

        $after = $h->hygiene(new FakeLogSource())->run(new HygieneOptions(reports: ['proposals']));
        self::assertSame([], $after->reports['proposals']->toArray()['proposals']);
    }

    public function testDecliningAnUnknownProposalIsAUsageError(): void
    {
        $h = new HygieneHarness();

        $r = $h->hygiene(new FakeLogSource())->run(new HygieneOptions(decline: 'nope'));

        self::assertSame(2, $r->exitCode());
        self::assertStringContainsString('unknown proposal', $r->text());
    }

    public function testASevereFamilyIsKeptInTheDayRecordEvenBeyondTheTopFamiliesByVolume(): void
    {
        $h = new HygieneHarness();
        $events = [];
        for ($f = 0; $f < 320; ++$f) {
            for ($k = 0; $k < 5; ++$k) {
                $events[] = HygieneHarness::event(Level::Info, 'Message ' . chr(97 + $f % 26) . chr(97 + intdiv($f, 26) % 26) . chr(97 + intdiv($f, 676)) . 'q kind' . $f);
            }
        }
        $events[] = HygieneHarness::event(Level::Critical, 'Database is gone', [], '2026-10-07T12:00:00+00:00', 'PDOException');

        $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));

        $day = (new HygieneHistory($h->state, 'svc', 2592000, new DateTimeZone('UTC')))->day('2026-10-08');
        self::assertNotNull($day);
        self::assertContains('critical', array_column($day->families, 'level'), 'a rare severe family must survive the cut');
        self::assertLessThanOrEqual(300, count($day->families));
    }

    public function testAStateFailureIsNamedAndTheJsonCarriesTheFailure(): void
    {
        $h = new HygieneHarness();
        $failing = new \Watcher\Tests\Fakes\FailingStateStore($h->state);
        $failing->failOn('get');
        $hygiene = new \Watcher\Hygiene\Hygiene(\Watcher\Config\WatchConfig::fromArray($h->config()), HygieneHarness::source(HygieneHarness::fixture('hygiene.log')), $failing, $h->notifier, $h->clock);

        $r = $hygiene->run(new HygieneOptions(reports: ['privacy']));

        self::assertSame(1, $r->exitCode());
        self::assertSame('state unavailable (RuntimeException)', $r->failure);
        $json = json_decode($r->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('state unavailable (RuntimeException)', $json['failure']);
        self::assertSame(['state error class: RuntimeException'], $json['notes'], 'the class, never the message');
        self::assertStringNotContainsString('hunter2', $r->text() . $r->toJson());
    }

    public function testNotesAndAnEmptyReportSetAreInTheJson(): void
    {
        $h = new HygieneHarness(['hygiene' => ['enabled' => false]]);

        $r = $h->hygiene(new FakeLogSource())->run(new HygieneOptions(reports: ['privacy']));

        self::assertStringContainsString('"reports":{}', $r->toJson());
    }

    public function testTheTimeBudgetStopsTheReadAndSaysItSampled(): void
    {
        $h = new HygieneHarness(['hygiene' => ['max_seconds' => 1]]);
        $events = [];
        for ($i = 0; $i < 1200; ++$i) {
            $events[] = HygieneHarness::event(Level::Info, 'Order created', ['file' => '/var/www/html/app/O.php', 'line' => 1]);
        }
        $calls = 0;
        $clock = new class ($calls) implements \Psr\Clock\ClockInterface {
            public function __construct(private int &$calls)
            {
            }

            public function now(): \DateTimeImmutable
            {
                ++$this->calls;

                return new \DateTimeImmutable('@' . (1_000_000 + 1000 * ($this->calls - 1))); // every reading finds the budget long gone
            }
        };
        $guard = new \Watcher\Schedule\RunGuard($clock, 1);

        $r = $h->hygiene(HygieneHarness::source($events), $guard)->run(new HygieneOptions(reports: ['privacy']));

        $json = json_decode($r->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($json['sampled']);
        self::assertLessThan(500, $json['events_read'], 'the source polls the guard inside its paging: the read stops long before the 500th event');
        self::assertSame(2, $calls, 'the guard read the start, then exactly one check');
        self::assertStringContainsString('time budget', $r->text());
    }

    public function testALongReadRenewsTheLockAndTheMemoryGuardStopsIt(): void
    {
        $h = new HygieneHarness();
        $events = [];
        for ($i = 0; $i < 1600; ++$i) {
            $events[] = HygieneHarness::event(Level::Info, 'Order created', ['file' => '/var/www/html/app/O.php', 'line' => 1]);
        }
        $probe = new \Watcher\Tests\Fakes\ProbeStateStore($h->state);
        $checks = 0;
        $guard = new \Watcher\Schedule\RunGuard($h->clock, null, function () use ($h, &$checks): array {
            ++$checks;
            $h->clock->advance('+1000 seconds'); // each check finds the run an hour older than lock_ttl allows
            if ($checks === 3) {
                return [90, 100]; // 90% of the limit: the memory guard
            }

            return [0, -1];
        });
        $hygiene = new Hygiene(WatchConfig::fromArray($h->config()), HygieneHarness::source($events), $probe, $h->notifier, $h->clock, $guard);

        $r = $hygiene->run(new HygieneOptions(reports: ['privacy']));

        self::assertContains(Keys::hygieneLock('svc'), $probe->renewed, 'the lock is extended while the read is alive');
        self::assertStringContainsString('memory', $r->text());
        $json = json_decode($r->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($json['sampled']);
    }

    public function testWhatTheSourcesParserGuessedOrDroppedIsANoteInTheReport(): void
    {
        $h = new HygieneHarness();
        $inner = HygieneHarness::source(HygieneHarness::fixture('hygiene.log'));
        $source = new class ($inner) implements \Watcher\Source\LogSource, \Watcher\Source\ReadDiagnostics {
            public function __construct(private readonly \Watcher\Source\LogSource $inner)
            {
            }

            public function totals(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector): array
            {
                return $this->inner->totals($window, $selector);
            }

            public function lines(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector, \Watcher\Source\ReadFilter $filter): iterable
            {
                return $this->inner->lines($window, $selector, $filter);
            }

            public function lastReadTruncated(): bool
            {
                return $this->inner->lastReadTruncated();
            }

            public function versions(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector, string $label): array
            {
                return $this->inner->versions($window, $selector, $label);
            }

            public function readDiagnostics(): array
            {
                return ['unknown_levels' => 3, 'orphan_trace_lines' => 0, 'message_tails_dropped' => 0, 'oversized_lines' => 2];
            }
        };

        $r = $h->hygiene($source)->run(new HygieneOptions(reports: ['privacy']));

        self::assertStringContainsString('read quality: 3 events with an unrecognised level, 2 lines over 64 KB dropped', $r->text());
        self::assertStringNotContainsString('read quality', $h->hygiene(HygieneHarness::source(HygieneHarness::fixture('hygiene.log')))->run(new HygieneOptions(reports: ['privacy']))->text());
    }

    public function testZeroWidthCharactersInsideAnEmailDoNotDefeatTheMask(): void
    {
        $h = new HygieneHarness();
        $events = [];
        for ($i = 0; $i < 3; ++$i) {
            $events[] = HygieneHarness::event(Level::Info, "login failed for jane\u{200B}.doe\u{200B}@example.com and ann\x01.lee\x08@example.org", ['file' => '/var/www/html/app/Auth.php', 'line' => 9]);
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));

        $json = $r->toJson();
        self::assertStringContainsString('"email"', $json, 'the site is counted as an e-mail site, not a host');
        self::assertStringNotContainsString('jane', $json);
        self::assertStringNotContainsString('jane', $r->text());
        $stored = json_encode($h->state->all(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("jane", $stored, "nor in the stored day record");
        self::assertStringNotContainsString("ann.lee", $json);
        self::assertStringNotContainsString("lee", $json);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function siblingPaths(): array
    {
        $zw = "jane\u{200B}.doe\u{200B}@example.com";
        $cc = "jane\x01.doe\x08@example.com";

        return [
            'zero-width email in a field value' => [['fields' => ['user' => $zw]]],
            'control email in a field value' => [['fields' => ['user' => $cc]]],
            'zero-width email in the error type' => [['type' => "Failure for $zw"]],
            'control email in the error type' => [['type' => "Failure for $cc"]],
            'zero-width email in a frame path' => [['frames' => ["/var/www/html/app/$zw.php:3"]]],
            'control email in a label' => [['labels' => ['user' => $cc]]],
        ];
    }

    /** @param array<string, mixed> $where */
    #[\PHPUnit\Framework\Attributes\DataProvider('siblingPaths')]
    public function testTheSameCleaningReachesEveryFieldTheScanReads(array $where): void
    {
        $h = new HygieneHarness();
        $events = [];
        for ($i = 0; $i < 3; ++$i) {
            $events[] = new \Watcher\Source\LogEvent(
                new DateTimeImmutable('2026-10-07T12:00:00+00:00'),
                Level::Info,
                'something happened',
                $where['type'] ?? null,
                $where['frames'] ?? [],
                ($where['fields'] ?? []) + ['file' => '/var/www/html/app/Auth.php', 'line' => 9],
                $where['labels'] ?? [],
            );
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));

        $all = $r->toJson() . $r->text() . json_encode($h->state->all());
        if (isset($where['fields'])) {
            self::assertStringContainsString('"email"', $r->toJson(), 'a field value is scanned: counted as an e-mail site');
        }
        self::assertStringNotContainsString('jane', $all);
        self::assertStringNotContainsString('doe', $all);
    }

    /** @return array<string, array{string}> */
    public static function encodedInvisibles(): array
    {
        return [
            'percent-encoded U+2800' => ['ivan%E2%A0%80@example.com'],
            'hex entity U+2800' => ['ivan&#x2800;@example.com'],
            'decimal entity U+2800' => ['ivan&#10240;@example.com'],
            'entity ESC' => ['ivan&#x1B;@example.com'],
            'entity BEL' => ['ivan&#x7;@example.com'],
            'entity U+0085' => ['ivan&#133;@example.com'],
            'raw unassigned U+2065' => ["ivan\u{2065}@example.com"],
            'percent-encoded unassigned U+2065' => ['ivan%E2%81%A5@example.com'],
            'raw line separator' => ["ivan\u{2028}@example.com"],
            'encoded paragraph separator' => ['ivan%E2%80%A9@example.com'],
            'double encoded' => ['ivan%26%23x2800%3B@example.com'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedInvisibles')]
    public function testAnInvisibleCharacterWrittenInAnyEncodingNextToAnAtSignCannotHideAnEmail(string $address): void
    {
        $h = new HygieneHarness();
        $events = [];
        foreach (range(0, 27) as $i) {
            $word = 'w' . chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'orvath';
            for ($k = 0; $k < 3; ++$k) {
                $events[] = HygieneHarness::event(Level::Warning, "login failed for $address reason $word", ['file' => '/var/www/html/app/Auth.php', 'line' => 9]);
            }
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy', 'shape'], notify: true));

        self::assertStringContainsString('"email"', $r->toJson(), 'categorised as an e-mail site, not a host');
        $sent = '';
        foreach ($h->notifier->sent as $s) {
            $sent .= $s['message']->text . implode('', array_column($s['message']->sections, 'text'));
        }
        $all = $r->toJson() . $r->text() . $sent . json_encode($h->state->all());
        self::assertNotSame('', $sent, 'a notice was sent, or this proves nothing');
        self::assertStringNotContainsString('ivan', $all);
    }

    public function testTerminalEscapesInvisibleFillersAndBidiOverridesNeverReachTheOutput(): void
    {
        $h = new HygieneHarness();
        $events = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $who) {
            $events[] = HygieneHarness::event(Level::Warning, "user $who@example.org said \x1b[31mred\x1b]0;owned\x07 \u{2800}\u{3164}\u{034F}\u{202E}evil\u{0085}done", ['file' => '/var/www/html/app/Send.php', 'line' => 3]);
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy', 'proposals']));

        $all = $r->text() . $r->toJson() . $r->reports['privacy']->text(true) . json_encode($h->state->all());
        foreach (["\x1b", "\x07", "\u{2800}", "\u{3164}", "\u{034F}", "\u{202E}", "\u{0085}", '\u001b', '\u202e', '\u2800'] as $needle) {
            self::assertStringNotContainsString($needle, $all, bin2hex($needle));
        }
        self::assertStringContainsString('app/Send.php:3', $r->text(), 'the readable part stays');
    }

    public function testAnEmptyButSuccessfulReadIsNotACompleteDayAndRetractsNothing(): void
    {
        $h = new HygieneHarness();
        $events = [];
        for ($i = 0; $i < 3; ++$i) {
            $events[] = HygieneHarness::event(Level::Info, 'sent to a' . $i . '@example.org', ['file' => '/var/www/html/app/Mail.php', 'line' => 1]);
        }
        $opts = new HygieneOptions(reports: ['privacy'], notify: true);
        $h->clock->set(new DateTimeImmutable('2026-10-07T06:00:00Z'));
        $h->hygiene(HygieneHarness::source($events))->run($opts);

        // Wrong stream_tags after a deploy, a rotated file, Loki answering 200 with []: five "quiet" days in a row.
        $notes = [];
        foreach (['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'] as $day) {
            $h->clock->set(new DateTimeImmutable($day . 'T06:00:00Z'));
            $r = $h->hygiene(HygieneHarness::source([]))->run($opts);
            $notes[] = implode(' ', $r->notes);
        }

        self::assertCount(1, $h->notifier->sent, 'the first day was sent; an empty source never sends "gone since the last report"');
        self::assertStringContainsString('no events', $notes[0], 'and it says why');
        $registry = $h->state->get(Keys::hygieneSites('svc')) ?? [];
        foreach ($registry as $entry) {
            self::assertSame(0, $entry['absent'], 'no absence is counted against an empty read');
        }
    }

    public function testAConstantMessageWithANameIsNeverShownAsALabelOrStored(): void
    {
        $h = new HygieneHarness();
        $events = [];
        for ($i = 0; $i < 4; ++$i) {
            $events[] = HygieneHarness::event(Level::Info, 'Welcome Jane Doe to Acme, mail a@example.org', ['file' => '/var/www/html/app/Welcome.php', 'line' => 2]);
        }
        // A wide family: the same sentence for many different people. Its constant words are shared by all of them.
        foreach (range(0, 24) as $i) {
            $name = chr(65 + $i % 26) . 'orvath' . chr(97 + intdiv($i, 26));
            for ($k = 0; $k < 3; ++$k) {
                $events[] = HygieneHarness::event(Level::Info, "Request sent to $name at u$i@example.org", ['file' => '/var/www/html/app/Greet.php', 'line' => 4]);
            }
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));

        $all = $r->toJson() . json_encode($h->state->all());
        self::assertStringNotContainsString('Jane', $all);
        self::assertStringNotContainsString('Doe', $all);
        self::assertStringContainsString('Request sent', $all, 'a family folded from many different messages keeps its label');
    }

    public function testAnAnonymousFailureClassNeverCarriesAFileNameOrANulByte(): void
    {
        $h = new HygieneHarness();
        $source = HygieneHarness::source(HygieneHarness::fixture('hygiene.log'));
        // an anonymous exception class: its name is "RuntimeException@anonymous\0/path/to/file.php:12$0"
        $anon = new class ('x') extends \RuntimeException {
        };
        $failing = new class ($anon) implements \Watcher\State\StateStore {
            public function __construct(private readonly \Throwable $e)
            {
            }

            public function get(string $key): ?array
            {
                throw $this->e;
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return true;
            }

            public function release(string $key, string $owner): void
            {
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return 0.0;
            }
        };

        $r = (new Hygiene(WatchConfig::fromArray($h->config()), $source, $failing, $h->notifier, $h->clock))->run(new HygieneOptions(reports: ['privacy']));

        self::assertSame(1, $r->exitCode());
        $all = $r->toJson() . $r->text();
        self::assertStringNotContainsString("\0", $all);
        self::assertStringNotContainsString('.php', $all);
        self::assertStringContainsString('@anonymous', $all);
    }

    public function testDecliningTakesTheSameLockAsAPass(): void
    {
        $h = new HygieneHarness();
        $h->state->acquire(Keys::hygieneLock('svc'), 'another-pass', 600);

        $r = $h->hygiene(HygieneHarness::source([]))->run(new HygieneOptions(reports: ['proposals'], decline: 'abcdef0123'));

        self::assertSame(1, $r->exitCode());
        self::assertStringContainsString('another hygiene pass is running', $r->text());
    }

    /** @return list<\Watcher\Source\LogEvent> 28 messages of one wide family whose constant words include a person's name */
    private static function nameInAWideFamily(): array
    {
        $events = [];
        foreach (range(0, 27) as $i) {
            $word = 'w' . chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'orvath';
            for ($k = 0; $k < 3; ++$k) {
                $events[] = HygieneHarness::event(Level::Info, "user Ivan Petrenko logged in reason $word at ivan$i@example.org", ['file' => '/var/www/html/app/Auth/Login.php', 'line' => 9]);
            }
        }

        return $events;
    }

    public function testAConstantNameInsideAWideFamilyIsNotPrintedInClear(): void
    {
        $h = new HygieneHarness();

        $r = $h->hygiene(HygieneHarness::source(self::nameInAWideFamily()))->run(new HygieneOptions(reports: ['privacy'], notify: true));

        $sent = '';
        foreach ($h->notifier->sent as $s) {
            $sent .= $s['message']->text . implode('', array_column($s['message']->sections, 'text'));
        }
        $all = $r->toJson() . $r->text() . $sent . json_encode($h->state->all());
        self::assertStringNotContainsString('Petrenko', $all);
        self::assertStringNotContainsString('Ivan', $all);
        self::assertStringContainsString('logged in', $all, 'common log words stay readable');
    }

    /** @return array<string, array{string}> */
    public static function varyingSecondWord(): array
    {
        return ['name' => ['failed %s for Ivan Petrenko'], 'tenant prefix' => ['[Acmecorp] %s error'], 'tenant inside' => ['sync %s finished for tenant Acmecorp']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('varyingSecondWord')]
    public function testAConstantNameInAFamilyWhoseSecondWordVariesIsNotPrinted(string $format): void
    {
        $h = new HygieneHarness();
        $events = [];
        foreach (range(0, 29) as $i) {
            for ($k = 0; $k < 3; ++$k) {
                $events[] = HygieneHarness::event(Level::Warning, sprintf($format, 'w' . chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'orvath') . " a$i@example.org", ['file' => '/var/www/html/app/Auth/Login.php', 'line' => 9]);
            }
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));

        $sent = '';
        foreach ($h->notifier->sent as $s) {
            $sent .= $s['message']->text . implode('', array_column($s['message']->sections, 'text'));
        }
        $all = $r->toJson() . $r->text() . $sent . json_encode($h->state->all());
        foreach (['Ivan', 'Petrenko', 'Acmecorp'] as $name) {
            self::assertStringNotContainsString($name, $all, $name);
        }
    }

    public function testAnEmptyRerunOrABackDatedNowNeverReplacesAGoodDayRecord(): void
    {
        $h = new HygieneHarness();
        $events = HygieneHarness::fixture('hygiene.log');
        $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));
        $day = (new \Watcher\Hygiene\HygieneHistory($h->state, 'svc', 2592000, new DateTimeZone('UTC')))->day('2026-10-08');
        self::assertNotNull($day);
        self::assertGreaterThan(0, $day->events);

        $h->hygiene(HygieneHarness::source([]))->run(new HygieneOptions(reports: ['privacy']));
        $h->hygiene(HygieneHarness::source([]))->run(new HygieneOptions(reports: ['privacy'], now: new DateTimeImmutable('2026-10-08T06:00:00+00:00')));

        $after = (new \Watcher\Hygiene\HygieneHistory($h->state, 'svc', 2592000, new DateTimeZone('UTC')))->day('2026-10-08');
        self::assertSame($day->events, $after->events ?? null, 'the good record of that date is still there');
        self::assertSame(count($day->families), count($after->families ?? []), 'with its families (the severity memory of the date)');
    }

    public function testALiteralSeenAtEnoughDifferentSitesIsNotAName(): void
    {
        $h = new HygieneHarness(['hygiene' => ['literal_min_sites' => 5]]);
        $events = self::nameInAWideFamily();
        foreach (['Auth', 'Audit', 'Orders', 'Mail', 'Http', 'Jobs'] as $i => $dir) {
            $events[] = HygieneHarness::event(Level::Info, "Petrenko lookup done for a$i@example.org", ['file' => "/var/www/html/app/$dir/X.php", 'line' => 3]);
        }

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));

        self::assertStringContainsString('Petrenko', $r->toJson() . json_encode($h->state->all()), 'six sites use the word: it is vocabulary of this log, not one person');
    }

    public function testConstantLabelsAreAnOptInAndOffByDefault(): void
    {
        $events = [];
        for ($i = 0; $i < 4; ++$i) {
            $events[] = HygieneHarness::event(Level::Info, 'Connection timeout exceeded, mail a@example.org', ['file' => '/var/www/html/app/Mail/Pool.php', 'line' => 2]);
        }
        $default = new HygieneHarness();
        $optIn = new HygieneHarness(['hygiene' => ['allow_constant_labels' => true]]);

        $off = $default->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']))->toJson();
        $on = $optIn->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']))->toJson();

        self::assertStringNotContainsString('Connection timeout', $off);
        self::assertStringContainsString('Connection timeout exceeded', $on);
    }

    public function testTheGuardAndTheLockRenewalRunInsideTheSourcePagingNotOnlyEvery500Events(): void
    {
        $h = new HygieneHarness();
        $probe = new \Watcher\Tests\Fakes\ProbeStateStore($h->state);
        $pages = 0;
        // A source whose pages are slow and nearly empty: three events per page, a page every 20 minutes of the (frozen) clock.
        $source = new class ($h->clock, $pages) implements \Watcher\Source\LogSource {
            public function __construct(private readonly \Watcher\Tests\Fakes\FrozenClock $clock, private int &$pages)
            {
            }

            public function totals(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector): array
            {
                return [];
            }

            public function lines(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector, \Watcher\Source\ReadFilter $filter): iterable
            {
                for ($page = 0; $page < 50; ++$page) {
                    if ($filter->shouldStop()) {
                        return;
                    }
                    ++$this->pages;
                    $this->clock->advance('+20 minutes');
                    foreach (range(1, 3) as $i) {
                        yield HygieneHarness::event(Level::Info, 'Order created', ['file' => '/var/www/html/app/Orders/O.php', 'line' => 1]);
                    }
                }
            }

            public function lastReadTruncated(): bool
            {
                return false;
            }

            public function versions(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector, string $label): array
            {
                return [];
            }
        };
        $guard = new \Watcher\Schedule\RunGuard($h->clock, 3600); // one hour of budget: the fourth page is past it
        $hygiene = new Hygiene(WatchConfig::fromArray($h->config()), $source, $probe, $h->notifier, $h->clock, $guard);

        $r = $hygiene->run(new HygieneOptions(reports: ['privacy']));

        self::assertLessThanOrEqual(4, $pages, 'the read stopped at the budget inside the source, long before 500 events');
        self::assertStringContainsString('time budget', $r->text());
        self::assertContains(Keys::hygieneLock('svc'), $probe->renewed, 'and the lock was renewed from the same check');
    }

    public function testADryRunWritesNothingTakesNoBlockingLockAndSendsNothing(): void
    {
        $h = new HygieneHarness();
        $events = HygieneHarness::fixture('hygiene.log');
        $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));
        $before = json_encode($h->state->all());
        $h->state->acquire(Keys::hygieneLock('svc'), 'a-scheduled-pass', 600); // a real pass is running right now
        $before = json_encode($h->state->all());

        $r = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy', 'shape'], notify: true, dryRun: true));

        self::assertSame(0, $r->exitCode(), 'a pass holding the lock does not stop a read-only look');
        self::assertSame($before, json_encode($h->state->all()), 'no day record, registry, counter or lock was written');
        self::assertSame([], $h->notifier->sent, 'nothing was sent');
        self::assertStringContainsString('dry run', $r->text());
        self::assertStringContainsString('privacy:', $r->text(), 'it still prints what it would report');
    }

    public function testANowFarInTheFutureIsRefusedUnlessItIsADryRun(): void
    {
        $h = new HygieneHarness();
        $future = $h->clock->now()->modify('+3 days');

        $refused = $h->hygiene(HygieneHarness::source([]))->run(new HygieneOptions(reports: ['privacy'], now: $future));
        $dry = $h->hygiene(HygieneHarness::source([]))->run(new HygieneOptions(reports: ['privacy'], now: $future, dryRun: true));

        self::assertSame(2, $refused->exitCode());
        self::assertStringContainsString('--now', $refused->text());
        self::assertSame([], $h->state->all(), 'a future --now never reached the registry');
        self::assertSame(0, $dry->exitCode());
    }

    public function testOverlappingPassesDoNotRaceAndTheLockIsReleased(): void
    {
        $h = new HygieneHarness();
        $events = HygieneHarness::fixture('hygiene.log');
        $h->state->acquire(Keys::hygieneLock('svc'), 'another-pass', 600);

        $busy = $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']));
        self::assertSame(1, $busy->exitCode());
        self::assertSame('another hygiene pass is running', $busy->failure);

        $h->state->release(Keys::hygieneLock('svc'), 'another-pass');
        self::assertSame(0, $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']))->exitCode());
        self::assertSame(0, $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy']))->exitCode(), 'released after the pass');
    }
    public function testTheLockOutlivesTheReadAndTheNoticePhase(): void
    {
        $h = new HygieneHarness();
        $spy = new class ($h->state) implements \Watcher\State\StateStore {
            public ?int $lockTtl = null;

            public function __construct(private readonly \Watcher\State\StateStore $inner)
            {
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                $this->inner->put($key, $value, $ttlSeconds);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                $this->lockTtl = $ttlSeconds;

                return $this->inner->acquire($key, $owner, $ttlSeconds);
            }

            public function release(string $key, string $owner): void
            {
                $this->inner->release($key, $owner);
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return $this->inner->increment($key, $by, $ttlSeconds);
            }
        };
        $hygiene = new \Watcher\Hygiene\Hygiene(\Watcher\Config\WatchConfig::fromArray($h->config()), HygieneHarness::source(HygieneHarness::fixture('hygiene.log')), $spy, $h->notifier, $h->clock);

        $hygiene->run(new HygieneOptions(reports: ['privacy']));

        // max_seconds (900) + the notice budget (schedule.lock_ttl, 900) + 60
        self::assertSame(900 + 900 + 60, $spy->lockTtl);
    }

    public function testAFailedLockReleaseNeverChangesTheResult(): void
    {
        $h = new HygieneHarness();
        $failing = new \Watcher\Tests\Fakes\FailingStateStore($h->state);
        $failing->failOn('release');
        $hygiene = new \Watcher\Hygiene\Hygiene(\Watcher\Config\WatchConfig::fromArray($h->config()), HygieneHarness::source(HygieneHarness::fixture('hygiene.log')), $failing, $h->notifier, $h->clock);

        $r = $hygiene->run(new HygieneOptions(reports: ['privacy'], notify: true));

        self::assertSame(0, $r->exitCode());
        self::assertNull($r->failure);
        self::assertArrayHasKey('privacy', $r->reports);
        self::assertNotSame([], $h->notifier->sent, 'the notice went out');
        self::assertContains('lock release failed (RuntimeException)', $r->notes);
        self::assertStringNotContainsString('hunter2', $r->text() . $r->toJson());
    }
}
