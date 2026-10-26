<?php

declare(strict_types=1);

namespace Watcher\Incident;

use DateTimeImmutable;
use Watcher\State\Keys;
use Watcher\State\StateStore;

final class KnownReferences
{
    public function __construct(
        private readonly StateStore $store,
        private readonly string $service,
        private readonly int $retention,
    ) {
    }

    public function get(string $fingerprint): ?int
    {
        $raw = $this->store->get(Keys::known($this->service, $fingerprint));
        if (!is_array($raw) || !isset($raw['ref']) || !is_int($raw['ref'])) {
            return null;
        }

        return $raw['ref'];
    }

    public function touch(string $fingerprint, int $count, DateTimeImmutable $now): int
    {
        $key = Keys::known($this->service, $fingerprint);
        $existing = $this->get($fingerprint);
        $ref = $existing ?? $count;
        $this->store->put($key, ['ref' => $ref, 'seen' => $now->format('Y-m-d')], $this->retention);

        return $ref;
    }
}
