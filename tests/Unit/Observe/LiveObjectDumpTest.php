<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Notify\NoticeRenderer;
use Watcher\Observe\ObserveCounters;
use Watcher\Schedule\SystemClock;

/** The live objects that hold a credential must not show it through var_dump, print_r, var_export, json_encode or serialize. */
final class LiveObjectDumpTest extends TestCase
{
    private const CANARIES = ['CANARY-obs-token-5', 'CANARY-url-4', 'CANARY-hook-3', 'CANARY-model-key-2', 'CANARY-loki-token-1'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/livedump-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'service' => 'svc', 'enabled' => true, 'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.com', 'auth' => ['type' => 'bearer', 'token' => 'CANARY-loki-token-1']]],
            'analysis' => ['api_keys' => ['gemini' => 'CANARY-model-key-2'], 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => $this->dir]],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/CANARY-hook-3']],
            'observe' => ['http' => ['enabled' => true, 'url' => 'https://ingest.example.com/CANARY-url-4', 'auth_token' => 'CANARY-obs-token-5']],
        ];
    }

    /** @return array<string, array{string}> */
    public static function objects(): array
    {
        return ['sink dispatcher with its http sink' => ['dispatcher'], 'heartbeat' => ['heartbeat'], 'notice renderer' => ['renderer'], 'notifier' => ['notifier'], 'loki source' => ['loki'], 'datadog source' => ['datadog'], 'model catalog' => ['catalog']];
    }

    private function build(string $which): object
    {
        $c = WatchConfig::fromArray($this->config());
        $clock = new SystemClock();
        $state = Factory::state($c, $clock);

        return match ($which) {
            'dispatcher' => Factory::observe($c, $clock, null, new ObserveCounters($state, 'svc', 100)) ?? throw new \LogicException('no dispatcher'),
            'heartbeat' => Factory::heartbeat($c, $state, $clock, null),
            'renderer' => new NoticeRenderer($c->secrets()),
            'loki' => Factory::source($c, Factory::http(), $clock),
            'datadog' => new \Watcher\Source\DatadogSource(Factory::http(), 'datadoghq.com', 'CANARY-model-key-2', 'CANARY-loki-token-1'),
            default => Factory::notifier(Factory::http(), 900),
        };
    }

    #[DataProvider('objects')]
    public function testNoDumpFormatShowsACredential(string $which): void
    {
        $o = $this->build($which);
        $dumps = [print_r($o, true), var_export($o, true), (string) json_encode($o)];
        ob_start();
        var_dump($o);
        $dumps[] = (string) ob_get_clean();
        try {
            $dumps[] = serialize($o);
        } catch (\Throwable) { // refused (a LogicException of ours, or a Guzzle closure): nothing is written
            $this->addToAssertionCount(1);
        }

        foreach ($dumps as $dump) {
            foreach (self::CANARIES as $canary) {
                self::assertStringNotContainsString($canary, $dump, "$which shows $canary");
            }
        }
    }

    public function testTheObjectsStillWork(): void
    {
        $c = WatchConfig::fromArray($this->config());
        $notices = new NoticeRenderer($c->secrets());

        self::assertStringNotContainsString('CANARY-hook-3', $notices->describe(new \RuntimeException('failed at https://hooks.example/CANARY-hook-3')));
    }
}
