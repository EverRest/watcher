<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\MissingEnvironmentVariable;
use Watcher\Config\WatchConfig;

/** Problems found while reading environment variables surface when the run starts, as a typed exception that names the variable. */
final class EnvProblemsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki', 'auth' => ['type' => 'bearer', 'token' => 't']]],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0, 0], 'm2' => [3, 15, 0, 0], 'm3' => [5, 25, 0, 0]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
        ];
    }

    /** @return array{kind: string, variable: string, key: string, message: string} */
    private static function missing(string $variable = 'WATCHER_DIGEST_BOT_TOKEN'): array
    {
        return ['kind' => 'missing', 'variable' => $variable, 'key' => 'notify.digest.bot_token', 'message' => "$variable is not set (notify.digest.bot_token)"];
    }

    /** @return array{kind: string, variable: string, key: string, message: string} */
    private static function invalid(string $variable = 'WATCHER_DIGEST_THREAD_ID'): array
    {
        return ['kind' => 'invalid', 'variable' => $variable, 'key' => 'notify.digest.thread_id', 'message' => "$variable must be a positive integer"];
    }

    public function testAMissingVariableIsATypedException(): void
    {
        $this->expectException(MissingEnvironmentVariable::class);
        $this->expectExceptionMessage('WATCHER_DIGEST_BOT_TOKEN is not set (notify.digest.bot_token)');

        WatchConfig::fromArray(self::valid() + ['env_problems' => [self::missing()]]);
    }

    public function testItIsStillAnInvalidConfigSoExistingHandlersKeepWorking(): void
    {
        try {
            WatchConfig::fromArray(self::valid() + ['env_problems' => [self::missing()]]);
            self::fail('expected an exception');
        } catch (InvalidConfig $e) {
            self::assertInstanceOf(MissingEnvironmentVariable::class, $e);
        }
    }

    public function testAnInvalidValueIsAnInvalidConfigButNotAMissingVariable(): void
    {
        try {
            WatchConfig::fromArray(self::valid() + ['env_problems' => [self::invalid()]]);
            self::fail('expected an exception');
        } catch (InvalidConfig $e) {
            self::assertNotInstanceOf(MissingEnvironmentVariable::class, $e);
            self::assertStringContainsString('WATCHER_DIGEST_THREAD_ID must be a positive integer', $e->getMessage());
        }
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        try {
            WatchConfig::fromArray(self::valid() + ['env_problems' => [self::missing('WATCHER_A'), self::invalid('WATCHER_B')]]);
            self::fail('expected an exception');
        } catch (MissingEnvironmentVariable $e) {
            self::assertStringContainsString('WATCHER_A is not set', $e->getMessage());
            self::assertStringContainsString('WATCHER_B must be a positive integer', $e->getMessage());
        }
    }

    public function testTheProblemsComeBeforeAnyOtherCheck(): void
    {
        $this->expectException(MissingEnvironmentVariable::class);

        WatchConfig::fromArray(['env_problems' => [self::missing()]]); // no service, no source: the variable is the news
    }

    public function testAnEmptyListChangesNothing(): void
    {
        $c = WatchConfig::fromArray(self::valid() + ['env_problems' => []]);

        self::assertSame('svc', $c->service);
    }

    public function testAMalformedListIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('env_problems');

        WatchConfig::fromArray(self::valid() + ['env_problems' => ['not a problem']]);
    }

    public function testTheKeyIsNotAConfigurationSetting(): void
    {
        $c = WatchConfig::fromArray(self::valid() + ['env_problems' => []]);

        self::assertStringNotContainsString('env_problems', (string) json_encode($c));
    }
}
