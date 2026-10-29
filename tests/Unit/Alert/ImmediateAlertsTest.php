<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Alert;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Alert\ImmediateAlerts;
use Watcher\Level;
use Watcher\Notify\Message;
use Watcher\Notify\NoticeRenderer;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** alerts.immediate: matching, one message per repeated alert, masking, the window, and failure isolation. */
final class ImmediateAlertsTest extends TestCase
{
    private const WINDOW = 900;

    /**
     * @param array<string, array{level: Level, message: ?string, action: ?string}> $rules
     * @param list<string>                                                          $fields
     */
    private static function alerts(array $rules, array $fields = ImmediateAlerts::FIELDS): ImmediateAlerts
    {
        return new ImmediateAlerts($rules, new Redactor(new Normalizer()), new NoticeRenderer(), 'm-seq', 'prod', $fields);
    }

    public function testNoContextValueLeavesTheServiceUnlessListed(): void
    {
        $sent = self::deliverAll(
            self::alerts(self::healthRule(), []),
            self::state(),
            self::event('health check failed', Level::Critical, ['service' => 'orders-api', 'error' => 'refused']),
        );

        self::assertSame("🚨 ALERT [critical] m-seq: health check failed\nprod · 2026-10-29 10:00:00 UTC", $sent[0]->text);
    }

    public function testTwoServicesWithTheSameMessageAreTwoAlertsEvenWithoutFields(): void
    {
        $sent = self::deliverAll(
            self::alerts(self::healthRule(), []),
            self::state(),
            self::event('health check failed', Level::Critical, ['service' => 'orders-api']),
            self::event('health check failed', Level::Critical, ['service' => 'payments']),
        );

        self::assertCount(2, $sent);
    }

    public function testMoreThanTheLimitHoldsTheRestAndTheyGoOnTheNextRead(): void
    {
        $state = self::state();
        $events = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $svc) {
            $events[] = self::event('health check failed', Level::Critical, ['service' => $svc]);
        }

        $first = self::deliverAll(self::alerts(self::healthRule(), []), $state, ...$events);
        $second = self::deliverAll(self::alerts(self::healthRule(), []), $state, ...$events);

        self::assertCount(ImmediateAlerts::MAX_PER_READ + 1, $first);
        self::assertStringContainsString('+2 more not sent (limit 5 per read)', $first[ImmediateAlerts::MAX_PER_READ]->text);
        self::assertCount(2, $second, 'the two held back alerts, and no summary line');
        self::assertStringStartsWith('🚨 ALERT [critical] m-seq: health check failed', $second[0]->text);
    }

    /** @return array<string, array{level: Level, message: ?string, action: ?string}> */
    private static function healthRule(Level $level = Level::Critical, ?string $message = 'health check failed', ?string $action = null): array
    {
        return ['health-check' => ['level' => $level, 'message' => $message, 'action' => $action]];
    }

    /** @param array<string, scalar|null> $fields */
    private static function event(string $message, Level $level = Level::Critical, array $fields = [], string $at = '2026-10-29T10:00:00Z'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable($at), $level, $message, null, [], $fields, []);
    }

    private static function state(): StateStore
    {
        return new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-29T10:00:00Z')));
    }

    /** @return list<Message> */
    private static function deliverAll(ImmediateAlerts $alerts, StateStore $state, LogEvent ...$events): array
    {
        foreach ($events as $e) {
            $alerts->observe($e);
        }
        $sent = [];
        $alerts->deliver($state, 'm-seq', self::WINDOW, static function (Message $m) use (&$sent): void {
            $sent[] = $m;
        }, static fn (\Throwable $e) => throw $e);

        return $sent;
    }

    public function testLevelIsAMinimum(): void
    {
        $sent = self::deliverAll(
            self::alerts(self::healthRule(Level::Critical, null)),
            self::state(),
            self::event('db down', Level::Error),
            self::event('db down', Level::Critical),
            self::event('db down', Level::Alert),
        );

        self::assertCount(1, $sent, 'one distinct alert: the error line is below the minimum, the two others share one message');
        self::assertStringContainsString('(×2)', $sent[0]->text);
    }

    public function testMessageIsAPlainCaseSensitiveSubstring(): void
    {
        $sent = self::deliverAll(
            self::alerts(self::healthRule()),
            self::state(),
            self::event('health check failed for orders-api'),
            self::event('health check passed'),
            self::event('Health Check Failed'),
        );

        self::assertCount(1, $sent);
        self::assertStringContainsString('health check failed for orders-api', $sent[0]->text);
    }

    public function testActionMustEqualTheActionFieldExactly(): void
    {
        $rules = self::healthRule(Level::Critical, null, 'health.check');
        $sent = self::deliverAll(
            self::alerts($rules),
            self::state(),
            self::event('x', Level::Critical, ['action' => 'health.check']),
            self::event('y', Level::Critical, ['action' => 'health.checkx']),
            self::event('z', Level::Critical),
        );

        self::assertCount(1, $sent);
    }

    public function testRepeatsInOneReadBecomeOneMessageWithACount(): void
    {
        $sent = self::deliverAll(
            self::alerts(self::healthRule()),
            self::state(),
            self::event('health check failed', Level::Critical, ['service' => 'orders-api']),
            self::event('health check failed', Level::Critical, ['service' => 'orders-api']),
            self::event('health check failed', Level::Critical, ['service' => 'orders-api']),
        );

        self::assertCount(1, $sent);
        self::assertStringStartsWith('🚨 ALERT [critical] m-seq: health check failed (×3)', $sent[0]->text);
    }

    public function testOnlyAllowlistedFieldsAreRenderedAndTheyAreMasked(): void
    {
        $sent = self::deliverAll(
            self::alerts(self::healthRule()),
            self::state(),
            self::event('health check failed', Level::Critical, [
                'service' => 'orders-api',
                'http_code' => 503,
                'error' => 'connect refused for jane@example.com',
                'user_email' => 'jane@example.com',
                'token' => 'abcdef1234567890',
            ]),
        );

        $text = $sent[0]->text;
        self::assertStringContainsString("service: orders-api · http_code: 503 · error: connect refused for ", $text);
        self::assertStringNotContainsString('jane@example.com', $text);
        self::assertStringNotContainsString('user_email', $text);
        self::assertStringNotContainsString('abcdef1234567890', $text);
    }

    public function testFlattenedNestedContextAndListsAreRenderedAsOneField(): void
    {
        $sent = self::deliverAll(
            self::alerts(self::healthRule(Level::Critical, null)),
            self::state(),
            self::event('health check: services down', Level::Critical, [
                'context.services.0' => 'orders-api',
                'context.services.1' => 'payments',
                'context.service' => 'orders-api',
            ]),
        );

        self::assertStringContainsString("service: orders-api · services: orders-api, payments\n", $sent[0]->text);
    }

    public function testTheParsedLoggerLineKeepsWhatTheAlertNeeds(): void
    {
        $line = '{"level":"critical","action":"health.check","message":"health check failed","context":{"service":"orders-api","http_code":503}}';
        $event = (new LineParser())->parse($line);
        $alerts = self::alerts(['health-check' => ['level' => Level::Critical, 'message' => 'health check failed', 'action' => 'health.check']]);
        $alerts->observe($event);
        $sent = [];
        $alerts->deliver(self::state(), 'm-seq', self::WINDOW, static function (Message $m) use (&$sent): void {
            $sent[] = $m;
        }, static fn (\Throwable $e) => throw $e);

        self::assertCount(1, $sent);
        self::assertStringContainsString('service: orders-api · http_code: 503', $sent[0]->text);
    }

    public function testEnvironmentAndUtcTimeFollowTheHead(): void
    {
        $sent = self::deliverAll(self::alerts(self::healthRule()), self::state(), self::event('health check failed'));

        self::assertSame("🚨 ALERT [critical] m-seq: health check failed\nprod · 2026-10-29 10:00:00 UTC", $sent[0]->text);
    }

    public function testAWindowSuppressesARepeatButNotAnotherService(): void
    {
        $state = self::state();
        $first = self::deliverAll(self::alerts(self::healthRule()), $state, self::event('health check failed', Level::Critical, ['service' => 'orders-api']));
        $repeat = self::deliverAll(self::alerts(self::healthRule()), $state, self::event('health check failed', Level::Critical, ['service' => 'orders-api']));
        $other = self::deliverAll(self::alerts(self::healthRule()), $state, self::event('health check failed', Level::Critical, ['service' => 'payments']));

        self::assertCount(1, $first);
        self::assertCount(0, $repeat);
        self::assertCount(1, $other);
    }

    public function testAFailedSendIsReportedAndTheOtherAlertsStillGo(): void
    {
        $alerts = self::alerts(self::healthRule());
        $alerts->observe(self::event('health check failed', Level::Critical, ['service' => 'a']));
        $alerts->observe(self::event('health check failed', Level::Critical, ['service' => 'b']));
        $calls = 0;
        $delivered = 0;
        $failures = [];
        $alerts->deliver(self::state(), 'm-seq', self::WINDOW, function () use (&$calls, &$delivered): void {
            if (++$calls === 1) {
                throw new \RuntimeException('webhook returned 500');
            }
            $delivered++;
        }, function (\Throwable $e) use (&$failures): void {
            $failures[] = $e->getMessage();
        });

        self::assertSame(['webhook returned 500'], $failures);
        self::assertSame(1, $delivered);
    }

    public function testAStateFaultIsReportedAndNeverEscapes(): void
    {
        $broken = new class () implements StateStore {
            public function get(string $key): ?array
            {
                throw new \RuntimeException('state unreachable');
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
                return $by;
            }
        };
        $alerts = self::alerts(self::healthRule());
        $alerts->observe(self::event('health check failed'));
        $failures = [];
        $alerts->deliver($broken, 'm-seq', self::WINDOW, static fn () => self::fail('nothing may be sent when the window cannot be read'), function (\Throwable $e) use (&$failures): void {
            $failures[] = $e->getMessage();
        });

        self::assertSame(['state unreachable'], $failures);
    }

    public function testNoMatchSendsNothing(): void
    {
        self::assertSame([], self::deliverAll(self::alerts(self::healthRule()), self::state(), self::event('all good', Level::Info)));
    }
}
