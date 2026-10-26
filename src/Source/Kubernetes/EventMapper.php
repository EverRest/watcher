<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LogEvent;

/**
 * Decoded events and pods -> Observations (spec 008 FR-005). Reads a CLOSED list of fields, so annotations, labels (other than
 * to evaluate the selector), managedFields, env, args and commands never reach an Observation. core/v1 and events.k8s.io/v1
 * shapes are both read. Attribution to the service follows research R4.
 */
final class EventMapper
{
    public const MAX_TEXT = 300;
    private const NAME = '/^[a-z0-9]([-a-z0-9.]*[a-z0-9])?$/';
    private const REASON = '/^[A-Za-z][A-Za-z0-9]{0,62}$/';

    public function __construct(
        private readonly KubeSettings $settings,
        private readonly EventMasker $masker,
        private readonly Normalizer $normalizer,
        private readonly string $salt,
    ) {
    }

    /**
     * @param array<mixed> $events decoded `items` of the event lists
     * @param array<mixed> $pods   decoded `items` of the pod lists
     * @param (\Closure(): ?string)|null $stop the run guard: why the run must stop (deadline, memory), checked every few hundred events
     * @throws \Watcher\Source\SourceUnavailable when the guard names a reason
     */
    public function map(array $events, array $pods, DateTimeImmutable $now, ?\Closure $stop = null): MappedRead
    {
        $info = [];
        $selected = []; // workload name => kind of the selected pods
        foreach ($pods as $pod) {
            $p = is_array($pod) ? $this->pod($pod) : null;
            if ($p !== null) {
                $info[$p['name']] = $p;
                if ($p['selected']) {
                    $selected[$p['workload']] = $p['workloadKind'];
                }
            }
        }
        $known = []; // every workload with a live pod in the namespaces, selected or not
        foreach ($info as $p) {
            $known[$p['workload']] = true;
        }
        $podNames = array_keys($info);
        $nodes = array_values(array_unique(array_filter(array_column($info, 'node'))));

        $out = [];
        $unattributed = [];
        $dropped = 0;
        $n = 0;
        foreach ($events as $event) {
            if ($stop !== null && (++$n & 255) === 0 && ($why = $stop()) !== null) {
                throw new \Watcher\Source\SourceUnavailable($why === 'memory' ? 'kubernetes memory' : 'kubernetes timeout');
            }
            $o = is_array($event) ? $this->event($event, $info, $selected, $known, $podNames, $nodes) : null;
            if ($o === false || $o === null) {
                ++$dropped;
            } elseif (is_string($o)) {
                $unattributed[] = $o;
            } elseif ($o instanceof Observation) {
                $out[] = $o;
            } // an array means "not mine": silently skipped
        }
        foreach ($info as $p) {
            if ($p['selected']) {
                foreach ($this->signals($p, $now) as $s) {
                    $out[] = $s;
                }
            }
        }

        return new MappedRead($this->coalesce($out), array_values(array_unique($unattributed)), $dropped);
    }

    /**
     * @param array<mixed> $e
     * @param array<string, array<string, mixed>> $info
     * @param array<string, string> $selected
     * @param array<string, true> $known
     * @param list<string> $podNames
     * @param list<string> $nodes
     * @return Observation|string|array<never>|false Observation, the key of an unattributable event, [] for "belongs to someone else", false when unreadable
     */
    private function event(array $e, array $info, array $selected, array $known, array $podNames, array $nodes): Observation|string|array|false
    {
        $meta = is_array($e['metadata'] ?? null) ? $e['metadata'] : [];
        $ns = $meta['namespace'] ?? null;
        $obj = is_array($e['involvedObject'] ?? null) ? $e['involvedObject'] : (is_array($e['regarding'] ?? null) ? $e['regarding'] : []);
        $kind = $obj['kind'] ?? null;
        $name = $obj['name'] ?? null;
        $reason = $e['reason'] ?? null;
        $type = $e['type'] ?? '';
        $message = $e['message'] ?? ($e['note'] ?? '');
        if (!is_string($ns) || !is_string($kind) || !is_string($name) || !is_string($reason) || !is_string($message) || !is_string($type)
            || preg_match(self::REASON, $reason) !== 1 || preg_match(self::NAME, $name) !== 1) {
            return false;
        }
        if (!in_array($ns, $this->settings->namespaces, true) || !in_array($kind, $this->settings->involvedKinds, true)) {
            return []; // outside the configured scope: not an error, not counted
        }
        $last = $this->time($e['series']['lastObservedTime'] ?? null) ?? $this->time($e['lastTimestamp'] ?? null) ?? $this->time($e['eventTime'] ?? null)
            ?? $this->time($e['deprecatedLastTimestamp'] ?? null) ?? $this->time($e['firstTimestamp'] ?? null);
        if ($last === null) {
            return false;
        }
        $first = $this->time($e['firstTimestamp'] ?? null) ?? $this->time($e['deprecatedFirstTimestamp'] ?? null) ?? $last;
        $count = $e['series']['count'] ?? $e['count'] ?? $e['deprecatedCount'] ?? 1;
        $count = is_int($count) && $count >= 1 ? $count : 1;

        $attr = $this->attribute($kind, $name, $info, $selected, $known);
        if ($attr === null) {
            return []; // someone else's object
        }
        $host = $e['source']['host'] ?? $e['deprecatedSource']['host'] ?? $e['reportingInstance'] ?? '';
        $text = $this->text($this->masker->mask($message, $podNames, $nodes, is_string($host) && $host !== '' ? $host : null));
        if ($text === null) {
            return false;
        }
        // One entry per cluster event OBJECT: two objects whose masked text is equal must not share a count.
        $object = is_string($meta['name'] ?? null) ? $meta['name'] : '';
        $key = $this->key($ns, $kind, $name, $reason, $text, $object);
        if ($attr === false) {
            return $key; // object gone and its workload is not a selected one
        }

        return new Observation(
            $key,
            $kind,
            $attr['kind'],
            $attr['workload'],
            $reason,
            $type,
            $text,
            $count,
            $first,
            $last,
            $kind === 'Pod' ? $this->podHash($name) : null,
            ($info[$name]['restarts'] ?? 0) > 0,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $info
     * @param array<string, string> $selected
     * @param array<string, true> $known
     * @return array{kind: string, workload: string}|false|null attributed, unattributable, or someone else's
     */
    private function attribute(string $kind, string $name, array $info, array $selected, array $known): array|false|null
    {
        $all = $this->settings->labelSelector === null;
        if ($kind === 'Pod') {
            if (isset($info[$name])) {
                $p = $info[$name];

                return $p['selected'] ? ['kind' => $p['workloadKind'], 'workload' => $p['workload']] : null;
            }
            $base = EventMasker::podBase($name);
            if ($all) {
                return ['kind' => 'Pod', 'workload' => $base ?? '<POD>'];
            }

            if ($base !== null && isset($selected[$base])) {
                return ['kind' => $selected[$base], 'workload' => $base];
            }

            return $base !== null && isset($known[$base]) ? null : false; // a live workload of another service, or nobody's
        }
        [$workload, $workloadKind] = self::workloadOfObject($kind, $name);
        if ($all || isset($selected[$workload])) {
            return ['kind' => $workloadKind, 'workload' => $workload];
        }

        return null;
    }

    /** @return array{string, string} name, kind of the workload an object of $kind/$name belongs to */
    private static function workloadOfObject(string $kind, string $name): array
    {
        if ($kind === 'ReplicaSet' && preg_match('/^(.+)-[a-z0-9]{5,10}$/', $name, $m) === 1) {
            return [$m[1], 'Deployment'];
        }
        if ($kind === 'Job' && preg_match('/^(.+)-\d{6,}$/', $name, $m) === 1) {
            return [$m[1], 'CronJob'];
        }

        return [$name, $kind];
    }

    /**
     * @param array<mixed> $pod
     * @return array<string, mixed>|null
     */
    private function pod(array $pod): ?array
    {
        $meta = is_array($pod['metadata'] ?? null) ? $pod['metadata'] : [];
        $name = $meta['name'] ?? null;
        $ns = $meta['namespace'] ?? null;
        if (!is_string($name) || preg_match(self::NAME, $name) !== 1 || !is_string($ns) || !in_array($ns, $this->settings->namespaces, true)) {
            return null;
        }
        $labels = [];
        foreach (is_array($meta['labels'] ?? null) ? $meta['labels'] : [] as $k => $v) {
            if (is_string($v)) {
                $labels[(string) $k] = $v; // used only to evaluate the selector, never kept
            }
        }
        $owner = null;
        foreach (is_array($meta['ownerReferences'] ?? null) ? $meta['ownerReferences'] : [] as $ref) {
            if (is_array($ref) && is_string($ref['kind'] ?? null) && is_string($ref['name'] ?? null) && preg_match(self::NAME, $ref['name']) === 1) {
                $owner = $ref;
                if (($ref['controller'] ?? false) === true) {
                    break;
                }
            }
        }
        if ($owner !== null) {
            [$workload, $workloadKind] = self::workloadOfObject($owner['kind'], $owner['name']);
        } else {
            $workload = EventMasker::podBase($name) ?? '<POD>';
            $workloadKind = 'Pod';
        }
        if (!in_array($workloadKind, LogEvent::WORKLOAD_KINDS, true)) {
            $workloadKind = 'Pod';
        }
        $statuses = [];
        $restarts = 0;
        foreach (is_array($pod['status']['containerStatuses'] ?? null) ? $pod['status']['containerStatuses'] : [] as $cs) {
            if (!is_array($cs) || !is_string($cs['name'] ?? null)) {
                continue;
            }
            $terminated = is_array($cs['lastState']['terminated'] ?? null) ? $cs['lastState']['terminated'] : [];
            $running = is_array($cs['state']['running'] ?? null) ? $cs['state']['running'] : [];
            $n = is_int($cs['restartCount'] ?? null) && $cs['restartCount'] > 0 ? $cs['restartCount'] : 0;
            $restarts += $n;
            $statuses[] = [
                'container' => $cs['name'],
                'restarts' => $n,
                'oomAt' => ($terminated['reason'] ?? null) === 'OOMKilled' ? $this->time($terminated['finishedAt'] ?? null) : null,
                'lastAt' => $this->time($terminated['finishedAt'] ?? null) ?? $this->time($running['startedAt'] ?? null),
            ];
        }
        $selector = $this->settings->labelSelector;

        return [
            'name' => $name,
            'ns' => $ns,
            'node' => is_string($pod['spec']['nodeName'] ?? null) ? $pod['spec']['nodeName'] : null,
            'workload' => $workload,
            'workloadKind' => $workloadKind,
            'selected' => $selector === null || $selector->matches($labels),
            'created' => $this->time($meta['creationTimestamp'] ?? null),
            'restarts' => $restarts,
            'statuses' => $statuses,
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return list<Observation>
     */
    private function signals(array $p, DateTimeImmutable $now): array
    {
        $out = [];
        foreach ($p['statuses'] as $s) {
            $base = [$p['ns'], 'Pod', $p['name'], $s['container']];
            if ($s['oomAt'] !== null) {
                $text = 'container was killed because it ran out of memory';
                $out[] = new Observation($this->key(...$base, ...['OOMKilled@' . $s['oomAt']->format('U'), $text]), 'Pod', $p['workloadKind'], $p['workload'], 'OOMKilled', 'Warning', $text, 1, $s['oomAt'], $s['oomAt'], $this->podHash($p['name']), $p['restarts'] > 0);
            }
            if ($s['restarts'] > 0 && $s['lastAt'] !== null) { // no known time: stamping "now" would re-add the count every read
                $at = $s['lastAt'];
                $text = 'container restarted';
                $out[] = new Observation($this->key(...$base, ...['ContainerRestarted', $text]), 'Pod', $p['workloadKind'], $p['workload'], 'ContainerRestarted', 'Warning', $text, $s['restarts'], min($p['created'] ?? $at, $at), $at, $this->podHash($p['name']), true); // first = the pod's creation: restartCount is a lifetime total
            }
        }

        return $out;
    }

    /**
     * Two cluster events can share one masked key (their numbers differ): per read they are one entry, counts summed, so the
     * ledger never sees the stored count swing between them.
     *
     * @param list<Observation> $all
     * @return list<Observation>
     */
    private function coalesce(array $all): array
    {
        $by = [];
        foreach ($all as $o) {
            $p = $by[$o->key] ?? null;
            $by[$o->key] = $p === null ? $o : new Observation(
                $o->key,
                $o->kind,
                $o->workloadKind,
                $o->workload,
                $o->reason,
                $o->type,
                $o->text,
                $p->count + $o->count,
                min($p->first, $o->first),
                max($p->last, $o->last),
                $p->podHash,
                $p->restarted || $o->restarted,
            );
        }

        return array_values($by);
    }

    private function text(string $masked): ?string
    {
        if ($masked === '') {
            return null;
        }
        try {
            $text = $this->normalizer->normalize($masked);
        } catch (Throwable) {
            return null; // fail closed: an event that cannot be scanned is dropped
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT);
            $text = (string) preg_replace('/\S*$/u', '', $text); // never end in a cut token
        }

        return trim($text) === '' ? null : trim($text);
    }

    private function key(string ...$parts): string
    {
        return substr(hash('sha256', implode("\0", $parts)), 0, 16);
    }

    private function podHash(string $pod): string
    {
        return substr(hash('sha256', $this->salt . "\0" . $pod), 0, 8);
    }

    private function time(mixed $v): ?DateTimeImmutable
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
