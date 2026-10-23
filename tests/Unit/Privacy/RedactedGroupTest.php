<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Privacy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\Reduce\ProblemGroup;

final class RedactedGroupTest extends TestCase
{
    /** @param list<string> $frames */
    private function make(array $frames = ['a.php:1']): RedactedGroup
    {
        return new RedactedGroup(
            'fp1',
            'Failed for <id>',
            Level::Error,
            'RuntimeException',
            $frames,
            7,
            3,
            GroupStatusLabel::Grown,
            ['payments'],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-01-01T01:00:00+00:00'),
        );
    }

    public function testOnlyAllowlistedProperties(): void
    {
        $names = array_map(
            static fn (ReflectionProperty $p) => $p->getName(),
            (new ReflectionClass(RedactedGroup::class))->getProperties(),
        );
        sort($names);
        $expected = ['count', 'errorType', 'fingerprint', 'firstSeen', 'frames', 'lastSeen', 'level',
            'previousCount', 'status', 'template', 'watchMatches'];
        self::assertSame($expected, $names);
        self::assertTrue((new ReflectionClass(RedactedGroup::class))->isFinal());
    }

    public function testFramesCappedAtFive(): void
    {
        $g = $this->make(['1', '2', '3', '4', '5', '6', '7']);
        self::assertSame(['1', '2', '3', '4', '5'], $g->frames);
    }

    public function testToArrayExactKeysAndIsoDates(): void
    {
        $a = $this->make()->toArray();
        self::assertSame(
            ['fp', 'tpl', 'lvl', 'err', 'frames', 'n', 'prev', 'st', 'watch', 'first', 'last'],
            array_keys($a),
        );
        self::assertSame('2026-01-01T00:00:00+00:00', $a['first']);
        self::assertSame('error', $a['lvl']);
        self::assertSame('grown', $a['st']);
        self::assertSame(7, $a['n']);
    }

    public function testNullTemplateMeansWithheld(): void
    {
        $g = new RedactedGroup(
            'f',
            null,
            Level::Info,
            null,
            [],
            1,
            null,
            GroupStatusLabel::New,
            [],
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );
        self::assertNull($g->toArray()['tpl']);
        self::assertNull($g->toArray()['prev']);
    }

    private function pg(?string $tpl): ProblemGroup
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return (new ProblemGroup('fp9', $tpl, Level::Critical, 'E', ['f1'], 30, $t, $t, null, \Watcher\Reduce\GroupStatus::New, ['rule']))
            ->withPrevious(10, 3.0);
    }

    public function testFromProblemGroupProjectsAllowlist(): void
    {
        $r = RedactedGroup::fromProblemGroup($this->pg('T <id>'));
        self::assertSame('fp9', $r->fingerprint);
        self::assertSame('T <id>', $r->template);
        self::assertSame(Level::Critical, $r->level);
        self::assertSame(30, $r->count);
        self::assertSame(10, $r->previousCount);
        self::assertSame(GroupStatusLabel::Grown, $r->status);
        self::assertSame(['rule'], $r->watchMatches);
        self::assertSame(['f1'], $r->frames);
    }

    public function testFromProblemGroupNullTemplateStaysNull(): void
    {
        self::assertNull(RedactedGroup::fromProblemGroup($this->pg(null))->template);
    }

    public function testFromProblemGroupJsonHasOnlyAllowlistedKeys(): void
    {
        $json = json_decode((string) json_encode(RedactedGroup::fromProblemGroup($this->pg('t'))), true);
        self::assertSame(
            ['fp', 'tpl', 'lvl', 'err', 'frames', 'n', 'prev', 'st', 'watch', 'first', 'last'],
            array_keys($json),
        );
    }
}
