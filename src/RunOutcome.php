<?php

declare(strict_types=1);

namespace Watcher;

enum RunOutcome: string
{
    case SkippedNotDue = 'skipped_not_due';
    case SkippedLocked = 'skipped_locked';
    case SkippedDone = 'skipped_done';
    case Baselined = 'baselined';
    case Quiet = 'quiet';
    case Reported = 'reported';
    case Failed = 'failed';
    case InvalidConfig = 'invalid_config';
    /** `enabled` is false: the run touched nothing (spec 012). */
    case Disabled = 'disabled';
    case Capped = 'capped';
}
