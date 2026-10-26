<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Slack-mrkdwn section text to Telegram HTML. Only `b`, `code` and `a href` are ever emitted; all else is escaped. */
final class TelegramText
{
    public static function section(string $kind, string $source): string
    {
        if ($kind === 'draft') {
            return '<pre>' . self::esc($source) . '</pre>';
        }
        $inner = '';
        foreach (Mrkdwn::tokens($source) as $t) {
            $inner .= match ($t[0]) {
                'text' => self::esc(Mrkdwn::decode($t[1])),
                'bold' => $kind === 'header' ? self::esc(Mrkdwn::decode($t[1])) : '<b>' . self::esc(Mrkdwn::decode($t[1])) . '</b>',
                'code' => '<code>' . self::esc(Mrkdwn::decode($t[1])) . '</code>',
                'link' => '<a href="' . strtr($t[1], ['&' => '&amp;', '"' => '&quot;']) . '">' . self::esc($t[2]) . '</a>',
            };
        }

        return $kind === 'header' && $inner !== '' ? "<b>$inner</b>" : $inner;
    }

    /** Text that is not Slack-escaped (a section-less message): defanged here, then HTML-escaped. */
    public static function plain(string $text): string
    {
        return self::esc(SlackText::defang($text));
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE);
    }
}
