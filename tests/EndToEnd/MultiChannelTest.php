<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;

/** Digest and notices on different channels (spec 011, US3). */
final class MultiChannelTest extends TestCase
{
    private const TOKEN = '123456:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789';
    private const HOOK = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-123';

    private static function source(int $n = 5, Level $level = Level::Error): FakeLogSource
    {
        $e = Harness::events($level, 'Alpha failed in a unique way', $n, 'Alpha');

        return new FakeLogSource(Harness::totalsOf($e), $e);
    }

    /** @return array<string, mixed> */
    private static function telegram(string $chat = '-100111'): array
    {
        return ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => $chat];
    }

    private static function baselined(Harness $h): void
    {
        $h->watcher(self::source(5, Level::Warning))->run(); // a Warning baseline: an Error first run would post (FR-035)
        $h->nextWindow();
    }

    public function testDigestFailureAnnouncedOnOtherChannel(): void
    {
        $h = new Harness(['notify' => ['digest' => self::telegram(), 'notice' => ['driver' => 'discord', 'webhook' => self::HOOK]]]);
        self::baselined($h);
        $sent = count($h->notifier->sent);
        $h->notifier->failOnce(new DeliveryFailed('telegram delivery failed: HTTP 403'));

        self::assertSame(RunOutcome::Failed, $h->watcher(self::source(9))->run()->outcome);

        self::assertCount($sent + 1, $h->notifier->sent);
        $last = $h->notifier->sent[$sent];
        self::assertSame('discord', $last['to']->driver());
        self::assertStringContainsString('digest delivery failed: HTTP 403', $last['message']->text);
    }

    public function testSameDestinationNoAnnouncement(): void
    {
        $h = new Harness(['notify' => ['digest' => self::telegram(), 'notice' => self::telegram()]]);
        self::baselined($h);
        $h->notifier->failOnce(new DeliveryFailed('telegram delivery failed: HTTP 403'));
        $attempts = $h->notifier->attempts;

        $h->watcher(self::source(9))->run();

        self::assertSame($attempts + 1, $h->notifier->attempts);
    }

    public function testDifferentChatsAreDifferentDestinations(): void
    {
        $h = new Harness(['notify' => ['digest' => self::telegram('-100111'), 'notice' => self::telegram('-100222')]]);
        self::baselined($h);
        $h->notifier->failOnce(new DeliveryFailed('telegram delivery failed: HTTP 403'));
        $attempts = $h->notifier->attempts;

        $h->watcher(self::source(9))->run();

        self::assertSame($attempts + 2, $h->notifier->attempts);
        self::assertSame('-100222', $h->notifier->sent[count($h->notifier->sent) - 1]['to']->chatId());
    }

    public function testNoticesGoToTheNoticeDestination(): void
    {
        $h = new Harness(['notify' => ['digest' => self::telegram(), 'notice' => ['driver' => 'discord', 'webhook' => self::HOOK]]]);

        $h->watcher(self::source(5, Level::Warning))->run(); // baseline notice

        self::assertSame('discord', $h->notifier->sent[0]['to']->driver());
    }

    public function testSecretsOfEveryDriverAreMaskedInAFailureReason(): void
    {
        $h = new Harness(['notify' => ['digest' => self::telegram(), 'notice' => ['driver' => 'discord', 'webhook' => self::HOOK]]]);
        self::baselined($h);
        $h->analyzer->failure = new \Watcher\Analyze\AnalyzerFailed('boom ' . self::TOKEN . ' and ' . self::HOOK);

        $h->watcher(self::source(9))->run();

        $text = implode("\n", $h->sentTexts());
        self::assertStringNotContainsString(self::TOKEN, $text);
        self::assertStringNotContainsString('abcDEF_ghi-123', $text);
        self::assertStringContainsString('analysis failed', $text);
    }
}
