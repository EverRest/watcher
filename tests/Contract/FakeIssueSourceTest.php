<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use Watcher\Issues\IssueRecord;
use Watcher\Issues\IssueSource;
use Watcher\Level;
use Watcher\Tests\Fakes\FakeIssueSource;

final class FakeIssueSourceTest extends IssueSourceContract
{
    protected function makeSourceWithIssues(int $n): IssueSource
    {
        $t = new DateTimeImmutable('2026-10-22T12:00:00Z');
        $records = [];
        for ($i = 1; $i <= $n; ++$i) {
            $records[] = new IssueRecord((string) (1000 + $i), "Issue {$i}", 'App::run', Level::Error, $t, $t, 5);
        }

        return new FakeIssueSource($records);
    }

    protected function makeSourceFailing(int $httpStatus): IssueSource
    {
        return FakeIssueSource::failing($httpStatus === 401 || $httpStatus === 403 ? 'unauthorized' : 'unreachable');
    }

    protected function secret(): string
    {
        return 'tok-s3cret';
    }
}
