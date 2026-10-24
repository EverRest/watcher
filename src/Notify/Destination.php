<?php

declare(strict_types=1);

namespace Watcher\Notify;

use JsonSerializable;
use LogicException;

/** Where to deliver. Webhook, bot token and chat id are secrets: they must never reach a log, dump or serialisation. */
final class Destination implements JsonSerializable
{
    private const TOKEN = '/^\d+:[A-Za-z0-9_-]{30,}$/D';
    private const CHAT = '/^(?:-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/D';
    private const DISCORD = '~^https://(?:(?:canary|ptb)\.)?(?:discord|discordapp)\.com/api(?:/v\d+)?/webhooks/\d+/[A-Za-z0-9_-]+$~D';

    // Held in a closure: var_export/(array) casts of an object expose properties, never closure captures.
    /** @var \Closure(): array{webhook: string, bot_token: string, chat_id: string, thread_id: ?int} */
    private readonly \Closure $reveal;

    /** `new Destination($url)` is a Slack destination, as before the other drivers existed. */
    public function __construct(string $webhook, private readonly string $driver = 'slack', string $botToken = '', string $chatId = '', ?int $threadId = null)
    {
        $secrets = ['webhook' => $webhook, 'bot_token' => $botToken, 'chat_id' => $chatId, 'thread_id' => $threadId];
        $this->reveal = static fn (): array => $secrets;
    }

    public static function slack(string $webhook): self
    {
        return new self($webhook);
    }

    public static function discord(string $webhook): self
    {
        return new self($webhook, 'discord');
    }

    public static function telegram(string $botToken, string $chatId, ?int $threadId = null): self
    {
        return new self('', 'telegram', $botToken, $chatId, $threadId);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** Only the notifier that performs the delivery should call the accessors below. */
    public function webhook(): string
    {
        $this->need('slack', 'discord');

        return ($this->reveal)()['webhook'];
    }

    public function botToken(): string
    {
        $this->need('telegram');

        return ($this->reveal)()['bot_token'];
    }

    public function chatId(): string
    {
        $this->need('telegram');

        return ($this->reveal)()['chat_id'];
    }

    public function threadId(): ?int
    {
        $this->need('telegram');

        return ($this->reveal)()['thread_id'];
    }

    public function sameAs(self $other): bool
    {
        return $this->driver === $other->driver && ($this->reveal)() === ($other->reveal)();
    }

    /** Name of the first key whose value has the wrong shape, or null. Never returns a value. */
    public function invalidKey(): ?string
    {
        $v = ($this->reveal)();

        return match ($this->driver) {
            'telegram' => match (true) {
                preg_match(self::TOKEN, $v['bot_token']) !== 1 => 'bot_token',
                preg_match(self::CHAT, $v['chat_id']) !== 1 => 'chat_id',
                $v['thread_id'] !== null && $v['thread_id'] < 1 => 'thread_id',
                default => null,
            },
            'discord' => preg_match(self::DISCORD, $v['webhook']) === 1 ? null : 'webhook',
            default => self::httpsWithHost($v['webhook']) ? null : 'webhook',
        };
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['driver' => $this->driver, 'secrets' => '[redacted]'];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['driver' => $this->driver, 'secrets' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Destination is not serialisable.');
    }

    private function need(string ...$drivers): void
    {
        if (!in_array($this->driver, $drivers, true)) {
            throw new LogicException('Destination of driver ' . $this->driver . ' has no such value.');
        }
    }

    private static function httpsWithHost(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== '';
    }
}
