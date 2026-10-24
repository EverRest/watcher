<?php

declare(strict_types=1);

namespace Watcher\Notify;

/**
 * Tokeniser for the small Slack-mrkdwn dialect the renderers emit: `*bold*`, `code`, `<https://url|label>`.
 * Runs on the SOURCE, before any entity is decoded: text that came from a model or log is already entity-escaped
 * (SlackText::escape), so a `<url|label>` here can only be one the package built. Everything else is text.
 */
final class Mrkdwn
{
    private const TOKEN = '/<([^\s|<>"]+)\|([^<>|]*)>|\*([^*\n]+)\*|`([^`\n]+)`/u';

    /** @return list<array{0: 'text'|'bold'|'code', 1: string}|array{0: 'link', 1: string, 2: string}> text and bodies still entity-encoded, except link (both decoded) */
    public static function tokens(string $source): array
    {
        $out = [];
        $pos = 0;
        preg_match_all(self::TOKEN, $source, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $hit) {
            $start = $hit[0][1];
            $token = null;
            if (($hit[1][1] ?? -1) >= 0 && $hit[1][0] !== '') {
                $url = self::decode($hit[1][0]);
                $token = preg_match('~^https?://[^\s|<>"]+$~D', $url) === 1 ? ['link', $url, self::decode($hit[2][0] ?? '')] : null;
            } elseif (($hit[3][1] ?? -1) >= 0 && $hit[3][0] !== '') {
                $token = ['bold', $hit[3][0]];
            } elseif (($hit[4][1] ?? -1) >= 0 && $hit[4][0] !== '') {
                $token = ['code', $hit[4][0]];
            }
            if ($token === null) {
                continue; // not a token: stays text, including a rejected link
            }
            if ($start > $pos) {
                $out[] = ['text', substr($source, $pos, $start - $pos)];
            }
            $out[] = $token;
            $pos = $start + strlen($hit[0][0]);
        }
        if ($pos < strlen($source)) {
            $out[] = ['text', substr($source, $pos)];
        }

        return $out;
    }

    /** The three entities SlackText::escape produces, decoded in one pass (`&amp;lt;` becomes `&lt;`, not `<`). */
    public static function decode(string $s): string
    {
        return strtr($s, ['&amp;' => '&', '&lt;' => '<', '&gt;' => '>']);
    }
}
