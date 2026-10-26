<?php

declare(strict_types=1);

namespace Watcher\Operate;

use LogicException;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;
use Watcher\State\StateStore;

/**
 * Collaborators that refuse every call. Used when the master switch (or fleet switch) is off so a
 * short-circuit path never builds a real source, state store or notifier.
 */
final class RefusedAccess
{
    public const MSG = 'watcher is disabled (enabled=false)';

    public static function source(): LogSource
    {
        return new class () implements LogSource {
            public function totals(Window $window, StreamSelector $selector): array
            {
                throw new LogicException(RefusedAccess::MSG);
            }

            public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): iterable
            {
                throw new LogicException(RefusedAccess::MSG);
            }

            public function lastReadTruncated(): bool
            {
                return false;
            }

            public function versions(Window $window, StreamSelector $selector, string $label): array
            {
                throw new LogicException(RefusedAccess::MSG);
            }
        };
    }

    public static function state(): StateStore
    {
        return new class () implements StateStore {
            public function get(string $key): ?array
            {
                throw new LogicException(RefusedAccess::MSG);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                throw new LogicException(RefusedAccess::MSG);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                throw new LogicException(RefusedAccess::MSG);
            }

            public function release(string $key, string $owner): void
            {
                throw new LogicException(RefusedAccess::MSG);
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                throw new LogicException(RefusedAccess::MSG);
            }
        };
    }

    public static function notifier(): Notifier
    {
        return new class () implements Notifier {
            public function send(Message $message, Destination $to): void
            {
                throw new LogicException(RefusedAccess::MSG);
            }
        };
    }

    /** @return never */
    public static function refuse(): never
    {
        throw new LogicException(self::MSG);
    }
}
