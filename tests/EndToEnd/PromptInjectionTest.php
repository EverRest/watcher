<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Analyze\Analyzer;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Notify\DiscordNotifier;
use Watcher\Notify\Notifier;
use Watcher\Notify\RoutingNotifier;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Notify\TelegramNotifier;
use Watcher\Source\LogEvent;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/**
 * Security review F8: log text is untrusted input to the model, so whatever the model writes (a title, evidence, cause
 * or fix) must reach Slack, Telegram and Discord as plain text: no ping, no link, no markup that renders, no raw log line.
 * The corpus is tests/Fixtures/prompt-injection/corpus.php. The baseline run uses the same log line with a harmless
 * model answer, so a structural token that the renderer itself emits is counted on both sides and cancels out.
 */
final class PromptInjectionTest extends TestCase
{
    /** Tokens no payload may contain, whatever the model wrote: pings, mentions, links, markup, autolink schemes. */
    private const FORBIDDEN = ['<!', '<@', '<#', '<http', '<script', '<img', '://', 'www.', 'evil.com', 'EVIL.COM', '@channel', '@here', '@everyone'];

    /**
     * Tokens whose count must equal the baseline run: the renderer's own markup (`<b>`, `<code>`, a link it builds) plus
     * anything the model could add. Equal counts mean the model added none of them.
     */
    private const COUNTED = ['*', '`', '~', 'www'];

    private const DESTINATIONS = [
        'slack' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x'],
        'telegram' => ['driver' => 'telegram', 'bot_token' => '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw_', 'chat_id' => '-1001234567890'],
        'discord' => ['driver' => 'discord', 'webhook' => 'https://discord.com/api/webhooks/1234567890/abc_DEF-123'],
    ];

    /** @return iterable<string, array{string}> */
    public static function corpus(): iterable
    {
        foreach (require __DIR__ . '/../Fixtures/prompt-injection/corpus.php' as $name => $text) {
            yield $name => [$text];
        }
    }

    /** The string leaves of a decoded request body, joined: what the recipient reads. */
    private static function texts(mixed $body): string
    {
        $leaves = iterator_to_array(new RecursiveIteratorIterator(new RecursiveArrayIterator(is_array($body) ? $body : [$body])), false);

        return implode("\n", array_filter($leaves, is_string(...)));
    }

    /**
     * Runs two windows through the real notifier stack (HTTP is a recording stub) and returns the text the destination
     * receives for the second one: the hostile log line is the group, and the model answers with $answer.
     */
    private function delivered(string $driver, string $line, string $answer): string
    {
        $bodies = [];
        $http = new Client(['handler' => new HandlerStack(static function (RequestInterface $r) use (&$bodies): PromiseInterface {
            $bodies[] = (string) $r->getBody();

            return Create::promiseFor(new Response(200, [], '{"ok":true}'));
        })]);
        $notifier = new RoutingNotifier(new SlackWebhookNotifier($http, 0, static fn (): null => null), new TelegramNotifier($http, 0, static fn (): null => null), new DiscordNotifier($http, 0, static fn (): null => null));

        $h = new Harness(['notify' => ['digest' => self::DESTINATIONS[$driver]]]);
        $analyzer = new HostileAnswer($answer);
        self::runWindow($h, $analyzer, $notifier, Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha'));
        $h->nextWindow();
        /** @var list<string> $bodies */
        $bodies = [];
        self::runWindow($h, $analyzer, $notifier, self::hostileEvents($line));

        self::assertNotSame([], $bodies, "$driver: nothing was delivered");
        $text = '';
        foreach ($bodies as $body) {
            $text .= self::texts(json_decode($body, true, 512, JSON_THROW_ON_ERROR)) . "\n";
        }

        return $text;
    }

    /** @param list<LogEvent> $events */
    private static function runWindow(Harness $h, Analyzer $analyzer, Notifier $notifier, array $events): void
    {
        (new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $notifier, $h->clock))->run();
    }

    /** @return list<LogEvent> */
    private static function hostileEvents(string $line): array
    {
        $at = new DateTimeImmutable('2026-10-07T12:00:00', new DateTimeZone('UTC'));

        return array_map(static fn (int $i): LogEvent => new LogEvent($at, Level::Error, $line, 'Alpha', [], [], []), range(1, 5));
    }

    /** @dataProvider corpus */
    public function testNoDestinationReceivesPingLinkMarkupFromTheModel(string $line): void
    {
        foreach (array_keys(self::DESTINATIONS) as $driver) {
            $text = $this->delivered($driver, $line, $line);
            $baseline = $this->delivered($driver, $line, 'a plain summary of the failure');

            foreach (self::FORBIDDEN as $bad) {
                self::assertStringNotContainsString($bad, $text, "$driver: forbidden token survived");
            }
            // A model can only add markup, never remove the renderer's: a count above the baseline is the hole. A bare `<`
            // is one Discord has not neutralised (it puts a zero-width space after it); `<` is also what Slack and Telegram escape.
            foreach (self::COUNTED as $token) {
                self::assertLessThanOrEqual(substr_count($baseline, $token), substr_count($text, $token), "$driver: [$token] above the baseline");
            }
            self::assertLessThanOrEqual(substr_count(str_replace("<\u{200B}", '', $baseline), '<'), substr_count(str_replace("<\u{200B}", '', $text), '<'), "$driver: bare < above the baseline");
        }
    }

    /** @dataProvider corpus */
    public function testTheRawLogLineIsNeverEchoedWhenTheModelWritesSomethingElse(string $line): void
    {
        if (trim($line) === '') {
            self::markTestSkipped('an empty line has nothing to echo');
        }
        foreach (array_keys(self::DESTINATIONS) as $driver) {
            self::assertStringNotContainsString(trim($line), $this->delivered($driver, $line, 'a plain summary of the failure'), "$driver: the raw log line reached the recipient");
        }
    }
}
