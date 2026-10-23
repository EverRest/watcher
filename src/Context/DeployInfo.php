<?php

declare(strict_types=1);

namespace Watcher\Context;

use DateTimeImmutable;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;

/** What is deployed during a window (FR-041): configured release/commit, deploy moment, change since the last window. */
final class DeployInfo
{
    public function __construct(
        public readonly ?string $release,
        public readonly ?string $commit,
        public readonly ?DateTimeImmutable $deployedAt,
        public readonly bool $changedSincePrevious,
    ) {
    }

    /**
     * @param array<string, mixed>            $deployConfig release, commit, version_label
     * @param array<string, string|null>|null $previousSnapshotDeploy ['release' => ?, 'commit' => ?] or null when no previous snapshot
     */
    public static function resolve(
        array $deployConfig,
        ?array $previousSnapshotDeploy,
        LogSource $source,
        Window $window,
        StreamSelector $selector,
    ): self {
        $release = self::str($deployConfig['release'] ?? null);
        $commit = self::str($deployConfig['commit'] ?? null);
        $label = self::str($deployConfig['version_label'] ?? null);

        $deployedAt = null;
        if ($label !== null && $release !== null) {
            try {
                $deployedAt = $source->versions($window, $selector, $label)[$release] ?? null;
            } catch (SourceUnavailable) {
                $deployedAt = null; // context only: a failing version query must not fail the run
            }
        }

        $previous = self::str($previousSnapshotDeploy['release'] ?? null);

        return new self($release, $commit, $deployedAt, $release !== null && $previous !== null && $previous !== $release);
    }

    /** The release a NEW group first appeared after, or null. */
    public function firstSeenAfterLabel(GroupStatusLabel|string $status): ?string
    {
        $value = $status instanceof GroupStatusLabel ? $status->value : $status;

        return $this->changedSincePrevious && $value === GroupStatusLabel::New->value ? $this->release : null;
    }

    /** @return array{release: ?string, commit: ?string} */
    public function toSnapshot(): array
    {
        return ['release' => $this->release, 'commit' => $this->commit];
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
