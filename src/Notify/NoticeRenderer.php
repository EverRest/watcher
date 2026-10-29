<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** The failure notices of contracts/slack-messages.md: a reason string, never a trace, URL, key or log content. */
final class NoticeRenderer
{
    private const MAX_REASON = 200;
    private const MIN_SECRET = 8;

    /** @var \Closure(): list<string> */
    private readonly \Closure $secrets;

    /** @param list<string> $secrets exact values ({@see \Watcher\Config\WatchConfig::secrets()}) to mask on top of the generic scrubbing */
    public function __construct(#[\SensitiveParameter] array $secrets = [])
    {
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a)); // longest first, so a prefix secret cannot leave its suffix
        $this->secrets = static fn (): array => $secrets; // a closure: var_export shows no value
    }

    /** @param string $kind source|analysis, anything else reads as a generic run failure */
    public function failure(string $service, string $environment, string $kind, string $reason): Message
    {
        $kind = in_array($kind, ['source', 'analysis', 'config'], true) ? $kind : 'run';

        return $this->notice('❌', $service, $environment, $kind . ' failed: ' . $this->reason($reason));
    }

    /**
     * Weekly "it is alive" line: counts only, never content.
     *
     * @param array<string, int> $counts reported, quiet, baselined, failed, skipped (missing = 0)
     */
    public function weekly(string $service, string $environment, string $isoWeek, array $counts): Message
    {
        $parts = [];
        foreach (['reported', 'quiet', 'baselined', 'failed', 'skipped'] as $name) {
            $parts[] = $name . ' ' . (int) ($counts[$name] ?? 0);
        }

        return $this->notice('📊', $service, $environment, sprintf('week %s: %s', SlackText::escape($isoWeek), implode(', ', $parts)));
    }

    /** Immediate alert from the host health check: the greppable prefix, then both versions. Versions go through reason() like any other host text. */
    public function versionChanged(string $service, string $environment, string $old, string $new, string $checkedAt): Message
    {
        $text = sprintf('🔔 VERSION CHANGED %s · %s · %s → %s (checked %s)', SlackText::escape($service), SlackText::escape($environment), $this->reason($old), $this->reason($new), SlackText::escape($checkedAt));

        return new Message(SlackText::plain($text), [['kind' => 'notice', 'text' => $text]]);
    }

    /** Up/down transition of one service (opt-in); sent once when the status flips, never on every failing hour. */
    public function healthTransition(string $service, string $environment, bool $up, string $checkedAt): Message
    {
        $text = sprintf('%s %s · %s · checked %s', $up ? '✅ UP' : '🔴 DOWN', SlackText::escape($service), SlackText::escape($environment), SlackText::escape($checkedAt));

        return new Message(SlackText::plain($text), [['kind' => 'notice', 'text' => $text]]);
    }

    private function notice(string $icon, string $service, string $environment, string $tail): Message
    {
        $text = sprintf('%s watcher · %s · %s — %s', $icon, SlackText::escape($service), SlackText::escape($environment), $tail);

        return new Message(SlackText::plain($text), [['kind' => 'notice', 'text' => $text]]);
    }

    /** For a host log: the exception class and its message with every secret, URL and credential shape masked. */
    public function describe(\Throwable $e): string
    {
        return $e::class . ': ' . $this->reason($e->getMessage());
    }

    private function reason(string $reason): string
    {
        foreach (($this->secrets)() as $secret) {
            if (strlen($secret) >= self::MIN_SECRET) {
                $reason = str_replace($secret, '[redacted]', $reason);
            }
        }
        // A credential in a header or query shape, whether or not it is one of the configured values.
        $reason = (string) preg_replace('/(?<![a-z0-9])((?:proxy-)?authorization|x-api-key|api[-_]?key|app[-_]?key|access[-_]?token|token|passw(?:or)?d|secret)\b(["\']?\s*[:=]\s*["\']?)(?:(?:bearer|basic)\s+)?[^\s"\',;}]+/i', '$1$2[redacted]', $reason);
        $reason = (string) preg_replace('/\bbearer\s+(?=[\w.~+\/=-]*[\d.~+\/=_-])[\w.~+\/=-]{6,}/i', 'bearer [redacted]', $reason);
        $reason = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://\S+~i', '[url]', $reason);
        $reason = (string) preg_replace('/[\w-]{24,}/', '[redacted]', $reason);
        $reason = SlackText::escape($reason);

        return mb_strlen($reason) <= self::MAX_REASON ? $reason : mb_substr($reason, 0, self::MAX_REASON - 1) . '…';
    }

    /** @return array<string, never> var_dump and print_r show nothing of what this object holds */
    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): array
    {
        throw new \LogicException(self::class . ' is not serialisable.');
    }
}
