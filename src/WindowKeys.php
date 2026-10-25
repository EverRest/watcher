<?php

declare(strict_types=1);

namespace Watcher;

use Watcher\Schedule\Window;
use Watcher\State\Keys;

/**
 * @internal
 *
 * The state keys and lifetimes of one service and one window, so Watcher does not assemble them from parts at every use. */
final class WindowKeys
{
    private readonly string $end;

    public function __construct(
        private readonly string $stateKey,
        private readonly Window $window,
        private readonly int $retention,
        private readonly int $lockTtlSeconds,
    ) {
        $this->end = Watcher::windowKey($window->end);
    }

    /** The window end as it appears in keys. */
    public function end(): string
    {
        return $this->end;
    }

    public function lock(): string
    {
        return Keys::lock($this->stateKey, $this->end);
    }

    public function lockTtl(): int
    {
        return Keys::ttl(Keys::LOCK, $this->retention, $this->lockTtlSeconds);
    }

    public function run(): string
    {
        return Keys::run($this->stateKey, $this->end);
    }

    /** The snapshot the previous window would have written. */
    public function previousSnapshot(): string
    {
        return Keys::snapshot($this->stateKey, Watcher::windowKey($this->window->start));
    }

    public function reported(string $fingerprint): string
    {
        return Keys::reported($this->stateKey, $fingerprint);
    }

    /** Marker that this window's digest reached the channel. */
    public function delivered(): string
    {
        return Keys::delivered($this->stateKey, $this->end);
    }

    public function deliveredParts(): string
    {
        return Keys::deliveredParts($this->stateKey, $this->end);
    }

    /** Failed analysis attempts of this window. */
    public function analysisFailures(): string
    {
        return Keys::analysisFailures($this->stateKey, $this->end);
    }

    /** Marks a window cut by the deadline or memory guard and retried once. */
    public function partial(): string
    {
        return Keys::partial($this->stateKey, $this->end);
    }

    /** Failure notices sent for this window. */
    public function failNotices(): string
    {
        return Keys::failNotices($this->stateKey, $this->end);
    }

    public function routeDelivered(int $routeIndex): string
    {
        return Keys::routeDelivered($this->stateKey, $this->end, $routeIndex);
    }

    public function draft(string $fingerprint): string
    {
        return Keys::draft($this->stateKey, $this->end, $fingerprint);
    }
}
