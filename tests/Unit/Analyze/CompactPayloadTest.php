<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\CompactPayload;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class CompactPayloadTest extends TestCase
{
    /** @param list<string> $frames */
    private static function group(string $fp, ?string $tpl, array $frames = [], int $n = 1): RedactedGroup
    {
        return new RedactedGroup($fp, $tpl, Level::Error, null, $frames, $n, null, GroupStatusLabel::New, [], new DateTimeImmutable('2026-10-23T05:00:00+00:00'), new DateTimeImmutable('2026-10-23T05:59:00+00:00'));
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($d);

        return $d;
    }

    public function testEachTemplateAppearsOnceAndIsReferencedByIndex(): void
    {
        $json = CompactPayload::encode([self::group('a', 'Timeout <N> ms'), self::group('b', 'Other'), self::group('c', 'Timeout <N> ms')]);
        $d = self::decode($json);

        self::assertSame(['Timeout <N> ms', 'Other'], $d['t']);
        self::assertSame(1, substr_count($json, 'Timeout <N> ms'));
        self::assertSame([0, 1, 0], array_column($d['g'], 't'));
        self::assertSame(['a', 'b', 'c'], array_column($d['g'], 'fp'));
        self::assertArrayNotHasKey('tpl', $d['g'][0]);
    }

    public function testNullTemplateIsSentAsNull(): void
    {
        $d = self::decode(CompactPayload::encode([self::group('a', null)]));

        self::assertSame([], $d['t']);
        self::assertNull($d['g'][0]['t']);
    }

    public function testUnindentedUnescapedAndAtMostFiveFrames(): void
    {
        $json = CompactPayload::encode([self::group('a', 'héllo/x', ['f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7'])]);

        self::assertStringNotContainsString("\n", $json);
        self::assertStringContainsString('héllo/x', $json);
        self::assertCount(5, self::decode($json)['g'][0]['frames']);
    }

    public function testEmptyInput(): void
    {
        self::assertSame(['t' => [], 'g' => []], self::decode(CompactPayload::encode([])));
    }

    public function testFiveHundredGroupsAreSmallerThanTheNaiveEncoding(): void
    {
        $groups = [];
        for ($i = 0; $i < 500; ++$i) {
            $groups[] = self::group('fp' . $i, 'Gateway timeout after <N> ms calling <HOST> with a long template ' . ($i % 10), ['A::b', 'C::d']);
        }
        $compact = CompactPayload::encode($groups);
        $naive = json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertLessThan(strlen((string) $naive), strlen($compact));
        self::assertSame(10, count(self::decode($compact)['t']));
        self::assertCount(500, self::decode($compact)['g']);
    }
}
