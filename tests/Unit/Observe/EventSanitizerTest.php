<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\TestCase;
use Watcher\Observe\Event;
use Watcher\Observe\EventSanitizer;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;

final class EventSanitizerTest extends TestCase
{
    public function testUnknownKeysAreDropped(): void
    {
        $out = $this->sanitize($this->group(['status' => 'ok', 'template' => 'secret tpl']), false);

        self::assertArrayNotHasKey('status', $out);
        self::assertArrayNotHasKey('template', $out);
        self::assertSame('error', $out['level']);
    }

    public function testAStringFailingItsPatternBecomesNull(): void
    {
        $out = $this->sanitize($this->group(['fingerprint' => 'not-a-fingerprint', 'level' => 'loud', 'first_seen' => 'yesterday']), false);

        self::assertNull($out['fingerprint']);
        self::assertNull($out['level']);
        self::assertNull($out['first_seen']);
    }

    public function testListEntriesAreFilteredAndCapped(): void
    {
        $out = $this->sanitize($this->group(['watch_rules' => ['payments', 'bad rule!', ...array_map(static fn (int $i): string => "r$i", range(1, 20))]]), false);

        self::assertCount(10, $out['watch_rules']);
        self::assertNotContains('bad rule!', $out['watch_rules']);
    }

    public function testNestedCountsKeepOnlyTheirClosedKeys(): void
    {
        $event = new Event('run', $this->envelope('run') + ['findings_by_severity' => ['info' => 1, 'warn' => '2', 'secret' => 5], 'calls' => 'x', 'cost_usd' => NAN], []);

        $out = $this->sanitize($event, false);

        self::assertSame(['info' => 1, 'warn' => 0, 'error' => 0, 'critical' => 0], $out['findings_by_severity']);
        self::assertNull($out['calls']);
        self::assertNull($out['cost_usd']);
    }

    public function testTextIsRemovedWhenIncludeTextIsFalse(): void
    {
        $out = $this->sanitize($this->finding(), false);

        foreach (['title', 'evidence', 'hypothesis', 'next_step', 'check', 'related'] as $key) {
            self::assertArrayNotHasKey($key, $out);
        }
        self::assertSame('critical', $out['severity']);
    }

    public function testTextIsKeptAfterTheScanWhenIncludeTextIsTrue(): void
    {
        $out = $this->sanitize($this->finding(), true);

        self::assertSame('Timeouts calling billing', $out['title']);
        self::assertSame(['billing'], $out['related']);
        self::assertSame(['schema', 'event_type'], array_slice(array_keys($out), 0, 2));
    }

    public function testASeededEmailInTextOrErrorTypeNeverSurvives(): void
    {
        $finding = $this->sanitize($this->finding(['title' => 'Mail to jane.doe@example.com failed', 'evidence' => 'user jane.doe@example.com']), true);
        $group = $this->sanitize($this->group([], ['error_type' => 'App\\jane.doe@example.com']), true);

        self::assertStringNotContainsString('jane.doe@example.com', (string) json_encode([$finding, $group]));
    }

    public function testTextThatCannotBeScannedIsDroppedNeverPassed(): void
    {
        $out = $this->sanitize($this->finding(['title' => "bad \xB1 utf8", 'related' => ["bad \xB1", 'ok']]), true);

        self::assertArrayNotHasKey('title', $out);
        self::assertSame(['ok'], $out['related']);
    }

    public function testLengthCaps(): void
    {
        $out = $this->sanitize($this->finding([
            'title' => str_repeat('word ', 41),
            'evidence' => str_repeat('word ', 81),
            'hypothesis' => str_repeat('word ', 81),
            'next_step' => str_repeat('word ', 81),
            'check' => str_repeat('word ', 81),
            'related' => [str_repeat('word ', 41), 'x', 'y', 'z'],
        ]), true);

        self::assertSame(80, mb_strlen($out['title']));
        self::assertSame(240, mb_strlen($out['evidence']));
        self::assertSame(240, mb_strlen($out['hypothesis']));
        self::assertSame(200, mb_strlen($out['next_step']));
        self::assertSame(200, mb_strlen($out['check']));
        self::assertCount(3, $out['related']);
        self::assertSame(80, mb_strlen($out['related'][0]));
    }

    public function testAFingerprintInRelatedIsKept(): void
    {
        $out = $this->sanitize($this->finding(['related' => ['0123456789ab', 'billing']]), true);

        self::assertSame(['0123456789ab', 'billing'], $out['related']);
    }

    public function testANullErrorTypeStaysNull(): void
    {
        self::assertNull($this->sanitize($this->group([], ['error_type' => null]), true)['error_type']);
    }

    /** @return array<string, mixed> */
    private function sanitize(Event $event, bool $includeText): array
    {
        return (new EventSanitizer(new Redactor(new Normalizer())))->sanitize($event, $includeText);
    }

    /** @return array<string, mixed> */
    private function envelope(string $type): array
    {
        return [
            'schema' => 'watcher.event.v1', 'event_type' => $type, 'event_id' => '0123456789abcdef-1', 'ts' => '2026-10-24T06:05:00Z',
            'service' => 'bff-a', 'environment' => 'production', 'run_id' => '0123456789abcdef', 'version' => '0.1.70',
            'window_start' => null, 'window_end' => null,
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @param array<string, mixed> $text
     */
    private function group(array $override = [], array $text = ['error_type' => 'App\\TimeoutException']): Event
    {
        return new Event('group', $override + $this->envelope('group') + [
            'rank' => 1, 'fingerprint' => '0123456789ab', 'level' => 'error', 'trend' => 'new', 'count' => 3, 'previous_count' => null,
            'first_seen' => '2026-10-23T07:00:00Z', 'last_seen' => '2026-10-24T05:59:00Z', 'has_finding' => true, 'watch_rules' => ['payments'],
        ], $text);
    }

    /** @param array<string, mixed> $text */
    private function finding(array $text = []): Event
    {
        return new Event('finding', $this->envelope('finding') + [
            'finding_index' => 1, 'fingerprint' => '0123456789ab', 'fingerprints' => ['0123456789ab'], 'kind' => 'bug', 'severity' => 'critical',
            'confidence' => 0.9, 'escalated' => false, 'group_trend' => 'new', 'group_count' => 3, 'group_previous_count' => null, 'after_release' => false,
        ], $text + ['title' => 'Timeouts calling billing', 'evidence' => '31 timeouts', 'hypothesis' => 'pool', 'next_step' => 'raise', 'check' => 'look', 'related' => ['billing']]);
    }
}
