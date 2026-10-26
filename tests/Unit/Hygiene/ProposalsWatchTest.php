<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\DayRecord;
use Watcher\Hygiene\HygieneHistory;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Hygiene\Proposals;
use Watcher\Level;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\WatchRules;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ProposalsWatchTest extends TestCase
{
    private const NOW = '2026-10-08T06:00:00+00:00';

    /** @param array<string, array<string, mixed>> $watchRules */
    private function proposals(array $watchRules = [], ?HygieneSettings $s = null): Proposals
    {
        $history = new HygieneHistory(new InMemoryStateStore(new FrozenClock(new DateTimeImmutable(self::NOW))), 'svc', 2592000, new DateTimeZone('UTC'));

        return new Proposals($s ?? new HygieneSettings(enabled: true), Level::Error, [], $watchRules, $history);
    }

    /**
     * 16 days; on the first `$severeDays` of them (newest first) a severe family starts at 10:00 and the precursor at `$precursorAt`;
     * on the others only `$otherDays` of the precursor appear.
     *
     * @return array<string, DayRecord>
     */
    private static function history(int $severeDays, string $precursorAt = '09:40:00', bool $precursorEveryDay = false, ?string $tpl = 'Pool exhausted <N>'): array
    {
        $days = [];
        $i = 0;
        foreach (DayFactory::days(16, []) as $date => $_) {
            $families = [];
            if ($i < $severeDays) {
                $families[] = ['fp' => 'ssssssssssss', 'tpl' => 'Checkout failed', 'level' => 'error', 'n' => 20, 'first' => "{$date}T10:00:00+00:00"];
                $families[] = ['fp' => 'pppppppppppp', 'tpl' => $tpl, 'level' => 'warning', 'n' => 50, 'first' => "{$date}T{$precursorAt}+00:00"];
            } elseif ($precursorEveryDay) {
                $families[] = ['fp' => 'pppppppppppp', 'tpl' => $tpl, 'level' => 'warning', 'n' => 50, 'first' => "{$date}T03:00:00+00:00"];
            }
            $days[$date] = DayFactory::day($date, $families);
            ++$i;
        }

        return $days;
    }

    /**
     * @param array<string, DayRecord> $days
     * @return list<array<string, mixed>>
     */
    private function watch(Proposals $p, array $days): array
    {
        return array_values(array_filter($p->find($days, new DateTimeImmutable(self::NOW))['proposals'], static fn (array $x): bool => $x['type'] === 'watch'));
    }

    public function testAFamilyThatRepeatedlyStartsJustBeforeASevereOneIsProposedAsAWatchRule(): void
    {
        $w = $this->watch($this->proposals(), self::history(10));

        self::assertCount(1, $w);
        self::assertSame('open', $w[0]['status']);
        self::assertSame(['days_with_precursor' => 10, 'days_with_severe' => 10, 'lead_seconds' => 1800], $w[0]['evidence']);
        self::assertSame("'precursor-pppppp' => ['template' => '/Pool exhausted .*/'],", $w[0]['fragment']);
    }

    public function testTheProposedRuleMatchesTheTemplateItCameFrom(): void
    {
        $w = $this->watch($this->proposals(), self::history(10));
        self::assertSame(1, preg_match("/^'(precursor-[a-z]+)' => \['template' => '(.+)'\],$/", (string) $w[0]['fragment'], $m));
        $name = $m[1] ?? '';
        $regex = $m[2] ?? '';
        $t = new DateTimeImmutable(self::NOW);
        $rules = new WatchRules([$name => ['template' => $regex]]);

        self::assertSame([$name], $rules->namesMatchingGroup(new ProblemGroup('x', 'Pool exhausted <N>', Level::Warning, null, [], 3, $t, $t)));
        self::assertSame([], $rules->namesMatchingGroup(new ProblemGroup('x', 'Checkout failed', Level::Warning, null, [], 3, $t, $t)));
    }

    public function testRegexCharactersInTheTemplateAreEscaped(): void
    {
        $w = $this->watch($this->proposals(), self::history(10, tpl: 'Pool (a+b)? exhausted for <*> after <N> ms'));

        self::assertSame("'precursor-pppppp' => ['template' => '/Pool \\(a\\+b\\)\\? exhausted for .* after .* ms/'],", $w[0]['fragment']);
    }

    public function testInvisibleFormatCharactersNeverReachACopiedFragment(): void
    {
        $w = $this->watch($this->proposals(), self::history(10, tpl: "Pool\u{200B} exhaus\u{FEFF}ted\u{2060} for <*> after <N> ms\u{202E}"));

        self::assertSame("'precursor-pppppp' => ['template' => '/Pool exhausted for .* after .* ms/'],", $w[0]['fragment']);
        self::assertSame(0, preg_match('/\p{Cf}/u', $w[0]['fragment']));
    }

    public function testAPrecursorThatStartedTooEarlyOrAfterTheSevereFamilyIsNotOne(): void
    {
        self::assertSame([], $this->watch($this->proposals(), self::history(10, '08:00:00')), 'two hours before is outside the lead');
        self::assertSame([], $this->watch($this->proposals(), self::history(10, '10:05:00')), 'after the severe family');
    }

    public function testAFamilyThatIsThereEveryDayButPrecedesAProblemRarelyIsNoPrecursor(): void
    {
        self::assertSame([], $this->watch($this->proposals(), self::history(5, precursorEveryDay: true)));
    }

    public function testTwoCoincidencesAreNotEnough(): void
    {
        self::assertSame([], $this->watch($this->proposals(), self::history(2)));
    }

    public function testAFamilyWithoutATemplateCannotBecomeARule(): void
    {
        self::assertSame([], $this->watch($this->proposals(), self::history(10, tpl: null)));
    }

    public function testARuleAlreadyConfiguredIsNotProposedAgain(): void
    {
        $existing = ['mine' => ['template' => '/Pool exhausted .*/']];

        self::assertSame([], $this->watch($this->proposals($existing), self::history(10)));
    }

    public function testTheLeadComesFromTheSettings(): void
    {
        $s = new HygieneSettings(enabled: true, watchLead: 7200);

        self::assertCount(1, $this->watch($this->proposals([], $s), self::history(10, '08:30:00')));
    }

    public function testWithoutSevereFamiliesThereIsNothingToPrecede(): void
    {
        self::assertSame([], $this->watch($this->proposals(), self::history(0)));
    }

    public function testTheFloorPlaceholderIsAWildcardAndNeverCountsAsLiteralText(): void
    {
        $found = $this->watch($this->proposals(), self::history(10, tpl: 'Connection timeout for <w> failed'));
        self::assertCount(1, $found);
        self::assertStringContainsString("'template' => '/Connection timeout for .* failed/'", $found[0]['fragment']);
        self::assertStringNotContainsString('<w>', $found[0]['fragment']);
        self::assertStringNotContainsString('\\<w', $found[0]['fragment'], 'the placeholder is not quoted as text that never matches');

        self::assertSame([], $this->watch($this->proposals(), self::history(10, tpl: '<w> <w> <w>')), 'nine characters of placeholders are no literal text');
        self::assertSame([], $this->watch($this->proposals(), self::history(10, tpl: 'sync <w> <w> <*>')), 'under MIN_LITERAL real characters: no rule');
    }
}
