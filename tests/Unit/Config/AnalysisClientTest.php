<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Analyze\SdkMessagesClient;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class AnalysisClientTest extends TestCase
{
    protected function tearDown(): void
    {
        SdkMessagesClient::$installed = null;
    }

    /** @return array<string, mixed> */
    private static function config(?string $client = null): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki', 'auth' => ['type' => 'bearer', 'token' => 'tok-secret']]],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]]]
                + ($client === null ? [] : ['client' => $client]),
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
        ];
    }

    public function testTheDefaultIsHttp(): void
    {
        $c = WatchConfig::fromArray(self::config());

        self::assertSame('http', $c->analysis['client']);
    }

    public function testHttpBuildsTheOwnClient(): void
    {
        $c = WatchConfig::fromArray(self::config('http'));

        self::assertInstanceOf(AnthropicAnalyzer::class, Factory::analyzer($c));
    }

    public function testSdkIsBuiltWhenInstalled(): void
    {
        SdkMessagesClient::$installed = true;
        $c = WatchConfig::fromArray(self::config('sdk'));

        self::assertSame('sdk', $c->analysis['client']);
        self::assertInstanceOf(AnthropicAnalyzer::class, Factory::analyzer($c));
    }

    public function testSdkWithoutThePackageFailsAtConfigLoadNamingTheKeyAndTheFix(): void
    {
        SdkMessagesClient::$installed = false;

        try {
            WatchConfig::fromArray(self::config('sdk'));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('analysis.client', $e->getMessage());
            self::assertStringContainsString('composer require anthropic-ai/sdk', $e->getMessage());
            self::assertStringNotContainsString('sk-secret-key', $e->getMessage());
        }
    }

    public function testHttpDoesNotNeedThePackage(): void
    {
        SdkMessagesClient::$installed = false;

        self::assertSame('http', WatchConfig::fromArray(self::config('http'))->analysis['client']);
    }

    public function testAnUnknownClientIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.client');
        WatchConfig::fromArray(self::config('curl'));
    }

    /** The default path never touches the SDK: with it uninstalled nothing in the Anthropic namespace may be needed. */
    public function testTheHttpPathLoadsNoSdkClass(): void
    {
        // A child of our own (not RunInSeparateProcess) so dependency deprecations cannot reach the captured output.
        $script = sys_get_temp_dir() . '/watcher-nosdk-' . getmypid() . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$a = Watcher\\Config\\Factory::analyzer(Watcher\\Config\\WatchConfig::fromArray(' . var_export(self::config(), true) . '));'
            . 'echo "RESULT ", $a instanceof Watcher\\Analyze\\AnthropicAnalyzer ? "analyzer" : "other", " ", class_exists(Anthropic\\Client::class, false) ? "sdk-loaded" : "no-sdk", "\\n";');
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d xdebug.mode=off ' . escapeshellarg($script) . ' 2>&1');
        @unlink($script);

        self::assertStringContainsString("RESULT analyzer no-sdk\n", $out, 'the SDK was autoloaded by the default path, or the child failed: ' . $out);
    }
}
