<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Closure;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Watcher\Source\Retry;
use Watcher\Source\SourceUnavailable;

/** Posts a Message as Block Kit. Errors never carry the webhook URL (it is the credential). */
final class SlackWebhookNotifier implements Notifier
{
    private const MAX_BLOCKS = 50;
    private const MAX_TEXT = 4000;

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
        $body = [
            'text' => mb_substr($message->text, 0, self::MAX_TEXT),
            'blocks' => $this->blocks($message),
        ];

        // Checked here too (not only by `watcher check`): a malformed URL must never reach the client, whose messages echo it.
        if ($to->invalidKey() !== null) {
            throw new DeliveryFailed('slack delivery failed: invalid destination URL (https, a host and no credentials are required)');
        }

        $failure = $this->post($to, $body);
        if ($failure !== null) {
            throw new DeliveryFailed('slack delivery failed: ' . $failure);
        }
    }

    /**
     * @param array{text: string, blocks: list<array<string, mixed>>} $body
     *
     * @return ?string why it failed (status or "transport error"), null on success; never throws
     */
    private function post(Destination $to, array $body): ?string
    {
        $retry = new Retry($this->sleeper, $this->retryBudget);
        try {
            $response = $retry->sendRateLimited(
                fn (): ResponseInterface => $this->http->request('POST', $to->webhook(), ['json' => $body, 'http_errors' => false, 'allow_redirects' => false, 'verify' => true]),
                'slack',
                static function (ResponseInterface $r): ?int {
                    $header = $r->getHeaderLine('Retry-After');

                    return is_numeric($header) ? (int) $header : null;
                },
            );
        } catch (SourceUnavailable|\InvalidArgumentException) {
            return 'transport error';
        }
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300 ? null : "HTTP $status";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(Message $message): array
    {
        $blocks = array_map($this->block(...), $message->sections);
        if (count($blocks) <= self::MAX_BLOCKS) {
            return $blocks;
        }
        $omitted = count($blocks) - (self::MAX_BLOCKS - 1);
        $blocks = array_slice($blocks, 0, self::MAX_BLOCKS - 1);
        $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => sprintf('… %d more sections not shown', $omitted)]];

        return $blocks;
    }

    /**
     * @param array{kind: string, text: string} $s
     *
     * @return array<string, mixed>
     */
    private function block(array $s): array
    {
        return match ($s['kind']) {
            'draft' => ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "```\n" . str_replace('```', "'''", $s['text']) . "\n```"]],
            // plain_text does not decode entities, so undo the mrkdwn escaping; header limit is 150.
            'header' => ['type' => 'header', 'text' => [
                'type' => 'plain_text',
                'text' => mb_substr(htmlspecialchars_decode($s['text'], ENT_NOQUOTES), 0, 150),
            ]],
            'footer' => ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $s['text']]]],
            default => ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $s['text']]],
        };
    }
}
