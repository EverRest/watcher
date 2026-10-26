<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Issues;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Issues\IssueGroupBuilder;
use Watcher\Issues\IssueRecord;
use Watcher\Level;
use Watcher\Reduce\Normalizer;

final class IssueGroupBuilderTest extends TestCase
{
    private function rec(string $id = '1001', string $title = 'Connection refused', string $culprit = 'App\\Repo::find', int $count = 10, Level $level = Level::Error, bool $regression = false): IssueRecord
    {
        return new IssueRecord($id, $title, $culprit, $level, new DateTimeImmutable('2026-10-22T10:00:00+02:00'), new DateTimeImmutable('2026-10-22T20:00:00Z'), $count, $regression);
    }

    private function builder(int $min = 3): IssueGroupBuilder
    {
        return new IssueGroupBuilder(new Normalizer(), $min);
    }

    public function testGroupShape(): void
    {
        $res = $this->builder()->build([$this->rec()]);

        $g = $res->groups[0];
        self::assertSame('sentry:1001', $g->fingerprint);
        self::assertSame('Connection refused', $g->template);
        self::assertSame(Level::Error, $g->level);
        self::assertSame(10, $g->count);
        self::assertSame(1, count($g->frames));
        self::assertSame('2026-10-22T08:00:00+00:00', $g->firstSeen->format('c'));
        self::assertSame(0, $res->dropped);
    }

    public function testTemplateIsWithheldBelowTheVolumeThreshold(): void
    {
        $g = $this->builder(3)->build([$this->rec(count: 2)])->groups[0];

        self::assertNull($g->template);
    }

    public function testSeededPersonalValuesAreMasked(): void
    {
        $title = 'Cannot charge anna.kowalski@example.org token sk_demo_51HxQ2eKzP9vWb3nTq7LmZ4Yc id 4711';
        $g = $this->builder()->build([$this->rec(title: $title, culprit: 'Pay::charge for anna.kowalski@example.org')])->groups[0];

        $text = $g->template . ' ' . implode(' ', $g->frames);
        self::assertStringNotContainsString('anna.kowalski', $text);
        self::assertStringNotContainsString('sk_demo', $text);
        self::assertStringNotContainsString('4711', $text);
    }

    public function testEmptyCulpritGivesNoFrame(): void
    {
        self::assertSame([], $this->builder()->build([$this->rec(culprit: '')])->groups[0]->frames);
    }

    public function testRegressionsAreReportedByFingerprintOnly(): void
    {
        $res = $this->builder()->build([$this->rec('1', regression: true), $this->rec('2')]);

        self::assertSame(['sentry:1'], $res->regressions);
    }

    public function testEqualTitlesStayTwoGroups(): void
    {
        $res = $this->builder()->build([$this->rec('1'), $this->rec('2')]);

        self::assertSame(['sentry:1', 'sentry:2'], array_map(static fn ($g): string => $g->fingerprint, $res->groups));
    }

    public function testAnIssueTheNormalizerCannotHandleIsDroppedAndCounted(): void
    {
        // lone invalid byte sequence makes Normalizer::scan throw NormalizationException
        $res = $this->builder()->build([$this->rec('1', title: "bad \xff bytes"), $this->rec('2')]);

        self::assertSame(['sentry:2'], array_map(static fn ($g): string => $g->fingerprint, $res->groups));
        self::assertSame(1, $res->dropped);
    }

    public function testRareIssueWithholdsTheCulpritToo(): void
    {
        $g = $this->builder(3)->build([$this->rec(culprit: 'Pay::charge for Anna Kowalski anna.kowalski@example.org', count: 2)])->groups[0];

        self::assertNull($g->template);
        self::assertSame([], $g->frames);
    }

    public function testCommonIssueKeepsTheMaskedCulpritAsFirstFrame(): void
    {
        $g = $this->builder(3)->build([$this->rec(culprit: 'Pay::charge anna.kowalski@example.org', count: 3)])->groups[0];

        self::assertCount(1, $g->frames);
        self::assertStringNotContainsString('anna.kowalski', $g->frames[0]);
        self::assertStringContainsString('Pay::charge', $g->frames[0]);
    }

    /** Review F6: cutting before masking left `<HOST>@exa`; masking first and cutting after leaves no part of the value. */
    public function testTitleAndCulpritAreMaskedThenCut(): void
    {
        $email = 'anna.kowalski@example.org';
        foreach ([[IssueGroupBuilder::TITLE, 'title'], [IssueGroupBuilder::CULPRIT, 'culprit']] as [$cap, $field]) {
            for ($pad = $cap - 24; $pad <= $cap + 1; ++$pad) { // the e-mail starts at every offset around the cut
                $text = str_repeat('x ', intdiv($pad, 2)) . ($pad % 2 === 1 ? 'y' : '') . $email . ' tail';
                $g = $this->builder()->build([$field === 'title' ? $this->rec(title: $text) : $this->rec(culprit: $text)])->groups[0];
                $out = $field === 'title' ? (string) $g->template : $g->frames[0];
                self::assertLessThanOrEqual($cap, mb_strlen($out));
                self::assertStringNotContainsString('kowalski', $out);
                self::assertStringNotContainsString('@exa', $out);
                self::assertStringNotContainsString('example', $out);
            }
        }
    }
}
