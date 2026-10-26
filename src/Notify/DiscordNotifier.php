<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Closure;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Watcher\Source\ResponseLimit;
use Watcher\Source\Retry;
use Watcher\Source\SourceUnavailable;

/**
 * Posts a Message to a Discord webhook as plain content: mentions off (allowed_mentions.parse = []), embeds suppressed.
 * Errors never carry the webhook URL (it is the credential): see {@see TelegramNotifier} for why DeliveryFailed is
 * thrown from send() and not chained.
 */
final class DiscordNotifier implements Notifier
{
    private const LIMIT = 1900;
    private const SUPPRESS_EMBEDS = 4;

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
        if ($to->driver() !== 'discord' || $to->invalidKey() !== null) {
            throw new DeliveryFailed('discord delivery failed: invalid destination');
        }
        $parts = MessageSplitter::split($this->sections($message), self::LIMIT, DiscordText::plain('… (cut)'));
        $retry = new Retry($this->sleeper, $this->retryBudget);
        MultipartSend::run($parts, $retry, $message->deliveryProgress, fn (string $text, Retry $r): ?string => $this->post($to, $text, $r), 'discord');
    }

    /** @return ?string why it failed (status or "transport error"), null on success; never throws */
    private function post(Destination $to, string $text, Retry $retry): ?string
    {
        $body = ['content' => $text, 'allowed_mentions' => ['parse' => []], 'flags' => self::SUPPRESS_EMBEDS];
        try {
            $response = $retry->sendRateLimited(
                fn (): ResponseInterface => $this->http->request('POST', $to->webhook(), ['json' => $body, 'http_errors' => false, 'allow_redirects' => false] + ResponseLimit::options(new \ArrayObject(), Notifier::MAX_RESPONSE_BYTES)),
                'discord',
                static function (ResponseInterface $r): ?float {
                    $after = json_decode((string) $r->getBody(), true)['retry_after'] ?? null;

                    return is_int($after) || is_float($after) ? (float) $after : null;
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
            return [static fn (int $n): string => DiscordText::plain(mb_substr($message->text, 0, $n))];
        }

        return array_map(
            static fn (array $s): Closure => static fn (int $n): string => DiscordText::section($s['kind'], mb_substr($s['text'], 0, $n)),
            $message->sections,
        );
    }
}
