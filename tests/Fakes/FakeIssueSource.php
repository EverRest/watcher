<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Watcher\Issues\IssueRecord;
use Watcher\Issues\IssueSource;
use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;

final class FakeIssueSource implements IssueSource
{
    public int $calls = 0;

    /** @param list<IssueRecord> $records */
    public function __construct(private readonly array $records = [], private readonly ?string $failWith = null)
    {
    }

    public static function failing(string $token): self
    {
        return new self([], $token);
    }

    public function dropped(): int
    {
        return 0;
    }

    public function issues(Window $window, int $limit): array
    {
        ++$this->calls;
        if ($this->failWith !== null) {
            throw new SourceUnavailable($this->failWith);
        }

        return array_slice($this->records, 0, $limit);
    }
}
