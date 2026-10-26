<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

/**
 * @param list<Observation> $observations
 * @param list<string>      $unattributed keys of events whose object is gone and whose workload matches no selected one
 * @param int               $dropped      events dropped as unreadable (no time, bad shape, masking failure)
 */
final class MappedRead
{
    /**
     * @param list<Observation> $observations
     * @param list<string> $unattributed
     */
    public function __construct(public readonly array $observations, public readonly array $unattributed, public readonly int $dropped)
    {
    }
}
