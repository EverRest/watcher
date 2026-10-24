<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Env;
use Watcher\Config\InvalidConfig;

final class EnvTest extends TestCase
{
    private const VAR = 'WATCHER_TEST_ENV_VAR';

    protected function tearDown(): void
    {
        putenv(self::VAR);
        unset($_ENV[self::VAR], $_SERVER[self::VAR]);
    }

    /** @return array<array-key, array{string, bool}> */
    public static function booleans(): array
    {
        return [
            'true' => ['true', true], 'TRUE' => ['TRUE', true], '1' => ['1', true], 'yes' => ['yes', true], 'On' => ['On', true],
            'false' => ['false', false], 'FALSE' => ['FALSE', false], '0' => ['0', false], 'no' => ['no', false], 'off' => ['OFF', false],
        ];
    }

    #[DataProvider('booleans')]
    public function testBoolParsesTheUsualSpellings(string $raw, bool $expected): void
    {
        putenv(self::VAR . '=' . $raw);

        self::assertSame($expected, Env::bool(self::VAR, !$expected));
    }

    public function testUnsetAndEmptyGiveTheDefault(): void
    {
        self::assertTrue(Env::bool(self::VAR, true));
        putenv(self::VAR . '=');
        self::assertFalse(Env::bool(self::VAR, false));
        self::assertSame(7, Env::int(self::VAR, 7));
        self::assertNull(Env::string(self::VAR));
        self::assertSame(['a'], Env::list(self::VAR, ['a']));
    }

    public function testInvalidBoolNamesTheVariableNeverTheValue(): void
    {
        putenv(self::VAR . '=sekret-value');
        try {
            Env::bool(self::VAR, true);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString(self::VAR, $e->getMessage());
            self::assertStringNotContainsString('sekret-value', $e->getMessage());
        }
    }

    public function testIntFloatStringList(): void
    {
        putenv(self::VAR . '=42');
        self::assertSame(42, Env::int(self::VAR, 1));
        putenv(self::VAR . '=0.75');
        self::assertSame(0.75, Env::float(self::VAR, 1.0));
        putenv(self::VAR . '=hello');
        self::assertSame('hello', Env::string(self::VAR, 'x'));
        putenv(self::VAR . '= a, b ,,c ');
        self::assertSame(['a', 'b', 'c'], Env::list(self::VAR));
    }

    public function testInvalidIntAndFloatThrow(): void
    {
        putenv(self::VAR . '=12abc');
        $this->expectException(InvalidConfig::class);
        Env::int(self::VAR, 1);
    }

    public function testInvalidFloatThrows(): void
    {
        putenv(self::VAR . '=nope');
        $this->expectException(InvalidConfig::class);
        Env::float(self::VAR, 1.0);
    }

    public function testReadsServerAndEnvSuperglobalsToo(): void
    {
        $_SERVER[self::VAR] = 'true';
        self::assertTrue(Env::bool(self::VAR, false));
    }
}
