<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;

/** Spec 013 T023: the shipped plain-PHP configs read every WATCHER_OBSERVE_* variable through Env, and every sink stays off by default. */
final class ObserveEnvConfigTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const BASE = [
        'WATCHER_LOKI_URL' => 'https://loki.example.com', 'WATCHER_LOKI_TOKEN' => 't', 'WATCHER_ANTHROPIC_KEY' => 'k',
        'WATCHER_SLACK_WEBHOOK' => 'https://hooks.example.com/x', 'WATCHER_DB_DSN' => 'sqlite::memory:',
        'WATCHER_MODEL_TRIAGE' => 'model-a', 'WATCHER_MODEL_ANALYZE' => 'model-b', 'WATCHER_MODEL_ESCALATE' => 'model-c',
        'WATCHER_PRICE_TRIAGE_IN' => '1', 'WATCHER_PRICE_TRIAGE_OUT' => '5', 'WATCHER_PRICE_ANALYZE_IN' => '3',
        'WATCHER_PRICE_ANALYZE_OUT' => '15', 'WATCHER_PRICE_ESCALATE_IN' => '5', 'WATCHER_PRICE_ESCALATE_OUT' => '25',
    ];

    /**
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private static function load(string $file, array $env): array
    {
        $before = [];
        foreach ($env + self::BASE as $k => $v) {
            $before[$k] = getenv($k);
            putenv("$k=$v");
        }
        try {
            /** @var array<string, mixed> $config */
            $config = require self::ROOT . '/' . $file;

            return $config;
        } finally {
            foreach ($before as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
        }
    }

    /** @return list<string> */
    public static function files(): array
    {
        return ['config/watcher.example.php'];
    }

    public function testEverySinkIsOffByDefaultInEveryShippedConfig(): void
    {
        foreach (['config/watcher.example.php', 'docs/examples/orders-api.loki.config.php', 'docs/examples/smoke.config.php'] as $file) {
            $c = WatchConfig::fromArray(self::load($file, []));

            self::assertFalse($c->observe->anyEnabled(), $file);
            self::assertFalse($c->observe->log['include_text'], $file . ': text is opt-in even for the log sink');
            self::assertSame(20, $c->observe->topGroups, $file);
        }
        self::assertStringContainsString("'observe'", (string) file_get_contents(self::ROOT . '/config/watcher.php'));
        self::assertStringContainsString("'observe'", (string) file_get_contents(self::ROOT . '/docs/examples/watcher-fleet.php'));
    }

    public function testTheDigestDriverIsSelectableFromTheEnvironment(): void
    {
        foreach (['config/watcher.example.php', 'docs/examples/orders-api.loki.config.php'] as $file) {
            $c = WatchConfig::fromArray(self::load($file, ['WATCHER_DIGEST_DRIVER' => 'none', 'WATCHER_SLACK_WEBHOOK' => '', 'WATCHER_OBSERVE_LOG_ENABLED' => '1']));

            self::assertSame('none', $c->destination('digest')->driver(), $file);
        }
    }

    public function testEveryObserveVariableIsReadThroughEnv(): void
    {
        $dir = sys_get_temp_dir();
        $c = WatchConfig::fromArray(self::load('config/watcher.example.php', [
            'WATCHER_OBSERVE_TOP_GROUPS' => '7',
            'WATCHER_OBSERVE_LOG_ENABLED' => '1', 'WATCHER_OBSERVE_LOG_STREAM' => 'stdout', 'WATCHER_OBSERVE_LOG_INCLUDE_TEXT' => '1',
            'WATCHER_OBSERVE_FILE_ENABLED' => '1', 'WATCHER_OBSERVE_FILE_PATH' => $dir . '/events.jsonl', 'WATCHER_OBSERVE_FILE_ROTATE' => 'daily',
            'WATCHER_OBSERVE_FILE_MAX_BYTES' => '2097152', 'WATCHER_OBSERVE_FILE_MAX_FILES' => '4', 'WATCHER_OBSERVE_FILE_RETENTION_DAYS' => '9', 'WATCHER_OBSERVE_FILE_INCLUDE_TEXT' => '1',
            'WATCHER_OBSERVE_HTTP_ENABLED' => '1', 'WATCHER_OBSERVE_HTTP_URL' => 'https://ingest.example.com/in', 'WATCHER_OBSERVE_HTTP_AUTH_HEADER' => 'X-API-Key',
            'WATCHER_OBSERVE_HTTP_AUTH_SCHEME' => 'Token', 'WATCHER_OBSERVE_HTTP_AUTH_TOKEN' => 'tok-observe-1234', 'WATCHER_OBSERVE_HTTP_BATCH_SIZE' => '50',
            'WATCHER_OBSERVE_HTTP_TIMEOUT' => '8', 'WATCHER_OBSERVE_HTTP_ALLOWED_HOSTS' => 'ingest.example.com, other.example.com',
            'WATCHER_OBSERVE_HTTP_ALLOW_INSECURE' => 'false', 'WATCHER_OBSERVE_HTTP_ALLOW_PRIVATE_NETWORK' => 'false', 'WATCHER_OBSERVE_HTTP_INCLUDE_TEXT' => 'false',
            'WATCHER_OBSERVE_PROMETHEUS_ENABLED' => '1', 'WATCHER_OBSERVE_PROMETHEUS_DIR' => $dir, 'WATCHER_OBSERVE_PROMETHEUS_NAME' => 'watcher_orders',
            'WATCHER_OBSERVE_PROMETHEUS_FILE_MODE' => '0640',
        ]))->observe;

        self::assertSame(7, $c->topGroups);
        self::assertSame(['stdout', true], [$c->log['stream'], $c->log['include_text']]);
        self::assertSame(['daily', 2097152, 4, 9, true], [$c->file['rotate'], $c->file['max_bytes'], $c->file['max_files'], $c->file['retention_days'], $c->file['include_text']]);
        self::assertSame(['X-API-Key', 'Token', 50, 8, ['ingest.example.com', 'other.example.com']], [$c->http['auth_header'], $c->http['auth_scheme'], $c->http['batch_size'], $c->http['timeout'], $c->http['allowed_hosts']]);
        self::assertSame('tok-observe-1234', $c->httpToken());
        self::assertSame(['watcher_orders', 0640], [$c->prometheus['name'], $c->prometheus['file_mode']]);
    }
}
