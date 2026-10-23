<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Text safe to put in a Slack mrkdwn message: model or exception text cannot ping, link or format. */
final class SlackText
{
    /** Extensions that look like a domain label but are file names: kept readable and copyable. */
    private const FILE_EXT = 'php|json|log|txt|yml|yaml|xml|js|ts|css|html|sql|csv|env|lock|rb|jar|gz';

    /**
     * One line, Slack control sequences neutralised: model text cannot ping, link or format, and — because log
     * content can steer the model (prompt injection) — cannot produce anything Slack would auto-link: a zero-width
     * space breaks `scheme://`, `www.` and `host.tld` tokens (file names such as `X.php:88` stay intact).
     */
    public static function escape(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        $s = str_replace(['&', '<', '>', '`', '@', '://'], ['&amp;', '&lt;', '&gt;', "'", "@\u{200B}", ":\u{200B}//"], $s);
        $s = (string) preg_replace('/\bwww\./i', "www\u{200B}.", $s);

        return (string) preg_replace('/([a-z0-9-]\.)(?!(?:' . self::FILE_EXT . ')\b)(?=[a-z]{2,24}\b)/i', "$1\u{200B}", $s);
    }

    /** Undoes {@see escape()} for the plain-text fallback of a message. */
    public static function plain(string $s): string
    {
        return str_replace(['&amp;', '&lt;', '&gt;', "\u{200B}"], ['&', '<', '>', ''], $s);
    }
}
