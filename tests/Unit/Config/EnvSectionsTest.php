<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\EnvSections;

/** The destination, price and tag variables of the publishable config. Nothing here throws: a problem is recorded and reported when the run starts. */
final class EnvSectionsTest extends TestCase
{
    private const NAMES = [
        'WATCHER_DIGEST_DRIVER', 'WATCHER_DIGEST_WEBHOOK', 'WATCHER_DIGEST_BOT_TOKEN', 'WATCHER_DIGEST_CHAT_ID', 'WATCHER_DIGEST_THREAD_ID',
        'WATCHER_NOTICE_DRIVER', 'WATCHER_NOTICE_WEBHOOK', 'WATCHER_NOTICE_BOT_TOKEN', 'WATCHER_NOTICE_CHAT_ID', 'WATCHER_NOTICE_THREAD_ID',
        'WATCHER_SLACK_WEBHOOK', 'WATCHER_STREAM_TAGS',
        'WATCHER_PRICE_TRIAGE_IN', 'WATCHER_PRICE_TRIAGE_OUT', 'WATCHER_PRICE_TRIAGE_CACHE_READ', 'WATCHER_PRICE_TRIAGE_CACHE_WRITE',
        'WATCHER_PRICE_ANALYZE_IN', 'WATCHER_PRICE_ANALYZE_OUT', 'WATCHER_PRICE_ESCALATE_IN', 'WATCHER_PRICE_ESCALATE_OUT',
    ];

    /** @var array<string, string|false> */
    private array $before = [];

    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
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

    /** @param array<string, string> $env */
    private static function set(array $env): void
    {
        foreach ($env as $name => $value) {
            putenv("$name=$value");
        }
    }

    // ---- digest

    public function testTheOldSlackVariableStillWorks(): void
    {
        self::set(['WATCHER_SLACK_WEBHOOK' => 'https://hooks.example/x']);
        $e = new EnvSections();

        self::assertSame(['driver' => 'slack', 'webhook' => 'https://hooks.example/x'], $e->destination('DIGEST'));
        self::assertSame([], $e->problems());
    }

    public function testTheDigestWebhookWinsOverTheOldVariable(): void
    {
        self::set(['WATCHER_SLACK_WEBHOOK' => 'https://old.example/x', 'WATCHER_DIGEST_WEBHOOK' => 'https://new.example/x']);

        self::assertSame(['driver' => 'slack', 'webhook' => 'https://new.example/x'], (new EnvSections())->destination('DIGEST'));
    }

    public function testABotTokenSelectsTelegram(): void
    {
        self::set(['WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_CHAT_ID' => '-1004423655078', 'WATCHER_DIGEST_THREAD_ID' => '4']);
        $e = new EnvSections();

        self::assertSame(['driver' => 'telegram', 'bot_token' => 'tok', 'chat_id' => '-1004423655078', 'thread_id' => 4], $e->destination('DIGEST'));
        self::assertSame([], $e->problems());
    }

    public function testTheThreadIsOptional(): void
    {
        self::set(['WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_CHAT_ID' => '-100']);

        self::assertSame(['driver' => 'telegram', 'bot_token' => 'tok', 'chat_id' => '-100'], (new EnvSections())->destination('DIGEST'));
    }

    public function testAnExplicitDriverWinsAndForeignKeysAreNotEmitted(): void
    {
        self::set(['WATCHER_DIGEST_DRIVER' => 'slack', 'WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_WEBHOOK' => 'https://h.example/x']);

        self::assertSame(['driver' => 'slack', 'webhook' => 'https://h.example/x'], (new EnvSections())->destination('DIGEST'));
    }

    public function testDiscordAndNoneAreSelectable(): void
    {
        self::set(['WATCHER_DIGEST_DRIVER' => 'discord', 'WATCHER_DIGEST_WEBHOOK' => 'https://discord.example/x']);
        self::assertSame(['driver' => 'discord', 'webhook' => 'https://discord.example/x'], (new EnvSections())->destination('DIGEST'));

        self::set(['WATCHER_DIGEST_DRIVER' => 'none']);
        $e = new EnvSections();
        self::assertSame(['driver' => 'none'], $e->destination('DIGEST'));
        self::assertSame([], $e->problems());
    }

    public function testNothingSetRecordsAMissingVariableThatNamesBothWays(): void
    {
        $e = new EnvSections();
        $e->destination('DIGEST');

        $problems = $e->problems();
        self::assertCount(1, $problems);
        self::assertSame('missing', $problems[0]['kind']);
        self::assertStringContainsString('WATCHER_DIGEST_BOT_TOKEN', $problems[0]['message']);
        self::assertStringContainsString('WATCHER_DIGEST_WEBHOOK', $problems[0]['message']);
    }

    public function testTelegramWithoutAChatNamesTheVariable(): void
    {
        self::set(['WATCHER_DIGEST_DRIVER' => 'telegram', 'WATCHER_DIGEST_BOT_TOKEN' => 'tok']);
        $e = new EnvSections();
        $e->destination('DIGEST');

        self::assertSame('missing', $e->problems()[0]['kind']);
        self::assertStringContainsString('WATCHER_DIGEST_CHAT_ID', $e->problems()[0]['message']);
        self::assertStringContainsString('notify.digest.chat_id', $e->problems()[0]['message']);
    }

    public function testTelegramWithoutATokenNamesTheVariable(): void
    {
        self::set(['WATCHER_DIGEST_DRIVER' => 'telegram', 'WATCHER_DIGEST_CHAT_ID' => '-100']);
        $e = new EnvSections();
        $e->destination('DIGEST');

        self::assertStringContainsString('WATCHER_DIGEST_BOT_TOKEN is not set', $e->problems()[0]['message']);
    }

    public function testABadThreadIsReportedByNameNeverByValue(): void
    {
        self::set(['WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_CHAT_ID' => '-100', 'WATCHER_DIGEST_THREAD_ID' => 'abc-secret']);
        $e = new EnvSections();
        $destination = $e->destination('DIGEST');

        self::assertIsArray($destination);
        self::assertArrayNotHasKey('thread_id', $destination);
        self::assertSame('invalid', $e->problems()[0]['kind']);
        self::assertStringContainsString('WATCHER_DIGEST_THREAD_ID', $e->problems()[0]['message']);
        self::assertStringNotContainsString('abc-secret', $e->problems()[0]['message']);
    }

    public function testAnUnknownDriverIsReportedByNameNeverByValue(): void
    {
        self::set(['WATCHER_DIGEST_DRIVER' => 'carrier-pigeon']);
        $e = new EnvSections();
        $e->destination('DIGEST');

        self::assertSame('invalid', $e->problems()[0]['kind']);
        self::assertStringContainsString('WATCHER_DIGEST_DRIVER', $e->problems()[0]['message']);
        self::assertStringNotContainsString('carrier-pigeon', $e->problems()[0]['message']);
    }

    // ---- notice

    public function testNoticeIsNullWhenNothingIsSet(): void
    {
        self::set(['WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_CHAT_ID' => '-100']);

        self::assertNull((new EnvSections())->destination('NOTICE'));
    }

    public function testNoticeThreadReusesTheDigestTokenAndChat(): void
    {
        self::set(['WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_CHAT_ID' => '-100', 'WATCHER_DIGEST_THREAD_ID' => '4', 'WATCHER_NOTICE_THREAD_ID' => '2']);
        $e = new EnvSections();

        self::assertSame(['driver' => 'telegram', 'bot_token' => 'tok', 'chat_id' => '-100', 'thread_id' => 2], $e->destination('NOTICE'));
        self::assertSame([], $e->problems());
    }

    public function testNoticeCanBeAnotherDestination(): void
    {
        self::set(['WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_DIGEST_CHAT_ID' => '-100', 'WATCHER_NOTICE_DRIVER' => 'slack', 'WATCHER_NOTICE_WEBHOOK' => 'https://hooks.example/n']);

        self::assertSame(['driver' => 'slack', 'webhook' => 'https://hooks.example/n'], (new EnvSections())->destination('NOTICE'));
    }

    public function testNoticeThreadNeedsATelegramDigest(): void
    {
        self::set(['WATCHER_SLACK_WEBHOOK' => 'https://hooks.example/x', 'WATCHER_NOTICE_THREAD_ID' => '2']);
        $e = new EnvSections();
        $e->destination('DIGEST');
        $e->destination('NOTICE');

        self::assertSame('invalid', $e->problems()[0]['kind']);
        self::assertStringContainsString('WATCHER_NOTICE_THREAD_ID', $e->problems()[0]['message']);
    }

    public function testADigestProblemIsRecordedOnceEvenWhenNoticeInheritsIt(): void
    {
        self::set(['WATCHER_DIGEST_BOT_TOKEN' => 'tok', 'WATCHER_NOTICE_THREAD_ID' => '2']); // no chat
        $e = new EnvSections();
        $e->destination('DIGEST');
        $e->destination('NOTICE');

        self::assertCount(1, $e->problems());
        self::assertStringContainsString('WATCHER_DIGEST_CHAT_ID', $e->problems()[0]['message']);
    }

    // ---- prices

    public function testPricesAreBuiltFromTheTierVariables(): void
    {
        self::set([
            'WATCHER_PRICE_TRIAGE_IN' => '0.10', 'WATCHER_PRICE_TRIAGE_OUT' => '0.50', 'WATCHER_PRICE_TRIAGE_CACHE_READ' => '0.01',
            'WATCHER_PRICE_ANALYZE_IN' => '2', 'WATCHER_PRICE_ANALYZE_OUT' => '10',
            'WATCHER_PRICE_ESCALATE_IN' => '4', 'WATCHER_PRICE_ESCALATE_OUT' => '20',
        ]);
        $e = new EnvSections();

        self::assertSame(
            ['haiku' => [0.10, 0.50, 0.01, 0.0], 'sonnet' => [2.0, 10.0, 0.0, 0.0], 'opus' => [4.0, 20.0, 0.0, 0.0]],
            $e->prices(['triage' => 'haiku', 'analyze' => 'sonnet', 'escalate' => 'opus']),
        );
        self::assertSame([], $e->problems());
    }

    public function testThereIsNoDefaultPrice(): void
    {
        $e = new EnvSections();

        self::assertSame([], $e->prices(['triage' => 'haiku', 'analyze' => null, 'escalate' => null]));
        $messages = implode(' ', array_column($e->problems(), 'message'));
        self::assertStringContainsString('WATCHER_PRICE_TRIAGE_IN', $messages);
        self::assertStringContainsString('WATCHER_PRICE_TRIAGE_OUT', $messages);
        self::assertSame('missing', $e->problems()[0]['kind']);
    }

    public function testTheSameModelWithTheSamePriceIsOneEntry(): void
    {
        self::set(['WATCHER_PRICE_TRIAGE_IN' => '0.1', 'WATCHER_PRICE_TRIAGE_OUT' => '0.5', 'WATCHER_PRICE_ANALYZE_IN' => '0.1', 'WATCHER_PRICE_ANALYZE_OUT' => '0.5']);
        $e = new EnvSections();

        self::assertSame(['haiku' => [0.1, 0.5, 0.0, 0.0]], $e->prices(['triage' => 'haiku', 'analyze' => 'haiku', 'escalate' => null]));
        self::assertSame([], $e->problems());
    }

    public function testTheSameModelWithDifferentPricesIsAConflict(): void
    {
        self::set(['WATCHER_PRICE_TRIAGE_IN' => '0.1', 'WATCHER_PRICE_TRIAGE_OUT' => '0.5', 'WATCHER_PRICE_ANALYZE_IN' => '2', 'WATCHER_PRICE_ANALYZE_OUT' => '10']);
        $e = new EnvSections();
        $e->prices(['triage' => 'haiku', 'analyze' => 'haiku', 'escalate' => null]);

        self::assertSame('invalid', $e->problems()[0]['kind']);
        self::assertStringContainsString('WATCHER_PRICE_ANALYZE_IN', $e->problems()[0]['message']);
        self::assertStringContainsString('WATCHER_PRICE_TRIAGE_IN', $e->problems()[0]['message']);
    }

    public function testANonNumericPriceIsReportedByNameNeverByValue(): void
    {
        self::set(['WATCHER_PRICE_TRIAGE_IN' => 'cheap-secret', 'WATCHER_PRICE_TRIAGE_OUT' => '0.5']);
        $e = new EnvSections();
        $e->prices(['triage' => 'haiku', 'analyze' => null, 'escalate' => null]);

        self::assertSame('invalid', $e->problems()[0]['kind']);
        self::assertStringContainsString('WATCHER_PRICE_TRIAGE_IN', $e->problems()[0]['message']);
        self::assertStringNotContainsString('cheap-secret', $e->problems()[0]['message']);
    }

    // ---- stream tags

    public function testStreamTagsBecomeAMap(): void
    {
        self::set(['WATCHER_STREAM_TAGS' => 'app=m-seq, env=test11']);

        self::assertSame(['app' => 'm-seq', 'env' => 'test11'], (new EnvSections())->streamTags());
    }

    public function testNoStreamTagsIsAnEmptyMap(): void
    {
        self::assertSame([], (new EnvSections())->streamTags());
    }

    public function testABrokenStreamTagIsReportedByNameNeverByValue(): void
    {
        self::set(['WATCHER_STREAM_TAGS' => 'app=m-seq,oops-secret']);
        $e = new EnvSections();
        $tags = $e->streamTags();

        self::assertSame([], $tags);
        self::assertSame('invalid', $e->problems()[0]['kind']);
        self::assertStringContainsString('WATCHER_STREAM_TAGS', $e->problems()[0]['message']);
        self::assertStringNotContainsString('oops-secret', $e->problems()[0]['message']);
    }
}
