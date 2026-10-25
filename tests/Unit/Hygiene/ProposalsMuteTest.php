<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneHistory;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Hygiene\Proposals;
use Watcher\Level;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ProposalsMuteTest extends TestCase
{
    private const NOW = '2026-10-08T06:00:00+00:00';

    private HygieneHistory $history;

    protected function setUp(): void
    {
        $this->history = new HygieneHistory(new InMemoryStateStore(new FrozenClock(new DateTimeImmutable(self::NOW))), 'svc', 2592000, new DateTimeZone('UTC'));
    }

    /** @param list<array<string, mixed>> $mute */
    private function proposals(?HygieneSettings $s = null, Level $severe = Level::Error, array $mute = []): Proposals
    {
        return new Proposals($s ?? new HygieneSettings(enabled: true), $severe, $mute, [], $this->history);
    }

    /**
     * @param array<string, \Watcher\Hygiene\DayRecord> $days
     * @return array{history_days: int, days_needed: int, proposals: list<array<string, mixed>>}
     */
    private function find(Proposals $p, array $days): array
    {
        return $p->find($days, new DateTimeImmutable(self::NOW));
    }

    public function testBeforeTheHistoryIsLongEnoughNothingIsProposedAndTheMissingDaysAreSaid(): void
    {
        $r = $this->find($this->proposals(), DayFactory::days(10, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]));

        self::assertSame(['history_days' => 10, 'days_needed' => 4, 'proposals' => []], $r);
    }

    public function testAStableHighVolumeFamilyThatWasNeverSevereIsProposedForMuting(): void
    {
        $r = $this->find($this->proposals(), DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'tpl' => 'GET /health <N>', 'n' => 500, 'tokens' => 40]]));

        self::assertSame(0, $r['days_needed']);
        self::assertCount(1, $r['proposals']);
        $p = $r['proposals'][0];
        self::assertSame('mute', $p['type']);
        self::assertSame('open', $p['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $p['id']);
        self::assertSame("['fingerprint' => 'aaaaaaaaaaaa', 'until' => null],", $p['fragment']);
        self::assertSame(['days_seen' => 16, 'events_per_day' => 500, 'tokens' => 640], $p['evidence']);
        self::assertSame(['tokens_per_day' => 40.0, 'events_per_day' => 500], $p['expected_saving']);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function severity(): iterable
    {
        yield 'info' => ['info', 'error', true];
        yield 'warning below error' => ['warning', 'error', true];
        yield 'error at the line' => ['error', 'error', false];
        yield 'critical' => ['critical', 'error', false];
        yield 'emergency' => ['emergency', 'error', false];
        yield 'warning when the line is warning' => ['warning', 'warning', false];
        yield 'info when the line is warning' => ['info', 'warning', true];
        yield 'debug' => ['debug', 'error', true];
    }

    #[DataProvider('severity')]
    public function testAFamilyAtOrAboveTheCandidateLevelIsNeverProposed(string $level, string $line, bool $proposed): void
    {
        $r = $this->find($this->proposals(severe: Level::parse($line)), DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'level' => $level, 'n' => 500]]));

        self::assertSame($proposed, $r['proposals'] !== []);
    }

    /** @return iterable<string, array{int}> */
    public static function whichDay(): iterable
    {
        yield 'newest' => [0];
        yield 'middle' => [8];
        yield 'oldest' => [15];
    }

    #[DataProvider('whichDay')]
    public function testASingleSevereDayAnywhereInTheHistoryBlocksTheMute(int $index): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]);
        $dates = array_keys($days);
        $days[$dates[$index]] = DayFactory::day($dates[$index], [['fp' => 'aaaaaaaaaaaa', 'level' => 'critical', 'n' => 500]]);

        self::assertSame([], $this->find($this->proposals(), $days)['proposals']);
    }

    public function testTheSameTemplateSevereUnderAnotherFingerprintBlocksTheMute(): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'tpl' => 'Cache miss <N>', 'n' => 500]]);
        $d = (string) array_key_first($days);
        $days[$d] = DayFactory::day($d, [['fp' => 'aaaaaaaaaaaa', 'tpl' => 'Cache miss <N>', 'n' => 500], ['fp' => 'bbbbbbbbbbbb', 'tpl' => 'Cache miss <N>', 'level' => 'error', 'n' => 1]]);

        self::assertSame([], $this->find($this->proposals(), $days)['proposals']);
    }

    public function testTooQuietTooRareOrUnstableFamiliesAreNotProposed(): void
    {
        $quiet = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 50]]);
        self::assertSame([], $this->find($this->proposals(), $quiet)['proposals'], 'below mute_min_daily');

        $rare = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]);
        foreach (array_slice(array_keys($rare), 4) as $date) {
            $rare[$date] = DayFactory::day($date, []);
        }
        self::assertSame([], $this->find($this->proposals(), $rare)['proposals'], 'seen on 4 of 16 days');

        $unstable = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]);
        $d = (string) array_key_last($unstable);
        $unstable[$d] = DayFactory::day($d, [['fp' => 'aaaaaaaaaaaa', 'n' => 100]]);
        self::assertSame([], $this->find($this->proposals(), $unstable)['proposals'], 'max is more than 3x min');
    }

    public function testAFamilyAlreadyMutedInTheConfigIsNotProposed(): void
    {
        $p = $this->proposals(mute: [['fingerprint' => 'aaaaaaaaaaaa', 'until' => null]]);

        self::assertSame([], $this->find($p, DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]))['proposals']);
    }

    public function testADeclinedProposalStaysQuietUntilTheDeclineExpires(): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]);
        $id = $this->find($this->proposals(), $days)['proposals'][0]['id'];

        $this->history->decline($id, new DateTimeImmutable(self::NOW), 90);
        self::assertSame([], $this->find($this->proposals(), $days)['proposals']);

        $later = $this->proposals()->find($days, new DateTimeImmutable('2027-01-10T06:00:00+00:00'));
        self::assertCount(1, $later['proposals']);
    }

    public function testProposalsAreOrderedByVolumeAndTheIdIsDeterministic(): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 300], ['fp' => 'bbbbbbbbbbbb', 'n' => 900], ['fp' => 'cccccccccccc', 'n' => 600]]);

        $a = $this->find($this->proposals(), $days)['proposals'];
        $b = $this->find($this->proposals(), $days)['proposals'];

        self::assertSame(['bbbbbbbbbbbb', 'cccccccccccc', 'aaaaaaaaaaaa'], array_map(static fn (array $p): string => preg_match("/'fingerprint' => '([0-9a-f]{12})'/", $p['fragment'], $m) === 1 ? $m[1] : '', $a));
        self::assertSame($a, $b);
    }

    public function testTheThresholdsComeFromTheSettings(): void
    {
        $days = DayFactory::days(5, [['fp' => 'aaaaaaaaaaaa', 'n' => 20]]);
        $s = new HygieneSettings(enabled: true, minHistoryDays: 5, muteMinDaily: 10);

        self::assertCount(1, $this->find($this->proposals($s), $days)['proposals']);
    }

    public function testSampledDaysAreMissingHistoryNotEvidence(): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]);
        foreach (array_slice(array_keys($days), 0, 3) as $date) {
            $d = $days[$date];
            $days[$date] = new \Watcher\Hygiene\DayRecord($d->date, $d->events, true, 0, $d->sites, $d->families, $d->formats, $d->variable);
        }

        $r = $this->find($this->proposals(), $days);

        self::assertSame(['history_days' => 13, 'days_needed' => 1, 'proposals' => []], $r);
    }

    public function testAFamilyThatWasSevereOnlyOnASampledDayIsStillNotProposedBecauseThatDayIsIgnoredEntirely(): void
    {
        $days = DayFactory::days(20, [['fp' => 'aaaaaaaaaaaa', 'n' => 500]]);
        $d = (string) array_key_first($days);
        $days[$d] = new \Watcher\Hygiene\DayRecord($d, 1000, true, 0, [], [], [], []);

        self::assertCount(1, $this->find($this->proposals(), $days)['proposals'], '19 complete days of evidence remain; the partial one says nothing either way');
    }

    public function testATemplateWithoutEnoughLiteralTextIsNotProposedAsAWatchRule(): void
    {
        $days = [];
        $i = 0;
        foreach (DayFactory::days(16, []) as $date => $_) {
            $days[$date] = DayFactory::day($date, $i++ < 10 ? [
                ['fp' => 'ssssssssssss', 'tpl' => 'Checkout failed', 'level' => 'error', 'n' => 20, 'first' => "{$date}T10:00:00+00:00"],
                ['fp' => 'pppppppppppp', 'tpl' => '<*> <*>', 'level' => 'warning', 'n' => 50, 'first' => "{$date}T09:40:00+00:00"],
                ['fp' => 'qqqqqqqqqqqq', 'tpl' => 'Boom <N>', 'level' => 'warning', 'n' => 50, 'first' => "{$date}T09:41:00+00:00"],
            ] : []);
        }

        $types = array_column($this->find($this->proposals(), $days)['proposals'], 'type');

        self::assertNotContains('watch', $types, 'a rule of /.*/ would match every line');
    }

    public function testAConstantMessageThatWasSevereUnderAnotherFingerprintIsNotMutedEvenWithoutAStoredTemplate(): void
    {
        $same = [['fp' => 'aaaaaaaaaaaa', 'level' => 'error', 'n' => 500, 'th' => 'deadbeefcafe'], ['fp' => 'bbbbbbbbbbbb', 'level' => 'info', 'n' => 500, 'th' => 'deadbeefcafe']];
        $other = [['fp' => 'aaaaaaaaaaaa', 'level' => 'error', 'n' => 500, 'th' => 'deadbeefcafe'], ['fp' => 'bbbbbbbbbbbb', 'level' => 'info', 'n' => 500, 'th' => '0123456789ab']];

        $guarded = $this->find($this->proposals(), DayFactory::days(16, $same));
        $control = $this->find($this->proposals(), DayFactory::days(16, $other));

        self::assertSame([], array_column($guarded['proposals'], 'fragment'), 'same template hash as a severe family: no mute');
        self::assertNotSame([], array_column($control['proposals'], 'fragment'), 'a different template is muted as before');
    }

    public function testSafetyAMuteProposalCanNeverHideAnErrorOrHigherGroup(): void
    {
        // One family is an error on a single day out of sixteen and quiet info on the rest; another is info for the whole period.
        $days = DayFactory::days(16, [['fp' => 'bbbbbbbbbbbb', 'level' => 'info', 'n' => 500], ['fp' => 'cccccccccccc', 'level' => 'critical', 'n' => 500]]);
        $oldest = (string) array_key_last($days);
        $days[$oldest] = DayFactory::day($oldest, [['fp' => 'bbbbbbbbbbbb', 'level' => 'error', 'n' => 1], ['fp' => 'cccccccccccc', 'level' => 'critical', 'n' => 500]]);

        $found = $this->find($this->proposals(), $days)['proposals'];

        $fragments = implode("\n", array_column($found, 'fragment'));
        self::assertStringNotContainsString('bbbbbbbbbbbb', $fragments, 'severe once within the history: never muted');
        self::assertStringNotContainsString('cccccccccccc', $fragments);
        foreach ($found as $p) {
            self::assertMatchesRegularExpression("/\\A\\['fingerprint' => '[0-9a-f]{12}', 'until' => null\\],\\z/", $p['fragment'], 'a mute carries a fingerprint and nothing else: no template text, no matcher');
        }
    }
}
