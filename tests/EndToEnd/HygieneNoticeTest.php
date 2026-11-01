<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Hygiene\Hygiene;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Level;
use Watcher\Source\LogEvent;

/** H1 and H2 of the lane B review: log text must not ping or link in any channel, and a cut notice must not mark what it cut as sent. */
final class HygieneNoticeTest extends TestCase
{
    private const HOSTILE = ['<!channel>', '<@U123>', '<https://evil|x>'];

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function drivers(): iterable
    {
        yield 'slack' => [['driver' => 'slack', 'webhook' => 'https://hooks.slack.com/services/T000/B000/XXXX']];
        yield 'telegram' => [['driver' => 'telegram', 'bot_token' => '123456:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789', 'chat_id' => '-100123']];
        yield 'discord' => [['driver' => 'discord', 'webhook' => 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-123']];
    }

    /** @return list<LogEvent> 40 distinct messages carrying hostile markup in the constant part and in the varying part */
    private static function hostileEvents(): array
    {
        $events = [];
        for ($i = 0; $i < 40; ++$i) {
            $name = chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'orvath';
            for ($k = 0; $k < 3; ++$k) {
                $events[] = HygieneHarness::event(Level::Warning, "user $name said <!channel> hi <@U123> see <https://evil|x> now");
            }
        }

        return $events;
    }

    /** @param array<string, mixed> $notice */
    #[DataProvider('drivers')]
    public function testNoMarkupFromTheLogSurvivesInAnyChannel(array $notice): void
    {
        $h = new HygieneHarness(['notify' => ['notice' => $notice]]);
        $sent = [];
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 40, new Response(200, [], '{"ok":true}'))));
        $stack->push(Middleware::mapRequest(static function ($r) use (&$sent) {
            $sent[] = (string) $r->getBody();

            return $r;
        }));
        $hygiene = new Hygiene(WatchConfig::fromArray($h->config()), HygieneHarness::source(self::hostileEvents()), $h->state, Factory::notifier(new Client(['handler' => $stack]), 900), $h->clock);

        $result = $hygiene->run(new HygieneOptions(reports: ['privacy', 'shape'], notify: true));

        self::assertSame(0, $result->exitCode());
        self::assertNotSame([], $sent, 'a notice was posted, or this proves nothing');
        $all = '';
        foreach ($sent as $body) {
            $all .= json_encode(json_decode($body, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        self::assertStringContainsString('now', $all, 'the template itself is reported (its common words)');
        foreach (self::HOSTILE as $needle) {
            self::assertStringNotContainsString($needle, $all, $needle);
            self::assertStringNotContainsString($needle, $result->reports['privacy']->text(true));
        }
    }

    public function testAConfiguredSecretNeverLeavesInANotice(): void
    {
        $h = new HygieneHarness(['analysis' => ['api_keys' => ['gemini' => 'plainsecretvalue1']]]);
        $events = [];
        for ($i = 0; $i < 3; ++$i) {
            $events[] = HygieneHarness::event(Level::Info, 'sent to a' . $i . '@example.org using plainsecretvalue1', ['file' => '/var/www/html/app/Mail.php', 'line' => 1]);
        }

        $h->hygiene(HygieneHarness::source($events))->run(new HygieneOptions(reports: ['privacy'], notify: true));

        self::assertNotSame([], $h->notifier->sent);
        $m = $h->notifier->sent[0]['message'];
        self::assertStringNotContainsString('plainsecretvalue1', $m->text . implode('', array_column($m->sections, 'text')));
    }

    public function testTheTerminalTextKeepsTheTemplateReadable(): void
    {
        $h = new HygieneHarness();
        $result = $h->hygiene(HygieneHarness::source(self::hostileEvents()))->run(new HygieneOptions(reports: ['privacy']));

        self::assertStringContainsString('<*>', $result->reports['privacy']->text());
    }

    public function testACutNoticeMarksOnlyTheSitesItShowed(): void
    {
        $h = new HygieneHarness();
        $events = [];
        for ($s = 0; $s < 60; ++$s) {
            $file = '/var/www/html/app/Job' . chr(65 + intdiv($s, 26)) . chr(65 + $s % 26) . '.php';
            for ($k = 0; $k < 3; ++$k) {
                $events[] = HygieneHarness::event(Level::Info, 'Receipt sent to a' . $s . '@example.org', ['file' => $file, 'line' => 1]);
            }
        }
        $opts = new HygieneOptions(reports: ['privacy'], notify: true);

        $h->hygiene(HygieneHarness::source($events))->run($opts);
        $first = $h->notifier->sent[0]['message'];
        foreach ($first->sections as $s) {
            self::assertStringNotContainsString("\n", $s['text'], 'one line per section: each channel splits and limits sections itself');
        }
        self::assertStringContainsString('more site', implode("\n", array_column($first->sections, 'text')));

        $shown = substr_count(implode("\n", array_column($first->sections, 'text')), 'email');
        self::assertSame(25, $shown, 'the notice is capped at 25 sites');

        $h->hygiene(HygieneHarness::source($events))->run($opts);
        $h->hygiene(HygieneHarness::source($events))->run($opts);
        self::assertCount(3, $h->notifier->sent, '25 + 25 + 10: nothing that was cut is suppressed');
        $h->hygiene(HygieneHarness::source($events))->run($opts);
        self::assertCount(3, $h->notifier->sent, 'then everything has been sent');
    }

    public function testShapeProposalsAndCostAreNotResentOnEveryNotify(): void
    {
        $h = new HygieneHarness();
        $events = [];
        foreach (range(1, 30) as $i) {
            $events[] = HygieneHarness::event(Level::Info, str_repeat('x', 30), ['file' => '/var/www/html/app/Noisy.php', 'line' => 1]);
        }
        $opts = new HygieneOptions(reports: ['shape'], notify: true);

        $h->hygiene(HygieneHarness::source($events))->run($opts);
        $h->hygiene(HygieneHarness::source($events))->run($opts);
        self::assertCount(1, $h->notifier->sent);

        $h->clock->advance('+7 days');
        $h->hygiene(HygieneHarness::source($events))->run($opts);
        self::assertCount(2, $h->notifier->sent, 'repeated after repeat_days');
    }

    public function testADisabledNoticeDestinationSendsNothingAndSaysSo(): void
    {
        $h = new HygieneHarness(['notify' => ['notice' => ['enabled' => false]]]);

        $r = $h->hygiene(HygieneHarness::source(HygieneHarness::fixture('hygiene.log')))->run(new HygieneOptions(reports: ['privacy'], notify: true));

        self::assertSame(0, $r->exitCode());
        self::assertSame([], $h->notifier->sent);
        self::assertStringContainsString('notices are disabled', $r->text());
    }

    public function testRetractionsAreNotCutAtTheLineCapAndOnlyThoseSentAreMarked(): void
    {
        $h = new HygieneHarness(['hygiene' => ['repeat_days' => 2]]);
        $entries = [];
        $ids = [];
        for ($n = 1; $n <= 40; ++$n) {
            $ids[$n] = sprintf('app/Modules/Billing/Services/LongNamedInvoiceReminderService%02d/Handlers/Handler:%d', $n, $n);
            $entries['g' . $n] = [
                'id' => $ids[$n], 'kind' => 'frame', 'first' => '2026-09-01T06:00:00+00:00', 'last' => '2026-10-06T06:00:00+00:00',
                'reportedAt' => '2026-09-02T06:00:00+00:00', 'reportedCount' => 5, 'seen' => true, 'gonePending' => false,
                'absent' => 1, 'absentOn' => '2026-10-07',
            ];
        }
        (new \Watcher\Hygiene\HygieneHistory($h->state, 'svc', 2592000, new \DateTimeZone('UTC')))->putRegistry($entries);
        $opts = new HygieneOptions(reports: ['privacy'], notify: true);

        $h->hygiene(HygieneHarness::source([HygieneHarness::event(Level::Info, 'Order created', ['file' => '/var/www/html/app/Order.php', 'line' => 1])]))->run($opts);

        $text = implode("\n", array_column($h->notifier->sent[0]['message']->sections, 'text'));
        $included = array_filter($ids, static fn (string $id): bool => str_contains($text, $id));
        self::assertCount(25, $included, 'every retraction of the notice is there in full, none cut at a line cap');

        $h->hygiene(HygieneHarness::source([HygieneHarness::event(Level::Info, 'Order created', ['file' => '/var/www/html/app/Order.php', 'line' => 1])]))->run($opts);
        $second = implode("\n", array_column($h->notifier->sent[1]['message']->sections, 'text'));
        foreach ($ids as $n => $id) {
            self::assertTrue(str_contains($text, $id) xor str_contains($second, $id), "retraction $n goes out exactly once");
        }
    }
}
