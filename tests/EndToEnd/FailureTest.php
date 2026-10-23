<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\RunOutcome;
use Watcher\Source\SourceUnavailable;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Watcher;

/** Quickstart scenarios 10-11 and every other way a run can fail (US5, FR-022, FR-031, FR-037). */
final class FailureTest extends TestCase
{
    private const API_KEY = 'sk-ant-api03-FAKEFAKEFAKEFAKEFAKE0123456789';
    private const DIGEST_HOOK = 'https://hooks.slack.test/services/T0/B0/DIGESTSECRET123';
    private const NOTICE_HOOK = 'https://hooks.slack.test/services/T0/B0/NOTICESECRET123';

    /** @return list<\Watcher\Source\LogEvent> */
    private static function alpha(int $n = 5): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', $n, 'Alpha');
    }

    /** @param list<\Watcher\Source\LogEvent> $events */
    private static function source(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** First window done, so the next run is a real one. */
    private static function baselined(Harness $h): void
    {
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
    }

    private static function runKey(Harness $h): string
    {
        return Keys::run('svc', Watcher::windowKey($h->windowEnd()));
    }

    public function testScenario10InvalidOutputTwiceFailsLoudlyWithOneNotice(): void
    {
        $h = new Harness();
        self::baselined($h);
        $sent = count($h->notifier->sent);
        $h->analyzer->failure = new AnalyzerFailed('output did not match schema twice');

        $result = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame(1, $result->exitCode());
        self::assertCount($sent + 1, $h->notifier->sent);
        $text = $h->sentTexts()[$sent];
        self::assertStringContainsString('analysis failed', $text);
        self::assertStringContainsString('output did not match schema twice', $text);
    }

    public function testScenario11SourceUnauthorisedFailsWithoutCallingTheModel(): void
    {
        $h = new Harness();
        self::baselined($h);
        $sent = count($h->notifier->sent);
        $source = self::source(self::alpha());
        $source->failWith(new SourceUnavailable('loki 401 Unauthorized'));

        $result = $h->watcher($source)->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame(1, $result->exitCode());
        self::assertCount($sent + 1, $h->notifier->sent);
        self::assertSame('❌ watcher · svc · stg — source failed: loki 401 Unauthorized', $h->notifier->sent[$sent]['message']->text);
        self::assertSame([], $h->analyzer->triageRequests);
        self::assertSame([], $h->analyzer->requests);
        self::assertNull($h->state->get(self::runKey($h)));
    }

    public function testProviderUnavailableKeepsTheSnapshotButNoRunRecordSoTheNextTriggerRetries(): void
    {
        $h = new Harness();
        self::baselined($h);
        $h->analyzer->failure = new AnalyzerFailed('provider unavailable');
        $events = [...self::alpha(), ...Harness::events(Level::Error, 'Beta failed in a unique way', 5, 'Beta')];

        $result = $h->watcher(self::source($events))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertStringContainsString('analysis failed: provider unavailable', $h->sentTexts()[count($h->sentTexts()) - 1]);
        self::assertNotNull($h->state->get(Keys::snapshot('svc', Watcher::windowKey($h->windowEnd()))), 'next window must compare against this one');
        self::assertNull($h->state->get(self::runKey($h)), 'no RunRecord: the next trigger in the hour runs again');

        $h->analyzer->failure = null;
        $again = $h->watcher(self::source($events))->run();

        self::assertSame(RunOutcome::Reported, $again->outcome);
    }

    public function testSlackDigestFailureIsLoggedOnceWithoutTheWebhookAndNoNoticeIsAttemptedOnTheSameChannel(): void
    {
        $h = new Harness(['notify' => ['digest' => ['webhook' => self::DIGEST_HOOK]]]);
        self::baselined($h);
        $attempts = $h->notifier->attempts;
        $h->notifier->failWith(new DeliveryFailed('slack delivery failed: HTTP 500'));
        $log = self::logger();

        $result = (new Watcher(WatchConfig::fromArray($h->config()), self::source(self::alpha(9)), $h->state, $h->analyzer, $h->notifier, $h->clock, $log))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame(1, $result->exitCode());
        self::assertSame($attempts + 1, $h->notifier->attempts, 'only the digest itself was attempted');
        self::assertCount(1, $log->records);
        self::assertSame('error', $log->records[0][0]);
        self::assertStringContainsString('slack delivery failed: HTTP 500', $log->records[0][1]);
        self::assertStringNotContainsString('DIGESTSECRET', $log->records[0][1] . json_encode($log->records[0][2]));
        self::assertNull($h->state->get(self::runKey($h)));
    }

    public function testStateStoreFailureExitsOneLogsNoDetailsAndStillTriesANotice(): void
    {
        $h = new Harness();
        $store = new FailingStateStore(new InMemoryStateStore($h->clock));
        $h->state = $store;
        $store->failOn('get');
        $log = self::logger();

        $result = (new Watcher(WatchConfig::fromArray($h->config()), self::source(self::alpha()), $h->state, $h->analyzer, $h->notifier, $h->clock, $log))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame(1, $result->exitCode());
        self::assertCount(2, $log->records); // the failure, then the liveness record that could not be written either
        self::assertSame('warning', $log->records[1][0]);
        self::assertStringNotContainsString('hunter2', $log->records[0][1] . $log->records[1][1] . $result->failureReason);
        self::assertCount(1, $h->notifier->sent);
        self::assertStringContainsString('run failed: internal error (RuntimeException)', $h->sentTexts()[0]);
    }

    public function testAFailingNoticeNeverMasksTheOriginalFailureNorCrashesTheRun(): void
    {
        $h = new Harness();
        self::baselined($h);
        $h->notifier->failWith(new DeliveryFailed('slack delivery failed: HTTP 500'));
        $source = self::source(self::alpha());
        $source->failWith(new SourceUnavailable('loki 503 Service Unavailable'));
        $log = self::logger();

        $result = (new Watcher(WatchConfig::fromArray($h->config()), $source, $h->state, $h->analyzer, $h->notifier, $h->clock, $log))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame('loki 503 Service Unavailable', $result->failureReason);
        self::assertTrue($h->state->acquire($h->lockKey(), 'next', 60), 'lock released');
    }

    public function testAnEmptyWindowIsNotAFailureButAnUnreachableSourceIs(): void
    {
        $h = new Harness();
        self::baselined($h);
        $sent = count($h->notifier->sent);

        $empty = $h->watcher(new FakeLogSource([], []))->run();

        self::assertSame(RunOutcome::Quiet, $empty->outcome);
        self::assertSame(0, $empty->exitCode());
        self::assertCount($sent, $h->notifier->sent, 'silent');

        $h->nextWindow();
        $down = self::source([]);
        $down->failWith(new SourceUnavailable('loki unreachable: timeout'));

        $failed = $h->watcher($down)->run();

        self::assertSame(RunOutcome::Failed, $failed->outcome);
        self::assertCount($sent + 1, $h->notifier->sent);
    }

    public function testNoticeGoesToTheNoticeWebhookWhenSetAndToTheDigestWebhookOtherwise(): void
    {
        $with = new Harness(['notify' => ['digest' => ['webhook' => self::DIGEST_HOOK], 'notice' => ['driver' => 'slack', 'webhook' => self::NOTICE_HOOK]]]);
        $without = new Harness(['notify' => ['digest' => ['webhook' => self::DIGEST_HOOK]]]);

        foreach ([$with, $without] as $h) {
            $source = self::source([]);
            $source->failWith(new SourceUnavailable('loki 401 Unauthorized'));
            $h->watcher($source)->run();
        }

        self::assertSame(self::NOTICE_HOOK, $with->notifier->sent[0]['to']->webhook());
        self::assertSame(self::DIGEST_HOOK, $without->notifier->sent[0]['to']->webhook());
    }

    public function testNoNoticeEverCarriesAKeyOrAWebhook(): void
    {
        $h = new Harness([
            'analysis' => ['api_key' => self::API_KEY],
            'notify' => ['digest' => ['webhook' => self::DIGEST_HOOK], 'notice' => ['driver' => 'slack', 'webhook' => self::NOTICE_HOOK]],
        ]);
        self::baselined($h);
        $leak = 'auth failed for ' . self::API_KEY . ' posting to ' . self::DIGEST_HOOK . ' and ' . self::NOTICE_HOOK;
        $source = self::source(self::alpha());
        $source->failWith(new SourceUnavailable($leak));
        $h->watcher($source)->run();
        $h->nextWindow();
        $h->analyzer->failure = new AnalyzerFailed($leak);
        $h->watcher(self::source(self::alpha(9)))->run();

        self::assertGreaterThanOrEqual(3, count($h->notifier->sent));
        foreach ($h->sentTexts() as $text) {
            foreach (['FAKEFAKE', 'DIGESTSECRET', 'NOTICESECRET', 'hooks.slack.test'] as $needle) {
                self::assertStringNotContainsString($needle, $text);
            }
        }
    }

    public function testAFailedDigestLeavesNothingBehindSoTheRetryInTheSameHourReportsAgain(): void
    {
        $h = new Harness();
        self::baselined($h);
        $h->notifier->failOnce(new DeliveryFailed('slack delivery failed: HTTP 500'));

        $failed = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::Failed, $failed->outcome);
        self::assertSame(1, $failed->exitCode());
        self::assertNull($h->state->get(self::runKey($h)));
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $kept = array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, 'verdict:') || str_starts_with($k, 'reported:'));
        self::assertSame([], array_values($kept), 'no verdict and no reported marker survives an undelivered digest');

        $again = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::Reported, $again->outcome);
        self::assertStringContainsString('Problem', $h->sentTexts()[count($h->sentTexts()) - 1]);
    }

    public function testAFailedDigestIsAnnouncedOnTheNoticeChannelWhenItDiffers(): void
    {
        $h = new Harness(['notify' => ['digest' => ['webhook' => self::DIGEST_HOOK], 'notice' => ['driver' => 'slack', 'webhook' => self::NOTICE_HOOK]]]);
        self::baselined($h);
        $h->notifier->failOnce(new DeliveryFailed('slack delivery failed: HTTP 500'));

        $result = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        $last = $h->notifier->sent[count($h->notifier->sent) - 1];
        self::assertSame(self::NOTICE_HOOK, $last['to']->webhook());
        self::assertStringContainsString('digest delivery failed: HTTP 500', $last['message']->text);
        self::assertStringNotContainsString('hooks.slack.test', $last['message']->text);
    }

    private static function logger(): SpyLogger
    {
        return new SpyLogger();
    }
}
