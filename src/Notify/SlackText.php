<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Text safe to put in a Slack mrkdwn message: model or exception text cannot ping, link or format. */
final class SlackText
{
    /** Extensions that look like a domain label but are file names: kept readable and copyable. */
    private const FILE_EXT = 'php|json|log|txt|yml|yaml|xml|js|ts|css|html|sql|csv|env|lock|rb|jar|gz';

    /**
     * Top-level domains of three or more ASCII letters that Slack links (two-letter ones are all treated as TLDs;
     * non-ASCII and `xn--` ones are matched separately). A code identifier such as `Mailer.send` is not a host and
     * stays copyable. ponytail: a hand-kept list; a TLD missing here is still defanged when the host is all lowercase, so only a capitalised host on a rare gTLD slips through.
     */
    private const TLDS = 'com|net|org|edu|gov|mil|int|info|biz|name|pro|mobi|asia|tel|xxx|jobs|travel|museum|aero|coop|cat|post'
        . '|app|dev|cloud|online|site|tech|store|shop|blog|news|live|link|click|top|club|space|website|email|page|xyz|icu|vip'
        . '|work|world|today|agency|network|systems|solutions|services|digital|media|group|company|center|life|art|design'
        . '|zip|mov|run|one|fun|host|press|buzz|rocks|ninja|guru|fyi|wiki|social|chat|lol|best|support|download|review|party'
        . '|win|bid|stream|science|trade|date|men|loan|racing|rest|bar|cyou|monster|sbs|cfd|quest|bond|mom|sale|cool';

    /** mrkdwn delimiters and the look-alikes that stand in for them: a look-alike cannot open or close formatting. */
    private const LOOKALIKE = ['*' => "\u{2217}", '_' => "\u{FF3F}", '~' => "\u{223C}"];

    /**
     * One line, Slack control sequences neutralised: model text cannot ping, link or format, and — because log
     * content can steer the model (prompt injection) — cannot produce anything Slack would auto-link: a zero-width
     * space breaks `scheme://`, `www.` and `host.tld` tokens (file names such as `X.php:88` stay intact). `*bold*`,
     * `_italic_`, `~strike~` and `:emoji:` are neutralised too, so every channel (the Telegram and Discord renderers
     * re-tokenise this text) sees plain text; a capitalised identifier whose member is a real TLD (`Logger.info`, `User.id`) is defanged like a host, since `Evil.info` cannot be told apart; `snake_case`, `~/path` and `12:30:45` stay as they are.
     */
    public static function escape(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));

        $s = str_replace(['&', '<', '>', '`'], ['&amp;', '&lt;', '&gt;', "'"], $s);
        if (substr_count($s, '*') >= 2) { // one star cannot open and close bold (`2 * 3`, `*.log`)
            $s = str_replace('*', self::LOOKALIKE['*'], $s);
        }
        $s = (string) preg_replace('/:(?=[a-z][a-z0-9_+-]*:)/i', ":\u{200B}", $s);
        // An opener (not after a word character, then a non-space) with a closer (after a non-space, not before a word character).
        $s = (string) preg_replace_callback('/(?<![\p{L}\p{N}_~])([_~])(?![\s_~])(.*?[^\s_~])\1(?![\p{L}\p{N}_~])/u', static fn (array $m): string => self::LOOKALIKE[$m[1]] . $m[2] . self::LOOKALIKE[$m[1]], $s);

        return self::defang($s);
    }

    /** The zero-width-space half of {@see escape()}, for text that is not Slack-escaped (other channels' plain path). */
    public static function defang(string $s): string
    {
        $dot = '[.\x{3002}\x{FF0E}\x{FF61}]'; // the full stop and the IDNA dots a browser treats as one
        $s = str_replace(['@', '://'], ["@\u{200B}", ":\u{200B}//"], $s);
        $s = (string) preg_replace("/\\bwww($dot)/iu", "www\u{200B}$1", $s);

        $s = (string) preg_replace(
            "/([\\p{L}\\p{M}\\p{N}-]$dot)(?!(?:" . self::FILE_EXT . ")\\b)(?=(?:[a-z]{2}|" . self::TLDS . ")\\b|[\\p{L}\\p{M}]*[^\\x00-\\x7F][\\p{L}\\p{M}]*\\b|xn--[a-z0-9-]{2,}\\b)/iu",
            "$1\u{200B}",
            $s,
        );

        // A TLD missing from the list still fails closed when both labels are lowercase (`mailer.send`); `Mailer.send` and `foo.sendAll` read as code.
        return (string) preg_replace("/(?<![\\p{L}\\p{N}-])([a-z0-9-]+$dot)(?!(?:" . self::FILE_EXT . ")\\b)(?=[a-z]{3,24}\\b)/u", "$1\u{200B}", $s);
    }

    /** Undoes {@see escape()} for the plain-text fallback of a message. */
    public static function plain(string $s): string
    {
        return str_replace(['&amp;', '&lt;', '&gt;', "\u{200B}", ...array_values(self::LOOKALIKE)], ['&', '<', '>', '', ...array_keys(self::LOOKALIKE)], $s);
    }
}
