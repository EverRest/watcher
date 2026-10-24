<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

/**
 * Decides in code, from names, placeholders, statuses and times, which consumer groups an origin group explains
 * (spec 003 R4). No model is called. It accepts only already-redacted groups (Principle I).
 */
final class Correlator
{
    /** @param list<ServiceEntry> $entries every service of the fleet */
    public function __construct(
        private readonly array $entries,
        private readonly int $toleranceSeconds = 0,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * @param array<string, list<RedactedGroup>> $registry service => its groups after redaction, for services that ran
     * @return list<CorrelationLink> sorted by origin service, origin fingerprint, consumer service, consumer fingerprint
     * @throws \TypeError for anything but a RedactedGroup
     */
    public function links(array $registry): array
    {
        foreach ($registry as $groups) {
            foreach ($groups as $g) {
                if (!$g instanceof RedactedGroup) {
                    throw new \TypeError('Correlator accepts only RedactedGroup');
                }
            }
        }
        if (!$this->enabled) {
            return [];
        }
        ksort($registry);
        $byName = [];
        foreach ($this->entries as $e) {
            $byName[$e->name()] = $e;
        }

        $links = [];
        foreach ($registry as $consumerService => $consumers) {
            foreach (($byName[$consumerService] ?? null)?->dependencies() ?? [] as $dependency) {
                foreach ($registry as $originService => $origins) {
                    $entry = $byName[$originService] ?? null;
                    if ($originService === $consumerService || $entry === null || !in_array($dependency, $entry->provides, true)) {
                        continue;
                    }
                    foreach ($origins as $origin) {
                        foreach ($consumers as $consumer) {
                            if ($this->explains($entry, $origin, $consumer, $dependency)) {
                                $links[$originService . "\0" . $origin->fingerprint . "\0" . $consumerService . "\0" . $consumer->fingerprint] ??= new CorrelationLink($originService, $origin, $consumerService, $consumer, $dependency);
                            }
                        }
                    }
                }
            }
        }
        ksort($links);

        return $this->withoutReversed(array_values($links));
    }

    /**
     * @param array<string, list<RedactedGroup>> $registry
     * @return list<CorrelatedFinding> one per origin group
     */
    public function findings(string $fleet, array $registry): array
    {
        $byOrigin = [];
        foreach ($this->links($registry) as $l) {
            $byOrigin[$l->originService . "\0" . $l->origin->fingerprint][] = $l;
        }

        $out = [];
        foreach ($byOrigin as $links) {
            $first = $links[0];
            /** @var array<string, array{service: string, groups: int, count: int, dependency: string}> $consumers */
            $consumers = [];
            $start = $first->origin->firstSeen;
            $end = $first->origin->lastSeen;
            foreach ($links as $l) {
                $c = $consumers[$l->consumerService] ?? ['service' => $l->consumerService, 'groups' => 0, 'count' => 0, 'dependency' => $l->dependency];
                $consumers[$l->consumerService] = ['service' => $c['service'], 'groups' => $c['groups'] + 1, 'count' => $c['count'] + $l->consumer->count, 'dependency' => $c['dependency']];
                $start = min($start, $l->consumer->firstSeen);
                $end = max($end, $l->consumer->lastSeen);
            }
            ksort($consumers);
            $out[] = new CorrelatedFinding($fleet, $first->originService, $first->origin, array_values($consumers), $start, $end);
        }

        return $out;
    }

    private function explains(ServiceEntry $originEntry, RedactedGroup $origin, RedactedGroup $consumer, string $dependency): bool
    {
        $template = $consumer->template ?? '';

        return $origin->status !== GroupStatusLabel::Unchanged
            && $origin->level->value >= $originEntry->config->selection['min_level']->value
            && (str_contains($template, '<URL:' . $dependency . '>') || str_contains($template, '<HOST:' . $dependency . '>'))
            && $origin->firstSeen->getTimestamp() - $this->toleranceSeconds <= $consumer->lastSeen->getTimestamp()
            && $consumer->firstSeen->getTimestamp() - $this->toleranceSeconds <= $origin->lastSeen->getTimestamp();
    }

    /**
     * Two services that reference each other: when the same two groups explain each other, keep the direction whose origin
     * was seen first; on a tie the service that sorts first is the origin.
     *
     * @param list<CorrelationLink> $links
     * @return list<CorrelationLink>
     */
    private function withoutReversed(array $links): array
    {
        $has = [];
        foreach ($links as $l) {
            $has[$l->originService . "\0" . $l->origin->fingerprint . "\0" . $l->consumerService . "\0" . $l->consumer->fingerprint] = true;
        }

        return array_values(array_filter($links, static function (CorrelationLink $l) use ($has): bool {
            if (!isset($has[$l->consumerService . "\0" . $l->consumer->fingerprint . "\0" . $l->originService . "\0" . $l->origin->fingerprint])) {
                return true;
            }

            return [$l->origin->firstSeen->getTimestamp(), $l->originService] <= [$l->consumer->firstSeen->getTimestamp(), $l->consumerService];
        }));
    }
}
