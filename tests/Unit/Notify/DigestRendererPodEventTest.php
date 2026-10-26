<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Level;
use Watcher\Notify\DigestData;
use Watcher\Notify\DigestRenderer;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

/** Spec 008 US1-5: an event group is marked as a pod event and shows reason, count, distinct pods and workload kind only. */
final class DigestRendererPodEventTest extends TestCase
{
    /** @param list<string> $lines */
    private function render(?string $origin, array $lines = []): string
    {
        $fp = substr(hash('sha256', 'x'), 0, 16);
        $t = new DateTimeImmutable('2026-10-23T01:00:00Z');
        $group = new RedactedGroup($fp, 'OOMKilled: container was killed', Level::Error, 'OOMKilled', [], 14, null, GroupStatusLabel::New, [], $t, $t, $origin, 3, 'Deployment');
        $finding = new Finding([$fp], FindingKind::Bug, Severity::Error, 'Containers run out of memory', 'e', 'c', 'a', 0.9);
        $data = new DigestData('svc', 'prod', $t, $t->modify('+1 day'), 'UTC', [$finding], [$fp => $group], podEventLines: $lines);
        $message = (new DigestRenderer())->render($data, 0.5);

        return implode("\n", array_map(static fn (array $s): string => $s['text'], $message->sections));
    }

    public function testAPodEventFindingShowsReasonPodsAndWorkloadKind(): void
    {
        $text = $this->render('k8s-events');

        self::assertStringContainsString('· pod event: OOMKilled · 3 pods · Deployment', $text);
    }

    public function testALogFindingIsUnchanged(): void
    {
        self::assertStringNotContainsString('pod event', $this->render(null));
    }

    public function testASinglePodIsSingular(): void
    {
        $fp = substr(hash('sha256', 'y'), 0, 16);
        $t = new DateTimeImmutable('2026-10-23T01:00:00Z');
        $group = new RedactedGroup($fp, 't', Level::Error, 'BackOff', [], 4, null, GroupStatusLabel::New, [], $t, $t, 'k8s-events', 1, 'StatefulSet');
        $finding = new Finding([$fp], FindingKind::Bug, Severity::Error, 'x', 'e', 'c', 'a', 0.9);
        $text = implode("\n", array_map(static fn (array $s): string => $s['text'], (new DigestRenderer())->render(new DigestData('svc', 'prod', $t, $t->modify('+1 day'), 'UTC', [$finding], [$fp => $group]), 0.5)->sections));

        self::assertStringContainsString('1 pod · StatefulSet', $text);
    }

    public function testNoPodCountIsShownWhenThereAreNoPods(): void
    {
        $fp = substr(hash('sha256', 'z'), 0, 16);
        $t = new DateTimeImmutable('2026-10-23T01:00:00Z');
        $group = new RedactedGroup($fp, 't', Level::Warning, 'FailedGetResourceMetric', [], 6, null, GroupStatusLabel::New, [], $t, $t, 'k8s-events', 0, 'HorizontalPodAutoscaler');
        $finding = new Finding([$fp], FindingKind::Bug, Severity::Warn, 'x', 'e', 'c', 'a', 0.9);
        $text = implode("\n", array_map(static fn (array $s): string => $s['text'], (new DigestRenderer())->render(new DigestData('svc', 'prod', $t, $t->modify('+1 day'), 'UTC', [$finding], [$fp => $group]), 0.5)->sections));

        self::assertStringContainsString('pod event: FailedGetResourceMetric · HorizontalPodAutoscaler', $text);
        self::assertStringNotContainsString('0 pods', $text);
    }

    public function testPodEventLinesAreShownAsNoticesAndEscaped(): void
    {
        $text = $this->render('k8s-events', ['⚠️ Pod events were not read: unauthorised.', 'ℹ️ Pod events: <!channel> gap']);

        self::assertStringContainsString('Pod events were not read: unauthorised.', $text);
        self::assertStringNotContainsString('<!channel>', $text);
    }
}
