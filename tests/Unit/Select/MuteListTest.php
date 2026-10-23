<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Select;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\MuteList;

final class MuteListTest extends TestCase
{
    private function g(string $fp, ?string $tpl, ?string $type = null): ProblemGroup
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new ProblemGroup($fp, $tpl, Level::Error, $type, [], 1, $t, $t);
    }

    private function now(string $s = '2026-05-10 12:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new \DateTimeZone('UTC'));
    }

    public function testFingerprintMute(): void
    {
        $r = (new MuteList([['fingerprint' => 'a']]))->filter([$this->g('a', 't'), $this->g('b', 't')], [], $this->now());
        self::assertSame(['b'], array_map(static fn ($g) => $g->fingerprint, $r->kept));
        self::assertSame(1, $r->mutedCount);
    }

    public function testMatcherMutes(): void
    {
        $m = new MuteList([
            ['template' => '/noisy/'],
            ['error_type' => '/Timeout/'],
            ['field' => 'tenant', 'equals' => 'acme'],
        ]);
        $groups = [$this->g('a', 'noisy thing'), $this->g('b', 'x', 'ReadTimeout'), $this->g('c', 'x'), $this->g('d', 'x')];
        $r = $m->filter($groups, ['c' => ['2']], $this->now());
        self::assertSame(['d'], array_map(static fn ($g) => $g->fingerprint, $r->kept));
        self::assertSame(3, $r->mutedCount);
    }

    public function testFieldMuteNeedsEventMatchForThatEntry(): void
    {
        $m = new MuteList([['template' => '/zzz/'], ['field' => 'tenant', 'equals' => 'acme']]);
        // event matcher index is the entry's list index, as a string
        $r = $m->filter([$this->g('c', 'x')], ['c' => ['0']], $this->now());
        self::assertSame(1, count($r->kept), 'index 0 is a template entry, an event hit for it means nothing');
        $r = $m->filter([$this->g('c', 'x')], ['c' => ['1']], $this->now());
        self::assertSame([], $r->kept);
    }

    public function testNullTemplateNeverMatchesTemplateMute(): void
    {
        $r = (new MuteList([['template' => '/.*/']]))->filter([$this->g('a', null)], [], $this->now());
        self::assertCount(1, $r->kept);
        self::assertSame(0, $r->mutedCount);
    }

    public function testUntilIsInclusiveThroughEndOfDayUtc(): void
    {
        $m = new MuteList([['fingerprint' => 'a', 'until' => '2026-05-10']]);
        self::assertSame(1, $m->filter([$this->g('a', 't')], [], $this->now('2026-05-10 23:59:59'))->mutedCount);
        self::assertSame(0, $m->filter([$this->g('a', 't')], [], $this->now('2026-05-11 00:00:00'))->mutedCount);
    }

    public function testNullUntilIsForever(): void
    {
        $m = new MuteList([['fingerprint' => 'a', 'until' => null]]);
        self::assertSame(1, $m->filter([$this->g('a', 't')], [], $this->now('2099-01-01'))->mutedCount);
    }
}
