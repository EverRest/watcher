<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;

/** One LogSource that answers per `app` stream tag, so a service and its dependencies can share the watcher's single source. */
final class RoutedLogSource implements LogSource
{
    /** @param array<string, FakeLogSource> $byApp app tag => source */
    public function __construct(private readonly array $byApp, private readonly FakeLogSource $default)
    {
    }

    private function pick(StreamSelector $s): FakeLogSource
    {
        return $this->byApp[$s->tags['app'] ?? ''] ?? $this->default;
    }

    public function totals(Window $window, StreamSelector $selector): array
    {
        return $this->pick($selector)->totals($window, $selector);
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): \Generator
    {
        return $this->pick($selector)->lines($window, $selector, $filter);
    }

    public function lastReadTruncated(): bool
    {
        return $this->default->lastReadTruncated();
    }

    public function versions(Window $window, StreamSelector $selector, string $label): array
    {
        return $this->pick($selector)->versions($window, $selector, $label);
    }
}
