<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Source\Kubernetes\KubeSettings;
use Watcher\Source\Kubernetes\ReasonLevels;

/** Spec 008 FR-006: the level is decided in code from event type and reason, by a documented, configurable table. */
final class ReasonLevelsTest extends TestCase
{
    /** @return array<string, array{string, string, int, bool, Level}> type, reason, occurrences, pod restarted, level */
    public static function table(): array
    {
        return [
            'normal is info' => ['Normal', 'Pulled', 1, false, Level::Info],
            'normal killing stays info' => ['Normal', 'Killing', 40, true, Level::Info],
            'normal with a critical reason name stays info' => ['Normal', 'BackOff', 9, false, Level::Info],
            'warning unknown' => ['Warning', 'FailedCreate', 1, false, Level::Warning],
            'hpa metric is a warning by default' => ['Warning', 'FailedGetResourceMetric', 30, false, Level::Warning],
            'oom killed' => ['Warning', 'OOMKilled', 1, false, Level::Error],
            'back off' => ['Warning', 'BackOff', 1, false, Level::Error],
            'crash loop' => ['Warning', 'CrashLoopBackOff', 1, false, Level::Error],
            'image pull backoff' => ['Warning', 'ImagePullBackOff', 1, false, Level::Error],
            'err image pull' => ['Warning', 'ErrImagePull', 1, false, Level::Error],
            'failed' => ['Warning', 'Failed', 1, false, Level::Error],
            'evicted' => ['Warning', 'Evicted', 1, false, Level::Error],
            'failed mount' => ['Warning', 'FailedMount', 1, false, Level::Error],
            'failed scheduling' => ['Warning', 'FailedScheduling', 1, false, Level::Error],
            'probe once' => ['Warning', 'Unhealthy', 1, false, Level::Warning],
            'probe below threshold' => ['Warning', 'Unhealthy', 2, false, Level::Warning],
            'probe at threshold' => ['Warning', 'Unhealthy', 3, false, Level::Error],
            'probe once with a restart' => ['Warning', 'Unhealthy', 1, true, Level::Error],
            'restart signal is a warning' => ['Warning', 'ContainerRestarted', 9, true, Level::Warning],
            'unknown type is info' => ['', 'Whatever', 1, false, Level::Info],
        ];
    }

    #[DataProvider('table')]
    public function testDefaultTable(string $type, string $reason, int $occurrences, bool $restarted, Level $expected): void
    {
        $levels = new ReasonLevels(KubeSettings::DEFAULT_CRITICAL, 3);

        self::assertSame($expected, $levels->level($type, $reason, $occurrences, $restarted));
    }

    public function testTheListIsConfigurable(): void
    {
        $levels = new ReasonLevels(['FailedGetResourceMetric'], 5);

        self::assertSame(Level::Error, $levels->level('Warning', 'FailedGetResourceMetric', 1, false));
        self::assertSame(Level::Warning, $levels->level('Warning', 'OOMKilled', 1, false));
        self::assertSame(Level::Warning, $levels->level('Warning', 'Unhealthy', 4, false));
        self::assertSame(Level::Error, $levels->level('Warning', 'Unhealthy', 5, false));
    }
}
