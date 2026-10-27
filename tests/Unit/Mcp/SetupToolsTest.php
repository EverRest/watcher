<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Mcp\ConfigGenerator;
use Watcher\Mcp\DocsIndex;
use Watcher\Mcp\InvalidParams;
use Watcher\Mcp\SetupCatalog;
use Watcher\Mcp\SetupPlanner;
use Watcher\OutcomeRegistry;

final class SetupToolsTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function combos(): iterable
    {
        foreach (['laravel', 'plain_php', 'kubernetes_cronjob'] as $h) {
            foreach (['loki', 'datadog'] as $s) {
                foreach (['slack', 'telegram', 'discord'] as $n) {
                    foreach (['file', 'pdo', 'redis'] as $st) {
                        yield "$h/$s/$n/$st" => [$h, $s, $n, $st];
                    }
                }
            }
        }
    }

    public function testThereAreFiftyFourCombinations(): void
    {
        self::assertCount(54, iterator_to_array(self::combos()));
    }

    /** @dataProvider combos */
    public function testPlanStructure(string $h, string $s, string $n, string $st): void
    {
        $p = (new SetupPlanner())->plan($h, $s, $n, $st);
        self::assertSame(['collect', 'env', 'config', 'check', 'estimate', 'baseline', 'second_run', 'idempotency', 'status', 'enable', 'rollback'], array_column($p['steps'], 'id'));
        $docs = new DocsIndex(__DIR__ . '/../../..');
        foreach ($p['steps'] as $i => $step) {
            self::assertSame($i + 1, $step['n']);
            foreach (['collect', 'where_to_find', 'verify', 'rollback', 'docs'] as $f) {
                self::assertArrayHasKey($f, $step);
            }
            self::assertNotNull(OutcomeRegistry::explain($step['verify']['expect']));
            self::assertStringContainsString($h === 'laravel' ? 'php artisan watcher:' : 'vendor/bin/watcher', $step['verify']['command']);
            foreach ($step['env_vars'] as $v) {
                self::assertMatchesRegularExpression('/^WATCHER_[A-Z0-9_]+$/', $v);
            }
            foreach ($step['docs'] as $d) {
                self::assertContains($d['file'], array_keys(\Watcher\Mcp\DocsAllowlist::FILES));
                $anchors = [];
                foreach (explode("\n", (string) file_get_contents(__DIR__ . '/../../../' . $d['file'])) as $l) {
                    if (preg_match('/^#{1,4} +(.+?)\s*#*$/', $l, $m) === 1) {
                        $anchors[] = DocsIndex::slug($m[1]);
                    }
                }
                self::assertContains($d['anchor'], $anchors, $d['file']);
            }
        }
        foreach ($p['env_vars_all'] as $v) {
            self::assertMatchesRegularExpression('/^WATCHER_[A-Z0-9_]{1,56}$/', $v['name']);
            self::assertArrayNotHasKey('value', $v);
        }
        $text = json_encode($p) ?: '';
        self::assertDoesNotMatchRegularExpression('~://[^/\s]*@|claude-|\$\d~', $text);
        self::assertSame($p, (new SetupPlanner())->plan($h, $s, $n, $st));
        unset($docs);
    }

    public function testDriverSpecificHelp(): void
    {
        $t = static fn (string ...$a): string => json_encode((new SetupPlanner())->plan(...$a), JSON_UNESCAPED_SLASHES) ?: '';
        self::assertStringContainsString('label browser', $t('plain_php', 'loki', 'slack', 'file'));
        self::assertStringContainsString('Viewer role', $t('plain_php', 'loki', 'slack', 'file'));
        self::assertStringContainsString('datasources/proxy/uid', $t('plain_php', 'loki', 'slack', 'file'));
        self::assertStringContainsString('logs_read_data', $t('plain_php', 'datadog', 'slack', 'file'));
        self::assertStringContainsString('database index', $t('plain_php', 'loki', 'slack', 'redis'));
        self::assertStringContainsString('Single runner only', $t('plain_php', 'loki', 'slack', 'file'));
        self::assertStringContainsString('createTableSql', $t('plain_php', 'loki', 'slack', 'pdo'));
    }

    public function testFeaturesAppendAfterStatus(): void
    {
        $p = (new SetupPlanner())->plan('plain_php', 'loki', 'slack', 'file', ['links', 'fleet']);
        self::assertSame(['status', 'features.links', 'features.fleet', 'enable'], array_slice(array_column($p['steps'], 'id'), 8, 4));
    }

    public function testFirstDay(): void
    {
        $d = (new SetupPlanner())->firstDay('laravel');
        self::assertSame(['check', 'estimate', 'baseline', 'second_run', 'idempotency', 'status'], array_column($d['steps'], 'id'));
        self::assertSame('baselined', $d['steps'][2]['expect']);
        self::assertSame('skipped_done', $d['steps'][4]['expect']);
        self::assertSame('php artisan watcher:run --dry-run --estimate', $d['steps'][1]['command']);
        self::assertSame('vendor/bin/watcher check --config=<config>', (new SetupPlanner())->firstDay('plain_php')['steps'][0]['command']);
    }

    /** @return array<string, mixed> */
    private function answers(string $h, string $s, string $n, string $st): array
    {
        return ['service' => 'orders-api', 'environment' => 'prod', 'run_at' => '06:00', 'timezone' => 'Europe/Kyiv', 'host' => $h, 'source' => $s, 'notifier' => $n, 'state' => $st, 'stream_tags' => ['env' => 'prod', 'app' => 'orders-api'], 'level_mode' => 'label', 'daily_budget_usd' => 0.25];
    }

    /** @dataProvider combos */
    public function testGeneratedSnippetIsValidPhpAndLoadsThroughWatchConfig(string $h, string $s, string $n, string $st): void
    {
        /** @var array{service: string, run_at: string, host: string, source: string, notifier: string, state: string} $a */
        $a = $this->answers($h, $s, $n, $st);
        $r = (new ConfigGenerator())->generate($a);
        self::assertNotSame([], token_get_all($r['php_snippet'], TOKEN_PARSE));
        self::assertSame($r, (new ConfigGenerator())->generate($a), 'deterministic');

        $dir = sys_get_temp_dir() . '/mcp-gen-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/c.php', str_replace('[null, null, null, null]', '[1.0, 5.0, 0.1, 1.25]', $r['php_snippet']));
        $dummy = ['@channel', 'x', '1.5', 'https://hooks.example.test/t'];
        $set = [];
        foreach ($r['env_vars'] as $v) {
            self::assertArrayNotHasKey('value', $v);
            $val = match (true) {
                str_contains($v['name'], 'WEBHOOK') => $n === 'discord' ? 'https://discord.com/api/webhooks/123456/abcdef' : 'https://hooks.slack.com/services/T0/B0/xxxx',
                str_contains($v['name'], 'TG_CHAT') => '@channel',
                str_contains($v['name'], 'PRICE') => '1.5',
                str_contains($v['name'], 'LOKI_URL') => 'https://loki.example.test',
                str_contains($v['name'], 'STATE_PATH') => $dir . '/state',
                str_contains($v['name'], 'DB_DSN') => 'sqlite::memory:',
                str_contains($v['name'], 'DD_SITE') => 'datadoghq.eu',
                str_contains($v['name'], 'ENABLED') => '1',
                str_contains($v['name'], 'REDIS_PORT') => '6379',
                str_contains($v['name'], 'REDIS_DATABASE') => '1',
                default => 'dummy-' . strtolower($v['name']),
            };
            putenv($v['name'] . '=' . $val);
            $set[] = $v['name'];
        }
        try {
            $values = (static fn (string $p): mixed => require $p)($dir . '/c.php');
            self::assertIsArray($values);
            $c = WatchConfig::fromArray($values);
            self::assertSame('orders-api', $c->service);
            self::assertTrue($c->enabled);
        } finally {
            foreach ($set as $name) {
                putenv($name);
            }
            exec('rm -rf ' . escapeshellarg($dir));
        }
        unset($dummy);
    }

    public function testSnippetRules(): void
    {
        $r = (new ConfigGenerator())->generate($this->answers('plain_php', 'loki', 'slack', 'redis')); // @phpstan-ignore argument.type
        $code = $r['php_snippet']; // prices stay unset here: the rule is that the snippet holds no price literal
        self::assertStringContainsString("Env::bool('WATCHER_ENABLED', false)", $code);
        self::assertStringContainsString("Env::string('WATCHER_LOKI_TOKEN')", $code);
        self::assertStringNotContainsString('claude-', $code);
        self::assertDoesNotMatchRegularExpression('/[0-9]\.[0-9]+, [0-9]/', str_replace(['0.25', '0.0'], '', $code));
        $laravel = (new ConfigGenerator())->generate($this->answers('laravel', 'datadog', 'telegram', 'file')); // @phpstan-ignore argument.type
        self::assertStringContainsString("Env::string('WATCHER_DD_API_KEY')", $laravel['php_snippet']);
        $file = (new ConfigGenerator())->generate($this->answers('plain_php', 'loki', 'slack', 'file')); // @phpstan-ignore argument.type
        self::assertStringContainsString('single runner', implode(' ', $file['notes']));
        self::assertLessThanOrEqual(8000, strlen($code));
        self::assertSame(array_values(array_unique(array_column($r['env_vars'], 'name'))), array_column($r['env_vars'], 'name'));
    }

    public function testEnvNameOverridesAndBadInputs(): void
    {
        $a = $this->answers('plain_php', 'loki', 'slack', 'file');
        $a['env_names'] = ['loki_token' => 'WATCHER_ORDERS_LOKI_TOKEN'];
        $r = (new ConfigGenerator())->generate($a); // @phpstan-ignore argument.type
        self::assertStringContainsString("Env::string('WATCHER_ORDERS_LOKI_TOKEN')", $r['php_snippet']);
        self::assertContains('WATCHER_ORDERS_LOKI_TOKEN', array_column($r['env_vars'], 'name'));
        $a['env_names'] = ['nope' => 'WATCHER_X'];
        $this->expectException(InvalidParams::class);
        (new ConfigGenerator())->generate($a); // @phpstan-ignore argument.type
    }

    public function testAnUnknownTimezoneIsRefused(): void
    {
        $a = $this->answers('plain_php', 'loki', 'slack', 'file');
        $a['timezone'] = 'Mars/Olympus';
        $this->expectException(InvalidParams::class);
        (new ConfigGenerator())->generate($a); // @phpstan-ignore argument.type
    }

    public function testCatalogHasNoValuesAndOnlyWatcherNames(): void
    {
        foreach (SetupCatalog::ENV as $v) {
            self::assertMatchesRegularExpression('/^WATCHER_[A-Z0-9_]{1,56}$/', $v['name']);
        }
    }
}
