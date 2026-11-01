<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Notify\DigestData;
use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Message;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

/** Spec 018 US1: the digest lists every flagged group, across as many messages as it needs. */
final class DigestPartsTest extends TestCase
{
    private function fp(int $i): string
    {
        return substr(hash('sha256', (string) $i), 0, 16);
    }

    /** @param list<int> $indexes */
    private function data(array $indexes): DigestData
    {
        $t = new DateTimeImmutable('2026-10-23T01:00:00Z');
        $groups = [];
        $unanalysed = [];
        foreach ($indexes as $i) {
            $groups[$this->fp($i)] = new RedactedGroup($this->fp($i), 'tpl ' . $i, Level::Error, null, [], 5, null, GroupStatusLabel::Unchanged, [], $t, $t);
            $unanalysed[] = ['fingerprint' => $this->fp($i), 'level' => Level::Error, 'count' => 5];
        }

        return new DigestData(
            service: 'svc',
            environment: 'stg',
            windowStart: new DateTimeImmutable('2026-10-22T03:00:00Z'),
            windowEnd: new DateTimeImmutable('2026-10-23T03:00:00Z'),
            timezone: 'Europe/Kyiv',
            findings: [],
            groups: $groups,
            unanalysedGroups: $unanalysed,
        );
    }

    /** @param list<Message> $parts */
    private static function listed(array $parts): string
    {
        $out = '';
        foreach ($parts as $m) {
            foreach ($m->sections as $s) {
                if ($s['kind'] === 'finding') {
                    $out .= $s['text'] . "\n";
                }
            }
        }

        return $out;
    }

    public function testEveryFlaggedGroupIsListedOnceAcrossTheParts(): void
    {
        $parts = (new DigestRenderer())->renderParts($this->data(range(1, 120)), 0.5);

        self::assertGreaterThan(1, count($parts));
        $listed = self::listed($parts);
        for ($i = 1; $i <= 120; ++$i) {
            self::assertSame(1, substr_count($listed, $this->fp($i)), 'group ' . $i . ' listed exactly once');
        }
    }

    public function testNoPartExceedsTheSectionAndCharacterBudget(): void
    {
        $parts = (new DigestRenderer())->renderParts($this->data(range(1, 120)), 0.5);

        foreach ($parts as $m) {
            self::assertLessThanOrEqual(49, count($m->sections));
            $chars = 0;
            foreach ($m->sections as $s) {
                $chars += mb_strlen($s['text']);
            }
            self::assertLessThanOrEqual(3500, $chars);
        }
    }

    public function testAMinorFindingIsListedNotOnlyCounted(): void
    {
        // Spec 018 US1 scenario 3: a below-threshold group is still in the digest, as a minor line with its counts.
        $low = new \Watcher\Analyze\Finding([$this->fp(9)], \Watcher\Analyze\FindingKind::Bug, \Watcher\Analyze\Severity::Warn, 'quiet slow query', 'e', 'c', 'a', 0.1);
        $parts = (new DigestRenderer())->renderParts(new DigestData(
            service: 'svc',
            environment: 'stg',
            windowStart: new DateTimeImmutable('2026-10-22T03:00:00Z'),
            windowEnd: new DateTimeImmutable('2026-10-23T03:00:00Z'),
            timezone: 'Europe/Kyiv',
            findings: [$low],
            groups: [$this->fp(9) => new RedactedGroup($this->fp(9), 'tpl', Level::Warning, null, [], 4, null, GroupStatusLabel::Unchanged, [], new DateTimeImmutable('2026-10-23T01:00:00Z'), new DateTimeImmutable('2026-10-23T01:00:00Z'))],
        ), 0.5);

        $minor = implode("\n", array_column(array_filter($parts[0]->sections, static fn (array $s): bool => $s['kind'] === 'minor'), 'text'));
        self::assertStringContainsString('quiet slow query', $minor);
        self::assertStringContainsString($this->fp(9), $minor);
    }

    public function testASingleMessageHasNoPartHeader(): void
    {
        $parts = (new DigestRenderer())->renderParts($this->data([1, 2]), 0.5);

        self::assertCount(1, $parts);
        self::assertStringNotContainsString('part ', $parts[0]->text);
    }

    public function testSeveralMessagesAreNumberedPartOfTotal(): void
    {
        $parts = (new DigestRenderer())->renderParts($this->data(range(1, 120)), 0.5);
        $n = count($parts);

        self::assertStringContainsString('part 1/' . $n, $parts[0]->text);
        self::assertStringContainsString('part ' . $n . '/' . $n, $parts[$n - 1]->text);
    }
}
