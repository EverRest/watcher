<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Alert;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Alert\NewGroupAlerts;
use Watcher\Level;
use Watcher\Notify\DayGate;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Message;
use Watcher\Notify\NoticeRenderer;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\Candidate;
use Watcher\Select\CandidateReason;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Spec 018 US2: a new group at selection.min_level or higher is announced once, out of turn. */
final class NewGroupAlertsTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryStateStore $state;
    /** @var list<Message> */
    private array $sent = [];
    private int $failures = 0;
    private bool $refuse = false;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-02T10:00:00Z')); // a Monday
        $this->state = new InMemoryStateStore($this->clock);
    }

    /** @param list<CandidateReason> $reasons */
    private function candidate(string $fp, Level $level, GroupStatus $status = GroupStatus::New, array $reasons = [CandidateReason::New]): Candidate
    {
        $t = new DateTimeImmutable('2026-11-02T09:00:00Z');

        return new Candidate(new ProblemGroup($fp, 'tpl', $level, null, [], 3, $t, $t, null, $status), $reasons, 1);
    }

    private function alerts(?DayGate $days = null, bool $enabled = true, ?\Closure $send = null): NewGroupAlerts
    {
        return new NewGroupAlerts(
            state: $this->state,
            stateKey: 'svc',
            minLevel: Level::Error,
            retention: 86400,
            enabled: $enabled,
            days: $days,
            send: $send ?? function (Message $m): void {
                $this->sent[] = $m;
            },
            failed: function (DeliveryFailed $e): void {
                ++$this->failures;
            },
            notices: new NoticeRenderer(),
        );
    }

    /** @param list<Candidate> $candidates */
    private function deliver(NewGroupAlerts $alerts, array $candidates): void
    {
        $alerts->deliver($candidates, static fn (string $fp): ?string => null, 'svc', 'stg', $this->clock->now());
    }

    public function testANewErrorGroupIsSentOnceAndRecorded(): void
    {
        $alerts = $this->alerts();

        $this->deliver($alerts, [$this->candidate('aaaa11112222', Level::Error)]);

        self::assertCount(1, $this->sent);
        self::assertNotNull($this->state->get(Keys::newGroupAlert('svc', 'aaaa11112222')));
    }

    public function testTheSameGroupInASecondRunIsNotSentAgain(): void
    {
        $alerts = $this->alerts();
        $this->deliver($alerts, [$this->candidate('aaaa11112222', Level::Error)]);

        $this->deliver($alerts, [$this->candidate('aaaa11112222', Level::Error)]);

        self::assertCount(1, $this->sent);
    }

    public function testAWarningGroupBelowMinLevelIsNotSent(): void
    {
        $this->deliver($this->alerts(), [$this->candidate('bbbb11112222', Level::Warning)]);

        self::assertSame([], $this->sent);
    }

    public function testAGroupThatIsNotNewIsNotSent(): void
    {
        $grown = $this->candidate('cccc11112222', Level::Error, GroupStatus::Grown, [CandidateReason::Grown]);

        $this->deliver($this->alerts(), [$grown]);

        self::assertSame([], $this->sent);
    }

    public function testAFailedSendLeavesNoRecordSoTheNextRunRetriesIt(): void
    {
        $this->refuse = true;
        $alerts = $this->alerts(send: function (Message $m): void {
            if ($this->refuse) {
                throw new DeliveryFailed('send refused');
            }
            $this->sent[] = $m;
        });

        $this->deliver($alerts, [$this->candidate('dddd11112222', Level::Error)]);
        self::assertSame(1, $this->failures);
        self::assertNull($this->state->get(Keys::newGroupAlert('svc', 'dddd11112222')));

        $this->refuse = false;
        $this->deliver($alerts, [$this->candidate('dddd11112222', Level::Error)]);
        self::assertCount(1, $this->sent);
    }

    public function testNothingIsSentOrStoredOutsideNotifyDays(): void
    {
        $saturday = new FrozenClock(new DateTimeImmutable('2026-11-07T10:00:00Z'));
        $days = new DayGate(new \Watcher\Tests\Fakes\RecordingNotifier(), ['mon', 'tue', 'wed', 'thu', 'fri'], new DateTimeZone('UTC'), $saturday->now(...));

        $this->deliver($this->alerts($days), [$this->candidate('eeee11112222', Level::Error)]);

        self::assertSame([], $this->sent);
        self::assertNull($this->state->get(Keys::newGroupAlert('svc', 'eeee11112222')));
    }

    public function testNothingIsSentWhenTheSwitchIsOff(): void
    {
        $this->deliver($this->alerts(enabled: false), [$this->candidate('ffff11112222', Level::Error)]);

        self::assertSame([], $this->sent);
    }

    public function testTheMessageNamesTheGroupAndItsCount(): void
    {
        $this->deliver($this->alerts(), [$this->candidate('abcd11112222', Level::Error)]);

        self::assertStringContainsString('abcd11112222', $this->sent[0]->text);
        self::assertStringContainsString('3×', $this->sent[0]->text);
        self::assertStringContainsString('new error group', $this->sent[0]->text);
    }
}
