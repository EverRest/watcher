<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Issues;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Issues\IssueRecord;
use Watcher\Issues\IssueSource;
use Watcher\Issues\SentryMerge;
use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;
use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FakeIssueSource;

final class SentryMergeTest extends TestCase
{
    private function window(): Window
    {
        return new Window(new DateTimeImmutable('2026-10-07T06:00:00Z'), new DateTimeImmutable('2026-10-08T06:00:00Z'));
    }

    /** @return list<ProblemGroup> */
    private function logGroups(): array
    {
        $t = new DateTimeImmutable('2026-10-08T01:00:00Z');

        return [new ProblemGroup('aaaa', 'Alpha', Level::Error, 'X', [], 10, $t, $t, null, GroupStatus::New)];
    }

    public function testGroupsAreMergedAndSortedByCountThenFingerprint(): void
    {
        $t = new DateTimeImmutable('2026-10-08T01:00:00Z');
        $src = new FakeIssueSource([new IssueRecord('7', 'Boom', '', Level::Error, $t, $t, 25), new IssueRecord('8', 'Bang', '', Level::Warning, $t, $t, 3)]);

        [$groups, $failure, $dropped] = (new SentryMerge($src, 50, 3))->merge($this->window(), $this->logGroups(), new Normalizer());

        self::assertNull($failure);
        self::assertSame(0, $dropped);
        self::assertSame(['sentry:7', 'aaaa', 'sentry:8'], array_map(static fn (ProblemGroup $g): string => $g->fingerprint, $groups));
    }

    public function testAnyFailureReturnsTheLogGroupsUntouchedWithAFixedToken(): void
    {
        $boom = new class () implements IssueSource {
            public function issues(Window $window, int $limit): array
            {
                throw new \RuntimeException('Bearer CANARY-secret https://sentry.io/x');
            }

            public function dropped(): int
            {
                return 0;
            }
        };
        $odd = new class () implements IssueSource {
            public function issues(Window $window, int $limit): array
            {
                throw new SourceUnavailable('GET https://sentry.io/x failed with CANARY');
            }

            public function dropped(): int
            {
                return 0;
            }
        };
        foreach ([[$boom, 'bad_response'], [$odd, 'unreachable'], [FakeIssueSource::failing('window_too_old'), 'window_too_old']] as [$src, $token]) {
            [$groups, $failure] = (new SentryMerge($src, 50, 3))->merge($this->window(), $this->logGroups(), new Normalizer());

            self::assertSame($token, $failure);
            self::assertEquals($this->logGroups(), $groups);
        }
    }
}
