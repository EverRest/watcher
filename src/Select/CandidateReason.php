<?php

declare(strict_types=1);

namespace Watcher\Select;

enum CandidateReason: string
{
    case New = 'new';
    case Grown = 'grown';
    case Level = 'level';
    case WatchRule = 'watch_rule'; // produced only by the watch-rule task (US4)
    case LevelAnomaly = 'level_anomaly';
    case TopVolume = 'top_volume';
    case MetricAnomaly = 'metric_anomaly'; // synthetic group from a metric move no log finding explains (spec 009)
    case KnownGrown = 'known_grown';

    /** Higher is more important; used for budget truncation. */
    public function weight(): int
    {
        return match ($this) {
            self::WatchRule => 6,
            self::Level, self::MetricAnomaly => 5,
            self::New => 4,
            self::KnownGrown => 4,
            self::Grown => 3,
            self::LevelAnomaly => 2,
            self::TopVolume => 1,
        };
    }
}
