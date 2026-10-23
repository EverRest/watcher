<?php

declare(strict_types=1);

namespace Watcher\Notify;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Posts a Message as Block Kit. Errors never carry the webhook URL (it is the credential). */
final class SlackWebhookNotifier implements Notifier
{
    private const MAX_BLOCKS = 50;
    private const MAX_TEXT = 4000;

    public function __construct(private readonly ClientInterface $http)
    {
    }

    public function send(Message $message, Destination $to): void
    {
        $body = [
            'text' => mb_substr($message->text, 0, self::MAX_TEXT),
            'blocks' => array_slice(array_map($this->block(...), $message->sections), 0, self::MAX_BLOCKS),
        ];

        try {
            $status = $this->http->request('POST', $to->webhook(), ['json' => $body, 'http_errors' => false])->getStatusCode();
        } catch (GuzzleException) {
            // Deliberately not chained: Guzzle messages embed the request URL.
            throw new DeliveryFailed('slack delivery failed: transport error');
        }
        if ($status < 200 || $status >= 300) {
            throw new DeliveryFailed("slack delivery failed: HTTP $status");
        }
    }

    /**
     * @param array{kind: string, text: string} $s
     *
     * @return array<string, mixed>
     */
    private function block(array $s): array
    {
        return match ($s['kind']) {
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
