<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Analyze\Usage;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Privacy\RedactedGroup;

/**
 * What a Watcher sees of its fleet (spec 003, plan R2): the shared budget and, in combined mode, the digest hold.
 * One instance per fleet run; null in a single-service run.
 */
final class FleetSeam
{
    /** @var array<string, CollectingNotifier> */
    private array $collectors = [];

    public function __construct(
        private readonly FleetBudget $budget,
        private readonly bool $combined = false,
        private readonly ?Correlator $correlator = null,
    ) {
    }

    /** @var array<string, list<RedactedGroup>> service => its candidates after redaction, for the services that ran */
    private array $registry = [];

    /**
     * Seam 2: a service hands over its redacted candidates; the origins registered so far explain some of them.
     * Returns fingerprint => annotation text. Nothing is removed or changed (owner decision 3).
     *
     * @param list<RedactedGroup> $groups
     * @return array<string, string>
     */
    public function reduced(string $service, array $groups): array
    {
        if ($this->correlator === null) {
            return [];
        }
        $this->registry[$service] = $groups;
        $out = [];
        foreach ($this->correlator->links($this->registry) as $l) {
            if ($l->consumerService === $service) {
                $out[$l->consumer->fingerprint] ??= sprintf('likely caused by the %s finding %s (hypothesis)', $l->originService, substr($l->origin->fingerprint, 0, 12));
            }
        }

        return $out;
    }

    /** @return list<CorrelatedFinding> what the services that ran add up to; empty when correlation is off */
    public function correlated(string $fleet): array
    {
        return $this->correlator?->findings($fleet, $this->registry) ?? [];
    }

    /** Called for every paid analyzer call, after the service's own counter. */
    public function book(Usage $usage, float $costUsd): void
    {
        $this->budget->book($usage, $costUsd);
    }

    /** 'fleet' when the fleet cap is spent, else null. (The service's own cap is the Watcher's to name.) */
    public function exhaustedCap(): ?string
    {
        return $this->budget->exhausted() ? 'fleet' : null;
    }

    public function allows(float $worstUsd, int $tokens): bool
    {
        return $this->budget->allows($worstUsd, $tokens);
    }

    /** @return array{usd: float, tokens: int} */
    public function today(): array
    {
        return $this->budget->today();
    }

    /** The notifier a service's Watcher must use: its own, or in combined mode one that holds the digest back. */
    public function notifierFor(string $service, Notifier $own): Notifier
    {
        return $this->combined ? $this->collectors[$service] = new CollectingNotifier($own) : $own;
    }

    /** @return array<string, Message> service => the digest it would have posted, in run order */
    public function digests(): array
    {
        $out = [];
        foreach ($this->collectors as $service => $collector) {
            if ($collector->held() !== []) {
                $out[$service] = $collector->held()[0];
            }
        }

        return $out;
    }
}
