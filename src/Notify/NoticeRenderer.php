<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** The failure and budget notices of contracts/slack-messages.md: a reason string, never a trace, URL, key or log content. */
final class NoticeRenderer
{
    private const MAX_REASON = 200;
    private const MIN_SECRET = 8;

    /** @param list<string> $secrets exact values ({@see \Watcher\Config\WatchConfig::secrets()}) to mask on top of the generic scrubbing */
    public function __construct(private readonly array $secrets = [])
    {
    }

    /** @param string $kind source|analysis, anything else reads as a generic run failure */
    public function failure(string $service, string $environment, string $kind, string $reason): Message
    {
        $kind = in_array($kind, ['source', 'analysis'], true) ? $kind : 'run';

        return $this->notice('❌', $service, $environment, $kind . ' failed: ' . $this->reason($reason));
    }

    /** @param string|null $cap 'fleet' or 'service': which cap was hit; null outside a fleet */
    public function budgetExhausted(string $service, string $environment, ?string $cap = null): Message
    {
        return $this->notice('⚠️', $service, $environment, 'budget exhausted' . ($cap === null ? '' : " ($cap cap)") . ': triage only today');
    }

    /**
     * Weekly "it is alive" line: counts and cost only, never content.
     *
     * @param array<string, int> $counts reported, quiet, baselined, failed, skipped (missing = 0)
     */
    public function weekly(string $service, string $environment, string $isoWeek, array $counts, float $costUsd): Message
    {
        $parts = [];
        foreach (['reported', 'quiet', 'baselined', 'failed', 'skipped'] as $name) {
            $parts[] = $name . ' ' . (int) ($counts[$name] ?? 0);
        }

        return $this->notice('📊', $service, $environment, sprintf('week %s: %s, cost $%s', SlackText::escape($isoWeek), implode(', ', $parts), number_format($costUsd, 4, '.', '')));
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
        // A credential in a header or query shape, whether or not it is one of the configured values.
        $reason = (string) preg_replace('/(?<![a-z0-9])((?:proxy-)?authorization|x-api-key|api[-_]?key|app[-_]?key|access[-_]?token|token|passw(?:or)?d|secret)\b(["\']?\s*[:=]\s*["\']?)(?:(?:bearer|basic)\s+)?[^\s"\',;}]+/i', '$1$2[redacted]', $reason);
        $reason = (string) preg_replace('/\bbearer\s+[\w.~+\/=-]{6,}/i', 'bearer [redacted]', $reason);
        $reason = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://\S+~i', '[url]', $reason);
        $reason = (string) preg_replace('/[\w-]{24,}/', '[redacted]', $reason);
        $reason = SlackText::escape($reason);

        return mb_strlen($reason) <= self::MAX_REASON ? $reason : mb_substr($reason, 0, self::MAX_REASON - 1) . '…';
    }
}
