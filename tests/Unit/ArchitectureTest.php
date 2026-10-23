<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;

/** Source-text rules that keep the package generic, read-only and framework-free (constitution II, VI). */
final class ArchitectureTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> absolute paths of files under $dir (relative to the package root) with the given extension */
    private static function files(string $dir, string $ext = 'php'): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === $ext) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param list<string> $files
     * @return list<string> "file:line: text" for each line matching $pattern
     */
    private static function grep(array $files, string $pattern): array
    {
        $hits = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = substr($file, strlen(self::ROOT) + 1) . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        return $hits;
    }

    /** @return list<string> */
    private static function shipped(): array
    {
        return [...self::files('src'), ...self::files('config'), self::ROOT . '/README.md'];
    }

    public function testNoModelIdUnderSrcOrConfig(): void
    {
        self::assertSame([], self::grep([...self::files('src'), ...self::files('src', 'md'), ...self::files('src', 'json'), ...self::files('config')], '/claude-/i'));
    }

    public function testNothingConsumerSpecificIsShipped(): void
    {
        $files = array_values(array_filter(self::shipped(), 'is_file'));

        self::assertSame([], self::grep($files, '/orders-api|tenant-a|example|example|core-api|users-api/i'));
    }

    public function testLokiSourceOnlyReadsQueryEndpointsWithGet(): void
    {
        $this->assertReadOnly(self::ROOT . '/src/Source/LokiSource.php', ['GET'], ['/loki/api/v1/query', '/loki/api/v1/query_range']);
    }

    public function testDatadogSourceOnlyPostsToReadEndpoints(): void
    {
        $this->assertReadOnly(self::ROOT . '/src/Source/DatadogSource.php', ['POST'], ['/api/v2/logs/analytics/aggregate', '/api/v2/logs/events/search']);
    }

    public function testEverySourceImplementationIsCovered(): void
    {
        $sources = array_map('basename', glob(self::ROOT . '/src/Source/*Source.php') ?: []);
        sort($sources);

        // LogSource is the contract; a new adapter needs its own read-only rule above.
        self::assertSame(['DatadogSource.php', 'LogSource.php', 'LokiSource.php'], $sources);
    }

    /**
     * @param list<string> $methods
     * @param list<string> $paths
     */
    private function assertReadOnly(string $file, array $methods, array $paths): void
    {
        $code = (string) file_get_contents($file);
        $name = basename($file);

        preg_match_all("/->request\(\s*'([A-Z]+)'/", $code, $m);
        self::assertSame($methods, array_values(array_unique($m[1])), "$name: HTTP methods");
        self::assertSame(0, preg_match('/\b(PUT|PATCH|DELETE|HEAD)\b/', $code), "$name: write verbs");

        preg_match_all("~'(/[a-z]+(?:/[a-z0-9_]+)*/(?:api|v\\d)/[^']*)'~", $code, $p);
        $found = array_values(array_unique($p[1]));
        sort($found);
        $expected = $paths;
        sort($expected);
        self::assertSame($expected, $found, "$name: request paths");
    }

    public function testCoreImportsNothingFromIlluminate(): void
    {
        $core = array_values(array_filter(self::files('src'), static fn (string $f): bool => !str_contains($f, '/src/Laravel/')));

        self::assertSame([], self::grep($core, '/\bIlluminate\\\\/'));
    }

    public function testEveryConcreteClassIsFinal(): void
    {
        $bad = self::grep(self::files('src'), '/^\s*(?:readonly\s+)?class\s+\w+/');

        self::assertSame([], $bad, 'concrete classes must be final (abstract/interface/enum are fine)');
    }

    public function testNoDebugOutputInSrc(): void
    {
        self::assertSame([], self::grep(self::files('src'), '/\b(?:var_dump|print_r|error_log)\s*\(|(?<![\w$>:])dd\s*\(/'));
    }

    public function testExampleConfigValidates(): void
    {
        $env = [
            'WATCHER_LOKI_URL' => 'https://loki.example.com', 'WATCHER_LOKI_TOKEN' => 't',
            'WATCHER_ANTHROPIC_KEY' => 'k', 'WATCHER_SLACK_WEBHOOK' => 'https://hooks.example.com/x',
            'WATCHER_MODEL_TRIAGE' => 'model-a', 'WATCHER_MODEL_ANALYZE' => 'model-b', 'WATCHER_MODEL_ESCALATE' => 'model-c',
            'WATCHER_PRICE_TRIAGE_IN' => '1', 'WATCHER_PRICE_TRIAGE_OUT' => '5',
            'WATCHER_PRICE_ANALYZE_IN' => '3', 'WATCHER_PRICE_ANALYZE_OUT' => '15',
            'WATCHER_PRICE_ESCALATE_IN' => '5', 'WATCHER_PRICE_ESCALATE_OUT' => '25',
            'WATCHER_DB_DSN' => 'sqlite::memory:',
        ];
        $before = [];
        foreach ($env as $k => $v) {
            $before[$k] = getenv($k);
            putenv("$k=$v");
        }
        try {
            $config = require self::ROOT . '/config/watcher.example.php';
            $parsed = WatchConfig::fromArray($config);
        } finally {
            foreach ($before as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
        }

        self::assertSame('orders-api', $parsed->service);
        self::assertSame(['model-a', 'model-b', 'model-c'], array_values($parsed->analysis['models']));
    }

    public function testPublishableConfigUsesOnlyKeysTheLoaderReads(): void
    {
        // config/watcher.php is what Lumen/Laravel hosts publish: an unknown nested key would be silently ignored.
        $text = (string) file_get_contents(self::ROOT . '/config/watcher.php');

        self::assertSame(1, preg_match("/'redis'\\s*=>\\s*\\[(?<body>[^\\]]*)\\]/s", $text, $m), 'state.redis block not found');
        preg_match_all("/'(\\w+)'\\s*=>/", $m['body'] ?? '', $keys);
        self::assertSame([], array_diff($keys[1], ['instance', 'host', 'port', 'password', 'database']), 'state.redis holds a key WatchConfig does not read');

        self::assertSame(1, preg_match("/'pdo'\\s*=>\\s*\\[(?<body>[^\\]]*)\\]/s", $text, $p), 'state.pdo block not found');
        preg_match_all("/'(\\w+)'\\s*=>/", $p['body'] ?? '', $pk);
        self::assertSame([], array_diff($pk[1], ['dsn', 'username', 'password', 'table']), 'state.pdo holds a key WatchConfig does not read');
    }

    public function testSmokeConfigInTheGuideValidates(): void
    {
        $env = [
            'WATCHER_LOKI_URL' => 'https://grafana.example.com/api/datasources/proxy/uid/loki', 'WATCHER_LOKI_TOKEN' => 't',
            'WATCHER_ANTHROPIC_KEY' => 'k', 'WATCHER_SLACK_WEBHOOK' => 'https://hooks.example.com/x',
            'WATCHER_MODEL_TRIAGE' => 'model-a', 'WATCHER_MODEL_ANALYZE' => 'model-b', 'WATCHER_MODEL_ESCALATE' => 'model-c',
            'WATCHER_PRICE_TRIAGE_IN' => '1', 'WATCHER_PRICE_TRIAGE_OUT' => '5',
            'WATCHER_PRICE_ANALYZE_IN' => '3', 'WATCHER_PRICE_ANALYZE_OUT' => '15',
            'WATCHER_PRICE_ESCALATE_IN' => '5', 'WATCHER_PRICE_ESCALATE_OUT' => '25',
        ];
        $before = [];
        foreach ($env as $k => $v) {
            $before[$k] = getenv($k);
            putenv("$k=$v");
        }
        try {
            $parsed = WatchConfig::fromArray(require self::ROOT . '/docs/examples/smoke.config.php');
        } finally {
            foreach ($before as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
        }

        self::assertSame('smoke-service', $parsed->service);
        self::assertSame('file', $parsed->state['driver']);
    }
}
