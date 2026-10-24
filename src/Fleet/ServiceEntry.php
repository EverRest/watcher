<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Config\WatchConfig;

/** One service of a fleet: its complete single-service config plus the two fleet-only keys. */
final class ServiceEntry
{
    /** @var list<string> names other services use for this one (default: its own name) */
    public readonly array $provides;

    /** @param list<string>|null $provides */
    public function __construct(
        public readonly WatchConfig $config,
        public readonly int $priority = 0,
        ?array $provides = null,
    ) {
        $this->provides = $provides ?? [$config->service];
    }

    public function name(): string
    {
        return $this->config->service;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return array_map('strval', array_keys($this->config->dependencies));
    }
}
