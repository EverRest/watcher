<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use PHPUnit\Framework\TestCase;
use Watcher\Reduce\Normalizer;
use Watcher\Source\Kubernetes\EventMapper;
use Watcher\Source\Kubernetes\EventMasker;
use Watcher\Source\Kubernetes\KubeSettings;
use Watcher\Source\Kubernetes\MappedRead;

final class EventMapperTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../Fixtures/kubernetes/';

    /** @return list<array<string, mixed>> */
    private static function items(string ...$files): array
    {
        $out = [];
        foreach ($files as $f) {
            /** @var array{items: list<array<string, mixed>>} $decoded */
            $decoded = json_decode((string) file_get_contents(self::DIR . $f), true, flags: JSON_THROW_ON_ERROR);
            $out = [...$out, ...$decoded['items']];
        }

        return $out;
    }

    /**
     * @param list<mixed> $events
     * @param list<array<string, mixed>> $pods
     * @param array<string, mixed> $s
     */
    private function map(array $events, array $pods, array $s = []): MappedRead
    {
        $settings = KubeSettings::fromArray(['namespaces' => ['shop']] + $s, true, true, false);
        $masker = new EventMasker($settings->namespaces, $settings->allowRegistries, $settings->allowImages);

        return (new EventMapper($settings, $masker, new Normalizer(), 'svc'))->map($events, $pods, new \DateTimeImmutable('2026-10-24T06:00:00Z'));
    }

    /** @return list<\Watcher\Source\Kubernetes\Observation> */
    private static function reason(MappedRead $r, string $reason): array
    {
        return array_values(array_filter($r->observations, static fn ($o): bool => $o->reason === $reason));
    }

    public function testEightCrashLoopingPodsShareOneTextAndHaveEightPodHashes(): void
    {
        $r = $this->map(self::items('events-core.json'), self::items('pods.json', 'pods-page2.json'));

        $backoff = array_filter(self::reason($r, 'BackOff'), static fn ($o): bool => $o->kind === 'Pod' && $o->workload === 'api' && $o->type === 'Warning');
        self::assertCount(8, $backoff);
        self::assertCount(1, array_unique(array_map(static fn ($o): string => $o->text, $backoff)));
        self::assertCount(8, array_unique(array_map(static fn ($o): ?string => $o->podHash, $backoff)));
        $first = reset($backoff);
        self::assertNotFalse($first);
        self::assertSame('Deployment', $first->workloadKind);
        self::assertStringNotContainsString('x2k4q', $first->text);
    }

    public function testCountsAndTimesComeFromCoreAndSeriesForms(): void
    {
        $core = $this->map(self::items('events-core.json'), []);
        $probe = array_values(array_filter($core->observations, static fn ($o): bool => $o->reason === 'Unhealthy' && $o->count === 40));
        self::assertCount(1, $probe);
        self::assertSame('2026-10-24T05:30:00+00:00', $probe[0]->last->format(DATE_ATOM));
        self::assertSame('2026-10-24T04:00:00+00:00', $probe[0]->first->format(DATE_ATOM));

        $v1 = $this->map(self::items('events-v1.json'), []);
        $b = self::reason($v1, 'BackOff');
        self::assertSame([21, 9], array_map(static fn ($o): int => $o->count, $b));
        self::assertSame('2026-10-24T05:40:00+00:00', $b[0]->last->format(DATE_ATOM));
    }

    public function testInvolvedKindsAndNamespacesAreFilteredInCode(): void
    {
        $r = $this->map(self::items('events-core.json'), []);

        self::assertSame([], array_filter($r->observations, static fn ($o): bool => $o->kind === 'Node'), 'node events are off by default');
        self::assertSame([], array_filter($r->observations, static fn ($o): bool => $o->workload === 'ledger'), 'the billing namespace is not configured');
        self::assertNotEmpty(self::reason($r, 'FailedGetResourceMetric'));

        $withNodes = $this->map(self::items('events-core.json'), [], ['involved_kinds' => ['Node', 'Pod']]);
        self::assertCount(1, self::reason($withNodes, 'OOMKilling'));
        self::assertSame([], self::reason($withNodes, 'FailedGetResourceMetric'), 'HPA is not in the kinds');
    }

    public function testPodStatusSignals(): void
    {
        $r = $this->map([], self::items('pods.json', 'pods-page2.json'));

        $oom = self::reason($r, 'OOMKilled');
        self::assertCount(2, $oom);
        self::assertSame('Warning', $oom[0]->type);
        self::assertSame(1, $oom[0]->count);
        self::assertSame('2026-10-24T05:20:30+00:00', $oom[0]->last->format(DATE_ATOM));
        self::assertSame('api', $oom[0]->workload);

        $restarts = self::reason($r, 'ContainerRestarted');
        // pods 3-5 restarted but report no time (waiting after a kubelet restart): skipped, not stamped with the read time
        self::assertSame([14, 15, 1], array_map(static fn ($o): int => $o->count, $restarts));
        self::assertTrue($restarts[0]->restarted);
        self::assertNotSame($oom[0]->key, $restarts[0]->key);
    }

    public function testOwnerReferencesNameTheWorkload(): void
    {
        $r = $this->map([], self::items('pods.json', 'pods-page2.json'));
        $byWorkload = [];
        foreach (self::reason($r, 'ContainerRestarted') as $o) {
            $byWorkload[$o->workloadKind . ':' . $o->workload] = true;
        }

        self::assertSame(['Deployment:api', 'CronJob:cron'], array_keys($byWorkload));
    }

    public function testSelectorSelectsPodsAndTheirWorkloadsOnly(): void
    {
        $r = $this->map(self::items('events-core.json'), self::items('pods.json', 'pods-page2.json'), ['label_selector' => 'app=api']);

        $workloads = array_unique(array_map(static fn ($o): string => $o->workload, $r->observations));
        sort($workloads);
        self::assertSame(['api'], $workloads, 'worker, batch and the checkout deployment belong to other services');
        self::assertNotEmpty(self::reason($r, 'FailedGetResourceMetric'), 'the HPA named like a selected workload is attributed');
        // the evicted batch pod is gone from the pod list and matches no selected workload: counted, never reported
        self::assertSame(1, count($r->unattributed));
    }

    public function testWithoutASelectorEverythingInTheNamespaceIsMine(): void
    {
        $r = $this->map(self::items('events-core.json'), self::items('pods.json', 'pods-page2.json'));
        $workloads = array_unique(array_map(static fn ($o): string => $o->workload, $r->observations));
        sort($workloads);

        self::assertContains('worker', $workloads);
        self::assertContains('checkout', $workloads);
        self::assertContains('batch', $workloads, 'a pod missing from the list falls back to its stripped name');
        self::assertSame([], $r->unattributed);
    }

    public function testNothingPersonalOrUniqueReachesAnObservation(): void
    {
        $r = $this->map(self::items('events-core.json', 'events-v1.json'), self::items('pods.json', 'pods-page2.json'));
        $seeded = require self::DIR . 'seeded-values.php';
        $dump = json_encode($r->observations, JSON_THROW_ON_ERROR) . serialize($r->observations);

        self::assertNotEmpty($r->observations);
        foreach ([...$seeded['values'], ...$seeded['podSuffixes']] as $value) {
            self::assertStringNotContainsString($value, $dump, "seeded value leaked: $value");
        }
    }

    public function testAnEventWithoutATimeOrWithGarbageIsDroppedAndCounted(): void
    {
        $bad = [
            ['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'x'], 'reason' => 'BackOff', 'type' => 'Warning', 'message' => 'm'],
            ['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'x'], 'reason' => 'Bad Reason!', 'type' => 'Warning', 'message' => 'm', 'lastTimestamp' => '2026-10-24T05:00:00Z'],
            ['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'x'], 'reason' => 'BackOff', 'type' => 'Warning', 'message' => "bad \xB1 utf8", 'lastTimestamp' => '2026-10-24T05:00:00Z'],
            'not an array',
        ];
        $r = $this->map($bad, []);

        self::assertSame([], $r->observations);
        self::assertSame(4, $r->dropped);
    }

    public function testTwoClusterEventObjectsAreTwoEntriesEvenWithTheSameMaskedText(): void
    {
        $mk = static fn (string $name, string $msg, int $count, string $last): array => ['metadata' => ['namespace' => 'shop', 'name' => $name], 'involvedObject' => ['kind' => 'Pod', 'name' => 'api-7d9f8c6b5-x2k4q'],
            'reason' => 'Unhealthy', 'type' => 'Warning', 'message' => $msg, 'count' => $count, 'lastTimestamp' => $last];
        $r = $this->map([$mk('e1', 'probe failed: statuscode: 503', 10, '2026-10-24T05:50:00Z'), $mk('e2', 'probe failed: statuscode: 500', 3, '2026-10-24T05:40:00Z')], []);

        self::assertCount(2, $r->observations);
        self::assertNotSame($r->observations[0]->key, $r->observations[1]->key);
        self::assertSame([10, 3], array_map(static fn ($o): int => $o->count, $r->observations));
    }

    public function testARestartSignalWithoutAKnownTimeIsSkippedNotStampedWithNow(): void
    {
        $pod = ['metadata' => ['name' => 'api-7d9f8c6b5-x2k4q', 'namespace' => 'shop'], 'status' => ['containerStatuses' => [['name' => 'api', 'restartCount' => 4, 'state' => ['waiting' => ['reason' => 'CrashLoopBackOff']]]]]];

        self::assertSame([], $this->map([], [$pod])->observations);
    }

    public function testAGoneDaemonSetPodIsAttributedToItsOwnWorkload(): void
    {
        $live = ['metadata' => ['name' => 'node-exporter-fghij', 'namespace' => 'shop', 'labels' => ['app' => 'node-exporter'], 'ownerReferences' => [['kind' => 'DaemonSet', 'name' => 'node-exporter', 'controller' => true]]]];
        $gone = [['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'node-exporter-abcde'], 'reason' => 'Killing', 'type' => 'Warning', 'message' => 'Stopping container', 'count' => 1, 'lastTimestamp' => '2026-10-24T05:50:00Z']];
        $r = $this->map($gone, [$live], ['label_selector' => 'app=node-exporter']);

        self::assertSame([], $r->unattributed);
        self::assertSame('node-exporter', $r->observations[0]->workload);
    }

    public function testTwoNamelessEventsWithTheSameKeyAreStillSummed(): void
    {
        $mk = static fn (string $msg, int $count, string $last): array => ['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'api-7d9f8c6b5-x2k4q'],
            'reason' => 'Unhealthy', 'type' => 'Warning', 'message' => $msg, 'count' => $count, 'lastTimestamp' => $last];
        // "503" and "500" both become <N>: one key, two cluster events
        $r = $this->map([$mk('Readiness probe failed: statuscode: 503', 10, '2026-10-24T05:50:00Z'), $mk('Readiness probe failed: statuscode: 500', 3, '2026-10-24T05:40:00Z')], []);

        self::assertCount(1, $r->observations);
        self::assertSame(13, $r->observations[0]->count);
        self::assertSame('2026-10-24T05:50:00+00:00', $r->observations[0]->last->format(DATE_ATOM));
    }

    public function testANodeNamedByTheSchedulerIsMaskedEvenWhenItsPodIsGone(): void
    {
        $e = [['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'api-7d9f8c6b5-x2k4q'], 'reason' => 'Scheduled', 'type' => 'Normal',
            'message' => 'Successfully assigned shop/api-7d9f8c6b5-x2k4q to worker-alpha', 'count' => 1, 'lastTimestamp' => '2026-10-24T05:50:00Z']];
        $r = $this->map($e, []);

        self::assertStringNotContainsString('worker-alpha', $r->observations[0]->text);
        self::assertStringNotContainsString('x2k4q', $r->observations[0]->text);
    }

    public function testEventsOfAnotherServicesGonePodAreNotCountedAsUnattributed(): void
    {
        $pods = self::items('pods.json', 'pods-page2.json'); // includes the worker pod, which the selector below does not select
        $gone = [['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'worker-5c8d9f7b4-zzzzz'], 'reason' => 'Killing', 'type' => 'Warning',
            'message' => 'Stopping container worker', 'count' => 1, 'lastTimestamp' => '2026-10-24T05:50:00Z']];
        $r = $this->map($gone, $pods, ['label_selector' => 'app=api']);

        self::assertSame([], $r->unattributed);
        self::assertSame([], array_filter($r->observations, static fn ($o): bool => $o->workload === 'worker'));
    }

    public function testTextIsBounded(): void
    {
        $long = [['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'x'], 'reason' => 'Failed', 'type' => 'Warning',
            'message' => str_repeat('connection refused while talking to a thing ', 80), 'lastTimestamp' => '2026-10-24T05:00:00Z', 'count' => 1]];

        self::assertLessThanOrEqual(300, strlen($this->map($long, [])->observations[0]->text));
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>} 5000 live pods and 10000 events of them */
    private static function bigCluster(int $pods, int $events): array
    {
        $podItems = [];
        $names = [];
        for ($i = 0; $i < $pods; ++$i) {
            $name = 'svc' . ($i % 50) . '-' . substr(md5('h' . $i), 0, 9) . '-' . substr(md5('p' . $i), 0, 5);
            $names[] = $name;
            $podItems[] = ['metadata' => ['name' => $name, 'namespace' => 'shop', 'labels' => ['app' => 'svc' . ($i % 50)], 'ownerReferences' => [['kind' => 'ReplicaSet', 'name' => 'svc' . ($i % 50) . '-' . substr(md5('h' . $i), 0, 9), 'controller' => true]], 'creationTimestamp' => '2026-10-24T04:00:00Z'], 'spec' => ['nodeName' => 'node-' . ($i % 40)], 'status' => ['containerStatuses' => []]];
        }
        $eventItems = [];
        for ($i = 0; $i < $events; ++$i) {
            $name = $names[$i % $pods];
            $eventItems[] = ['metadata' => ['name' => "e$i", 'namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => $name, 'namespace' => 'shop'], 'reason' => 'BackOff', 'type' => 'Warning',
                'message' => "Back-off restarting failed container svc in pod {$name}_shop(uid) on node-" . ($i % 40), 'count' => 3, 'lastTimestamp' => '2026-10-24T05:00:00Z', 'firstTimestamp' => '2026-10-24T04:30:00Z'];
        }

        return [$podItems, $eventItems];
    }

    /** @group perf */
    public function testFiveThousandPodsAndTenThousandEventsAreMappedInSeconds(): void
    {
        [$pods, $events] = self::bigCluster(5000, 10000);
        $started = microtime(true);

        $r = $this->map($events, $pods, ['namespaces' => ['shop']]);

        self::assertLessThan(8.0, microtime(true) - $started, 'the pod-name matcher is built once per read, not once per event');
        self::assertNotEmpty($r->observations);
        $text = json_encode(array_map(static fn ($o): string => $o->text, $r->observations), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(substr(md5('p17'), 0, 5), $text, 'every pod name was still masked');
    }

    public function testTheMappingStopsWhenTheRunGuardNamesAReason(): void
    {
        [$pods, $events] = self::bigCluster(50, 2000);
        $settings = KubeSettings::fromArray(['namespaces' => ['shop'], 'api' => ['server' => 'https://k8s.example', 'token' => 't']], true, true, false);
        $mapper = new EventMapper($settings, new EventMasker(['shop']), new Normalizer(), 'svc');
        $calls = 0;

        try {
            $mapper->map($events, $pods, new \DateTimeImmutable('2026-10-24T06:00:00Z'), static function () use (&$calls): ?string {
                return ++$calls > 2 ? 'memory' : null;
            });
            self::fail('expected SourceUnavailable');
        } catch (\Watcher\Source\SourceUnavailable $e) {
            self::assertSame('kubernetes memory', $e->getMessage());
        }
        self::assertLessThan(10, $calls, 'checked every few hundred events, not at the end');
    }
}
