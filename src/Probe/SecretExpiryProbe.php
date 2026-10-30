<?php

declare(strict_types=1);

namespace Watcher\Probe;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Declared secret expiry: date math on `expires` (YYYY-MM-DD, UTC), valid through that whole day. It never reads a secret value,
 * an environment variable or a credential file: the secret's name and owner are all the config holds.
 */
final class SecretExpiryProbe implements Probe
{
    /** @param list<array{name: string, expires: string, owner: string}> $secrets */
    public function __construct(
        private readonly array $secrets,
        private readonly int $warnDays,
        private readonly int $failDays,
        private readonly ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'secret';
    }

    public function check(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($this->secrets as $secret) {
            $endsAt = (new DateTimeImmutable($secret['expires'], $utc))->modify('+1 day')->getTimestamp();
            $out[] = ProbeResult::expiry(
                'secret:' . $secret['name'],
                "secret {$secret['name']} (owner {$secret['owner']})",
                $endsAt - $now,
                $this->warnDays,
                $this->failDays,
            );
        }

        return $out;
    }
}
