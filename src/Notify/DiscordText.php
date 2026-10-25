<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Slack-mrkdwn section text to Discord markdown. Everything outside the package's own tokens is escaped. */
final class DiscordText
{
    private const ZWSP = "\u{200B}";

    public static function section(string $kind, string $source): string
    {
        if ($kind === 'draft') {
            return '```' . "\n" . str_replace('```', "'''", $source) . "\n```";
        }
        $out = '';
        foreach (Mrkdwn::tokens($source) as $t) {
            $out .= match ($t[0]) {
                'text' => self::esc(Mrkdwn::decode($t[1]), $out === '' || str_ends_with($out, "\n")),
                'bold' => $kind === 'header' ? self::esc(Mrkdwn::decode($t[1]), false) : '**' . self::esc(Mrkdwn::decode($t[1]), false) . '**',
                'code' => '`' . str_replace('`', "'", Mrkdwn::decode($t[1])) . '`',
                'link' => '[' . self::esc($t[2], false) . '](<' . $t[1] . '>)',
            };
        }

        return $kind === 'header' && $out !== '' ? "**$out**" : $out;
    }

    /** Text that is not Slack-escaped (a section-less message): defanged here, then escaped. */
    public static function plain(string $text): string
    {
        return self::esc(SlackText::defang($text), true);
    }

    private static function esc(string $s, bool $atLineStart): string
    {
        $s = (string) preg_replace('/[\\\\*_~|`\[\]()]/', '\\\\$0', $s);
        $s = str_replace('<', '<' . self::ZWSP, $s);
        $s = (string) preg_replace('/(?<=\n)(?=[#>+-]|\d+\.)/', '\\\\', $s);

        return $atLineStart && preg_match('/^(?:[#>+-]|\d+\.)/', $s) === 1 ? '\\' . $s : $s;
    }
}
