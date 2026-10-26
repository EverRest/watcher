<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\MissingEnvironmentVariable;
use Watcher\Config\WatchConfig;

/** A Lumen or Laravel host that keeps no config file of its own: the shipped config/watcher.php, driven by environment variables only. */
final class EnvOnlyConfigTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const CLEARED = [
        'WATCHER_DIGEST_DRIVER', 'WATCHER_DIGEST_WEBHOOK', 'WATCHER_DIGEST_BOT_TOKEN', 'WATCHER_DIGEST_CHAT_ID', 'WATCHER_DIGEST_THREAD_ID',
        'WATCHER_NOTICE_DRIVER', 'WATCHER_NOTICE_WEBHOOK', 'WATCHER_NOTICE_BOT_TOKEN', 'WATCHER_NOTICE_CHAT_ID', 'WATCHER_NOTICE_THREAD_ID',
        'WATCHER_SLACK_WEBHOOK', 'WATCHER_STREAM_TAGS', 'WATCHER_ENABLED', 'WATCHER_TRIAGE_ENABLED', 'WATCHER_RUN_DEADLINE',
        'WATCHER_PRICE_TRIAGE_IN', 'WATCHER_PRICE_TRIAGE_OUT', 'WATCHER_PRICE_ANALYZE_IN', 'WATCHER_PRICE_ANALYZE_OUT',
        'WATCHER_PRICE_ESCALATE_IN', 'WATCHER_PRICE_ESCALATE_OUT',
        'WATCHER_MODEL_TRIAGE', 'WATCHER_MODEL_ANALYZE', 'WATCHER_MODEL_ESCALATE', 'WATCHER_SERVICE', 'WATCHER_RUN_AT',
        'WATCHER_LOKI_URL', 'WATCHER_LOKI_TOKEN', 'WATCHER_ANTHROPIC_KEY', 'WATCHER_STATE', 'WATCHER_STATE_PATH', 'WATCHER_REPO_URL',
    ];

    private const BASE = [
        'WATCHER_SERVICE' => 'm-seq', 'WATCHER_RUN_AT' => '06:00', 'WATCHER_LOKI_URL' => 'https://loki.example.invalid', 'WATCHER_LOKI_TOKEN' => 't',
        'WATCHER_ANTHROPIC_KEY' => 'k', 'WATCHER_STATE' => 'file', 'WATCHER_STATE_PATH' => '/tmp/w',
        'WATCHER_REPO_URL' => 'https://git.example.com/g/p',
        'WATCHER_MODEL_TRIAGE' => 'haiku', 'WATCHER_MODEL_ANALYZE' => 'haiku', 'WATCHER_MODEL_ESCALATE' => 'sonnet',
        'WATCHER_PRICE_TRIAGE_IN' => '0.1', 'WATCHER_PRICE_TRIAGE_OUT' => '0.5', 'WATCHER_PRICE_ANALYZE_IN' => '0.1', 'WATCHER_PRICE_ANALYZE_OUT' => '0.5',
        'WATCHER_PRICE_ESCALATE_IN' => '2', 'WATCHER_PRICE_ESCALATE_OUT' => '10',
    ];

    /** @var array<string, string|false> */
    private array $before = [];

    protected function setUp(): void
    {
        if (!function_exists('env')) {
            self::markTestSkipped('env() in illuminate/support needs phpoption/phpoption, which is not installed.');
        }
        foreach (self::CLEARED as $name) {
            $this->before[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    /**
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private static function load(array $env): array
    {
        foreach ($env as $name => $value) {
            putenv("$name=$value");
        }
        /** @var array<string, mixed> $config */
        $config = require self::ROOT . '/config/watcher.php';

        return $config;
    }

    public function testTelegramWithTopicsNeedsNoConfigFile(): void
    {
        $c = WatchConfig::fromArray(self::load(self::BASE + [
            'WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_CHAT_ID' => '-1004423655078', 'WATCHER_DIGEST_THREAD_ID' => '4',
            'WATCHER_NOTICE_THREAD_ID' => '2', 'WATCHER_STREAM_TAGS' => 'app=m-seq,env=test11',
        ]));

        self::assertSame('telegram', $c->destination('digest')->driver());
        self::assertSame(4, $c->destination('digest')->threadId());
        self::assertSame('telegram', $c->destination('notice')->driver());
        self::assertSame(2, $c->destination('notice')->threadId());
        self::assertSame(['app' => 'm-seq', 'env' => 'test11'], $c->source['stream_tags']);
        self::assertSame([0.1, 0.5, 0.0, 0.0], $c->analysis['prices']['haiku']);
        self::assertSame([2.0, 10.0, 0.0, 0.0], $c->analysis['prices']['sonnet']);
    }

    public function testSlackFromTheOldVariableIsUnchanged(): void
    {
        $c = WatchConfig::fromArray(self::load(self::BASE + ['WATCHER_SLACK_WEBHOOK' => 'https://hooks.example/x']));

        self::assertSame('slack', $c->destination('digest')->driver());
        self::assertSame('slack', $c->destination('notice')->driver(), 'no notice variable: the notice uses the digest destination');
    }

    public function testLoadingTheFileNeverThrowsWhateverIsMissing(): void
    {
        $config = self::load([]); // not one variable set: a host application must still boot

        self::assertNotSame([], $config['env_problems']);
    }

    public function testAMissingTokenIsATypedExceptionWhenTheRunStarts(): void
    {
        $this->expectException(MissingEnvironmentVariable::class);
        $this->expectExceptionMessage('WATCHER_DIGEST_CHAT_ID is not set');

        WatchConfig::fromArray(self::load(self::BASE + ['WATCHER_DIGEST_DRIVER' => 'telegram', 'WATCHER_DIGEST_BOT_TOKEN' => 'tok']));
    }

    public function testNothingSetNamesBothWaysToConfigureTheDigest(): void
    {
        try {
            WatchConfig::fromArray(self::load(self::BASE));
            self::fail('expected an exception');
        } catch (MissingEnvironmentVariable $e) {
            self::assertStringContainsString('WATCHER_DIGEST_BOT_TOKEN', $e->getMessage());
            self::assertStringContainsString('WATCHER_DIGEST_WEBHOOK', $e->getMessage());
        }
    }

    public function testAPriceConflictIsReportedWhenTheRunStarts(): void
    {
        try {
            WatchConfig::fromArray(self::load(['WATCHER_PRICE_ANALYZE_IN' => '9'] + self::BASE + ['WATCHER_SLACK_WEBHOOK' => 'https://hooks.example/x']));
            self::fail('expected an exception');
        } catch (\Watcher\Config\InvalidConfig $e) {
            self::assertStringContainsString('WATCHER_PRICE_ANALYZE_IN', $e->getMessage());
        }
    }

    public function testTheSwitchedOffPackageIgnoresBrokenVariables(): void
    {
        $config = self::load(self::BASE + ['WATCHER_DIGEST_THREAD_ID' => 'oops']);

        self::assertTrue(WatchConfig::masterSwitchOff($config), 'the kill switch is read before any validation, so a half-filled environment cannot stop it');
    }
    public function testAHostThatSetsItsOwnDigestIsLeftAlone(): void
    {
        $config = self::load(self::BASE); // no digest variable at all
        $config['notify']['digest'] = ['driver' => 'telegram', 'bot_token' => 'own', 'chat_id' => '-1']; // the host's own config/watcher.php, laid over the shipped one

        $c = WatchConfig::fromArray($config);

        self::assertSame('telegram', $c->destination('digest')->driver());
    }

    public function testABrokenFlagDoesNotThrowWhileLoadingButIsReportedWhenTheRunStarts(): void
    {
        $config = self::load(self::BASE + ['WATCHER_SLACK_WEBHOOK' => 'https://hooks.example/x', 'WATCHER_TRIAGE_ENABLED' => 'maybe-secret']);

        try {
            WatchConfig::fromArray($config);
            self::fail('expected an exception');
        } catch (\Watcher\Config\InvalidConfig $e) {
            self::assertStringContainsString('WATCHER_TRIAGE_ENABLED', $e->getMessage());
            self::assertStringNotContainsString('maybe-secret', $e->getMessage());
        }
    }

    public function testABrokenMasterSwitchFailsSafeToOff(): void
    {
        $config = self::load(self::BASE + ['WATCHER_ENABLED' => 'maybe']);

        self::assertTrue(WatchConfig::masterSwitchOff($config));
    }
}
