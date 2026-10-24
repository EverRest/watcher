<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Text safe to put in a Slack mrkdwn message: model or exception text cannot ping, link or format. */
final class SlackText
{
    /** Extensions that look like a domain label but are file names: kept readable and copyable. */
    private const FILE_EXT = 'php|json|log|txt|yml|yaml|xml|js|ts|css|html|sql|csv|env|lock|rb|jar|gz';

    /** mrkdwn delimiters and the look-alikes that stand in for them: a look-alike cannot open or close formatting. */
    private const LOOKALIKE = ['*' => "\u{2217}", '_' => "\u{FF3F}", '~' => "\u{223C}"];

    /**
     * One line, Slack control sequences neutralised: model text cannot ping, link or format, and — because log
     * content can steer the model (prompt injection) — cannot produce anything Slack would auto-link: a zero-width
     * space breaks `scheme://`, `www.` and `host.tld` tokens (file names such as `X.php:88` stay intact). `*bold*`,
     * `_italic_`, `~strike~` and `:emoji:` are neutralised too, so every channel (the Telegram and Discord renderers
     * re-tokenise this text) sees plain text; `snake_case`, `~/path` and `12:30:45` stay as they are.
     */
    public static function escape(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        $s = str_replace(['&', '<', '>', '`', '*'], ['&amp;', '&lt;', '&gt;', "'", self::LOOKALIKE['*']], $s);
        $s = (string) preg_replace('/:(?=[a-z][a-z0-9_+-]*:)/i', ":\u{200B}", $s);
        // An opener (not after a word character, then a non-space) with a closer (after a non-space, not before a word character).
        $s = (string) preg_replace_callback('/(?<![\p{L}\p{N}_~])([_~])(?![\s_~])(.*?[^\s_~])\1(?![\p{L}\p{N}_~])/u', static fn (array $m): string => self::LOOKALIKE[$m[1]] . $m[2] . self::LOOKALIKE[$m[1]], $s);

        return self::defang($s);
    }

    /** The zero-width-space half of {@see escape()}, for text that is not Slack-escaped (other channels' plain path). */
    public static function defang(string $s): string
    {
        $s = str_replace(['@', '://'], ["@\u{200B}", ":\u{200B}//"], $s);
        $s = (string) preg_replace('/\bwww\./i', "www\u{200B}.", $s);

        return (string) preg_replace('/([a-z0-9-]\.)(?!(?:' . self::FILE_EXT . ')\b)(?=[a-z]{2,24}\b)/i', "$1\u{200B}", $s);
    }

    /** Undoes {@see escape()} for the plain-text fallback of a message. */
    public static function plain(string $s): string
    {
        return str_replace(['&amp;', '&lt;', '&gt;', "\u{200B}", ...array_values(self::LOOKALIKE)], ['&', '<', '>', '', ...array_keys(self::LOOKALIKE)], $s);
    }
}
