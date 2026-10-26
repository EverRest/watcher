<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/** The reduced spec in the existing StateStore, expiring exactly after the TTL (FR-003, research R4). */
final class ApiSpecCache
{
    public function __construct(
        private readonly StateStore $state,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds,
    ) {
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('a ' . Keys::API . ' cache entry needs a TTL above 0 seconds');
        }
    }

    /** A wrong version or malformed entry is a miss. */
    public function get(string $service, string $sourceId): ?ReducedSpec
    {
        $entry = $this->state->get(Keys::apiContext($service, $sourceId));

        return $entry === null ? null : ReducedSpec::fromCache($entry);
    }

    public function put(string $service, string $sourceId, ReducedSpec $spec): void
    {
        $this->state->put(Keys::apiContext($service, $sourceId), $spec->toCache($this->clock->now()->format('c')), $this->ttlSeconds);
    }
}
