<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** The failure and budget notices of contracts/slack-messages.md: a reason string, never a trace, URL, key or log content. */
final class NoticeRenderer
{
    private const MAX_REASON = 200;
    private const MIN_SECRET = 8;

    /** @param list<string> $secrets exact values (API keys, webhooks) to mask on top of the generic scrubbing */
    public function __construct(private readonly array $secrets = [])
    {
    }

    /** @param string $kind source|analysis, anything else reads as a generic run failure */
    public function failure(string $service, string $environment, string $kind, string $reason): Message
    {
        $kind = in_array($kind, ['source', 'analysis'], true) ? $kind : 'run';

        return $this->notice('❌', $service, $environment, $kind . ' failed: ' . $this->reason($reason));
    }

    public function budgetExhausted(string $service, string $environment): Message
    {
        return $this->notice('⚠️', $service, $environment, 'budget exhausted: triage only today');
    }

    private function notice(string $icon, string $service, string $environment, string $tail): Message
    {
        $text = sprintf('%s watcher · %s · %s — %s', $icon, SlackText::escape($service), SlackText::escape($environment), $tail);

        return new Message(SlackText::plain($text), [['kind' => 'notice', 'text' => $text]]);
    }

    private function reason(string $reason): string
    {
        foreach ($this->secrets as $secret) {
            if (strlen($secret) >= self::MIN_SECRET) {
                $reason = str_replace($secret, '[redacted]', $reason);
            }
        }
        $reason = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://\S+~i', '[url]', $reason);
        $reason = (string) preg_replace('/[\w-]{24,}/', '[redacted]', $reason);
        $reason = SlackText::escape($reason);

        return mb_strlen($reason) <= self::MAX_REASON ? $reason : mb_substr($reason, 0, self::MAX_REASON - 1) . '…';
    }
}
