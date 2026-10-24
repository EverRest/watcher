<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Closure;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Watcher\Source\Retry;
use Watcher\Source\SourceUnavailable;

/**
 * Posts a Message through the Bot API `sendMessage` as HTML. The bot token is part of the URL, and PHP traces print
 * string arguments of active frames: the token is read inside a closure, and DeliveryFailed is thrown from send(),
 * a frame that holds neither the token nor the URL, and never chained to the Guzzle exception (its message has the URL).
 */
final class TelegramNotifier implements Notifier
{
    private const LIMIT = 4000;

    private readonly ?Closure $sleeper;

    /** @param (callable(int): void)|null $sleeper whole seconds to wait on a 429; defaults to sleep() */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly int $retryBudget = 60,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper === null ? null : Closure::fromCallable($sleeper);
    }

    public function send(Message $message, Destination $to): void
    {
        if ($to->driver() !== 'telegram' || $to->invalidKey() !== null) {
            throw new DeliveryFailed('telegram delivery failed: invalid destination');
        }
        $parts = MessageSplitter::split($this->sections($message), self::LIMIT, TelegramText::plain('… (cut)'));
        $retry = new Retry($this->sleeper, $this->retryBudget);
        foreach ($parts as $i => $text) {
            $failure = $this->post($to, $text, $retry);
            if ($failure !== null) {
                throw new DeliveryFailed('telegram delivery failed: ' . $failure . (count($parts) > 1 ? sprintf(' at part %d of %d', $i + 1, count($parts)) : ''));
            }
        }
    }

    /** @return ?string why it failed (status or "transport error"), null on success; never throws */
    private function post(Destination $to, string $text, Retry $retry): ?string
    {
        $body = ['chat_id' => $to->chatId(), 'text' => $text, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
        if ($to->threadId() !== null) {
            $body['message_thread_id'] = $to->threadId();
        }
        try {
            $response = $retry->sendRateLimited(
                fn (): ResponseInterface => $this->http->request('POST', 'https://api.telegram.org/bot' . $to->botToken() . '/sendMessage', ['json' => $body, 'http_errors' => false, 'allow_redirects' => false]),
                'telegram',
                static function (ResponseInterface $r): ?int {
                    $after = json_decode((string) $r->getBody(), true)['parameters']['retry_after'] ?? null;

                    return is_int($after) ? $after : null;
                },
            );
        } catch (SourceUnavailable) {
            return 'transport error';
        }
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300 ? null : "HTTP $status";
    }

    /** @return list<Closure(int): string> */
    private function sections(Message $message): array
    {
        if ($message->sections === []) {
            return [static fn (int $n): string => TelegramText::plain(mb_substr($message->text, 0, $n))];
        }

        return array_map(
            static fn (array $s): Closure => static fn (int $n): string => TelegramText::section($s['kind'], mb_substr($s['text'], 0, $n)),
            $message->sections,
        );
    }
}
