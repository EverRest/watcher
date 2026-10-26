<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\FleetConfig;
use Watcher\Notify\Destination;
use Watcher\Operate\AnthropicModelCatalog;
use Watcher\Source\DatadogSource;
use Watcher\Source\LokiSource;
use Watcher\State\PdoStateStore;

/** S2-07: a dump of the config object (var_dump, dd(), Ignition) shows no secret; secret constructor parameters are hidden from traces. */
final class ConfigDumpTest extends TestCase
{
    private const SECRETS = ['sk-secret-key-AAAA', 'https://hooks.example/SECRETHOOK', 'loki-bearer-BBBB', 'redis-pass-CCCC', 'dd-api-DDDD'];

    /** @return array<string, mixed> */
    private static function raw(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example', 'auth' => ['type' => 'bearer', 'token' => 'loki-bearer-BBBB']]],
            'analysis' => [
                'api_key' => 'sk-secret-key-AAAA',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'redis', 'redis' => ['host' => 'r.example', 'password' => 'redis-pass-CCCC']],
            'notify' => ['digest' => ['webhook' => 'https://hooks.example/SECRETHOOK']],
        ];
    }

    public function testWatchConfigDumpsShowNoSecret(): void
    {
        $c = WatchConfig::fromArray(self::raw());
        ob_start();
        var_dump($c);
        print_r($c);
        $dump = (string) ob_get_clean() . json_encode($c);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        self::assertStringContainsString('svc', $dump);
        self::assertSame('sk-secret-key-AAAA', WatchConfig::reveal($c->analysis['api_key']), 'the real value is still there for the code that needs it');
    }

    public function testFleetConfigDumpsShowNoSecret(): void
    {
        $fleet = FleetConfig::fromArray(['fleet' => 'f', 'services' => [self::raw()], 'notify' => ['digest' => ['webhook' => 'https://hooks.example/SECRETHOOK']]]);
        ob_start();
        var_dump($fleet);
        $dump = (string) ob_get_clean();

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
    }

    public function testSecretConstructorParametersAreSensitive(): void
    {
        if (!class_exists(\SensitiveParameter::class)) {
            self::markTestSkipped('PHP < 8.2 has no SensitiveParameter; the attribute is inert there');
        }
        $expect = [
            [AnthropicAnalyzer::class, ['apiKey']],
            [AnthropicModelCatalog::class, ['apiKey']],
            [LokiSource::class, ['bearerToken', 'basicPassword', 'tenant']],
            [DatadogSource::class, ['apiKey', 'appKey']],
            [Destination::class, ['webhook', 'botToken', 'chatId']],
            [PdoStateStore::class, []],
        ];
        foreach ($expect as [$class, $names]) {
            $params = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
            foreach ($params as $param) {
                $sensitive = $param->getAttributes(\SensitiveParameter::class) !== [];
                self::assertSame(in_array($param->getName(), $names, true), $sensitive, $class . '::$' . $param->getName());
            }
        }
        $from = (new ReflectionClass(WatchConfig::class))->getMethod('fromArray')->getParameters()[0];
        self::assertNotSame([], $from->getAttributes(\SensitiveParameter::class));
    }

    /** F-09: `$` without `D` accepts a trailing newline. */
    public function testValidatorsRejectATrailingNewline(): void
    {
        foreach ([['environment', "prod\n"], ['schedule', ['run_at' => "06:00\n"]]] as [$key, $value]) {
            $raw = self::raw();
            $raw[$key] = $value;
            try {
                WatchConfig::fromArray($raw);
                self::fail("$key accepted a trailing newline");
            } catch (\Watcher\Config\InvalidConfig) {
                $this->addToAssertionCount(1);
            }
        }
        $raw = self::raw();
        $raw['state'] = ['driver' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:', 'table' => "t\n"]];
        $this->expectException(\Watcher\Config\InvalidConfig::class);
        WatchConfig::fromArray($raw);
    }
}
