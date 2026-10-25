<?php

declare(strict_types=1);

namespace Watcher\Issues;

use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;

/** Optional, read-only issue tracker (spec 009). Failure messages: unreachable, unauthorized, timeout, bad_response, rate_limited, too_large, window_too_old. */
interface IssueSource
{
    /**
     * @return list<IssueRecord> at most $limit, unresolved only, allowlisted fields only
     *
     * @throws SourceUnavailable
     */
    public function issues(Window $window, int $limit): array;
}
