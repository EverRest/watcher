<?php

declare(strict_types=1);

namespace Watcher\Schedule;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A PHP fatal (memory exhausted, compile error) is not a Throwable, so no catch or finally runs (A4-02). While armed, a
 * shutdown handler releases the run lock and logs one short line to the host logger: the error type name only, never the message.
 */
final class FatalGuard
{
    private const FATAL = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR];

    private bool $armed = false;
    private bool $registered = false;

    /** @param Closure(): void $release */
    public function __construct(private readonly Closure $release, private readonly ?LoggerInterface $log, private readonly string $service)
    {
    }

    public function arm(): void
    {
        $this->armed = true;
        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function(fn () => $this->handle(error_get_last()));
        }
    }

    public function disarm(): void
    {
        $this->armed = false;
    }

    /** @param array{type: int, message: string, file: string, line: int}|null $error */
    public function handle(?array $error): void
    {
        if (!$this->armed || $error === null || !in_array($error['type'], self::FATAL, true)) {
            return;
        }
        $this->armed = false;
        try {
            ($this->release)();
        } catch (Throwable) {
            // the lock expires by TTL
        }
        $this->log?->error('watcher run aborted by a PHP fatal error (' . ($error['type'] === E_ERROR ? 'E_ERROR' : ($error['type'] === E_CORE_ERROR ? 'E_CORE_ERROR' : 'E_COMPILE_ERROR')) . ')', ['service' => $this->service]);
    }
}
