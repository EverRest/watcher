<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use Watcher\Level;

/** One normalised entry from a source. Lives only inside a run; `fields` never leaves the service. */
final class LogEvent
{
    public const MAX_FRAMES = 5;
    public const ORIGIN_KUBERNETES = 'k8s-events';
    /** The only workload kinds that may reach a digest or a model payload (a closed list, never free text). */
    public const WORKLOAD_KINDS = ['Pod', 'Deployment', 'ReplicaSet', 'StatefulSet', 'DaemonSet', 'Job', 'CronJob', 'HorizontalPodAutoscaler', 'Node', 'Service', 'PersistentVolume', 'PersistentVolumeClaim'];

    /** @var list<string> */
    public readonly array $frames;

    /** Set only by the Kubernetes source (never read from `labels`, which a log stream controls); null for every log line. */
    public readonly ?string $origin;

    public readonly ?string $workloadKind;

    /**
     * @param list<string>                 $frames
     * @param array<string, scalar|null>   $fields
     * @param array<string, string>        $labels
     * @param int                          $occurrences how many identical occurrences this entry stands for (a Kubernetes event with count 14)
     * @param string|null                  $podId       one-way id of the pod, only to count distinct pods
     * @param string|null                  $origin      ORIGIN_KUBERNETES for a pod event; anything else is dropped
     * @param string|null                  $workloadKind one of WORKLOAD_KINDS, otherwise dropped
     */
    public function __construct(
        public readonly DateTimeImmutable $time,
        public readonly Level $level,
        public readonly string $message,
        public readonly ?string $errorType,
        array $frames,
        public readonly array $fields,
        public readonly array $labels,
        public readonly int $occurrences = 1,
        public readonly ?string $podId = null,
        ?string $origin = null,
        ?string $workloadKind = null,
    ) {
        $this->origin = $origin === self::ORIGIN_KUBERNETES ? $origin : null;
        $this->workloadKind = $this->origin !== null && in_array($workloadKind, self::WORKLOAD_KINDS, true) ? $workloadKind : null;
        $this->frames = array_slice($frames, 0, self::MAX_FRAMES);
    }
}
