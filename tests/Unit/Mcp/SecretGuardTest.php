<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\SecretGuard;
use Watcher\Mcp\SecretRefused;

final class SecretGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function secrets(): array
    {
        $c = require __DIR__ . '/../../Fixtures/mcp/secret-corpus.php';

        return array_map(static fn (string $s): array => [$s], $c['secrets']);
    }

    /** @return array<string, array{string}> */
    public static function nearMisses(): array
    {
        $c = require __DIR__ . '/../../Fixtures/mcp/secret-corpus.php';

        return array_map(static fn (string $s): array => [$s], $c['near_misses']);
    }

    public function testCorpusSizes(): void
    {
        self::assertGreaterThanOrEqual(30, count(self::secrets()));
        self::assertGreaterThanOrEqual(30, count(self::nearMisses()));
    }

    /** @dataProvider secrets */
    public function testSecretShapedValuesAreRefused(string $value): void
    {
        self::assertTrue((new SecretGuard())->looksSecret($value));
    }

    /** @dataProvider nearMisses */
    public function testNearMissesPass(string $value): void
    {
        self::assertFalse((new SecretGuard())->looksSecret($value), $value);
    }

    public function testTheRefusalHoldsNoValueAndWalksNestedKeysAndValues(): void
    {
        $secret = 'ghp_' . 'abcdefghijklmnopqrstuvwxyz0123456789';
        $g = new SecretGuard();
        foreach ([['a' => ['b' => $secret]], [$secret => 'x'], ['list' => ['fine', $secret]]] as $input) {
            try {
                $g->assertClean($input);
                self::fail('not refused');
            } catch (SecretRefused $e) {
                self::assertSame('Give the name of the environment variable, not its value.', $e->getMessage());
                self::assertStringNotContainsString($secret, print_r($e, true));
            }
        }
        $g->assertClean(['service' => 'orders-api', 'n' => 3, 'b' => true, 'x' => null]);
    }

    public function testAnOverlongInputIsRefusedWithoutAScan(): void
    {
        self::assertTrue((new SecretGuard())->looksSecret(str_repeat('a b ', 6000)));
    }
}
