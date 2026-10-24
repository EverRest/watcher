<?php

declare(strict_types=1);

namespace Watcher\Tests\Perf;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Watcher\State\StateCodec;

/**
 * Not part of the default run: `vendor/bin/phpunit --testsuite Perf --group perf`.
 * Measures what z1 compression saves on a realistic snapshot (~150 KB of JSON: thousands of 12-hex fingerprints with
 * skewed counts, the plain totals and the deploy block). Fingerprints are hashes, so this is close to the worst case
 * for zlib; templates and verdicts compress better.
 */
#[Group('perf')]
final class CompressionRatioTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function snapshot(int $targetBytes): array
    {
        mt_srand(2026);
        $groups = [];
        while (strlen((string) json_encode(['groups' => $groups])) < $targetBytes) {
            $fp = substr(sha1((string) mt_rand()), 0, 12);
            $groups[$fp] = (int) max(1, floor(exp(mt_rand(0, 700) / 100))); // long tail: many 1s, a few thousands
        }

        return [
            'groups' => $groups,
            'totals' => ['error' => 18234, 'warning' => 90211, 'info' => 4410023],
            'deploy' => ['release' => '1.52.2', 'commit' => 'abc1234', 'at' => '2026-10-23T05:41:00+00:00'],
            'start' => '2026-10-22T06:00:00Z',
        ];
    }

    public function testRatioOnA150KbSnapshot(): void
    {
        $snap = self::snapshot(150 * 1024);
        $json = (string) json_encode($snap);

        $t = microtime(true);
        $stored = StateCodec::encode($snap, 512);
        $enc = (microtime(true) - $t) * 1000;
        $t = microtime(true);
        $back = StateCodec::decode($stored);
        $dec = (microtime(true) - $t) * 1000;

        self::assertSame($snap, $back);
        self::assertStringStartsWith('z1:', $stored);
        $ratio = strlen($stored) / strlen($json);
        fwrite(STDERR, sprintf(
            "\n[perf] snapshot: %d groups, json %d B -> stored %d B (ratio %.3f, saves %.1f%%), encode %.2f ms, decode %.2f ms\n",
            count($snap['groups']),
            strlen($json),
            strlen($stored),
            $ratio,
            (1 - $ratio) * 100,
            $enc,
            $dec,
        ));
        self::assertLessThan(0.85, $ratio, 'compression must at least pay for itself on fingerprint data');
    }
}
