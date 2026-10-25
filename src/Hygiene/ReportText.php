<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use Watcher\Notify\SlackText;
use Watcher\Reduce\Normalizer;

/**
 * Log-derived strings (a site id, a masked template, a rule fragment) can still carry markup a log writer chose: the Normalizer
 * makes `<` and `>` inert while it scans and restores them afterwards. In a notice every such string goes through the same
 * escaper as the digest, so it cannot ping, link or format in Slack, Telegram or Discord; on a terminal it stays readable.
 */
final class ReportText
{
    /**
     * Control (Cc), format (Cf) and invisible filler characters (braille blank, Hangul fillers, combining grapheme joiner, variation
     * selectors) removed; a line break or tab becomes a space. Used on every log-derived string before it is scanned, so an invisible
     * character inside an address cannot defeat a mask, and before it is printed, so ESC sequences and bidi overrides never reach a terminal.
     */
    public static function clean(string $s): string
    {
        $s = (string) preg_replace('/[\r\n\t]+/u', ' ', $s);

        return (string) preg_replace('/[\p{Cc}\p{Cf}\p{Cn}\p{Zl}\p{Zp}\x{2800}\x{3164}\x{115F}\x{1160}\x{FFA0}\x{034F}\x{FE00}-\x{FE0F}]+/u', '', $s);
    }

    /**
     * What the scan must see: the text as the Normalizer will decode it (percent, entity and compatibility forms), THEN cleaned, and again
     * until nothing changes, so an invisible character that only appears after decoding (`%E2%A0%80`, `&#x2800;`, `&#x1B;`, a nested
     * encoding) is gone before any mask looks at the address. Cleaning the raw text alone leaves the decoded character inside it.
     */
    public static function forScan(string $s): string
    {
        if (preg_match('//u', $s) !== 1) {
            return $s; // not UTF-8: left for the Normalizer to refuse, so the line is counted as unscannable and never guessed at
        }
        for ($round = 0; $round < 4; ++$round) {
            // html_entity_decode leaves numeric references to control characters alone (`&#x1B;`, `&#133;`); a character that is
            // invisible once decoded is dropped here, as the cleaning would drop it.
            $drop = static function (int $cp, string $whole): string {
                return $cp > 0 && $cp <= 0x10FFFF && self::clean((string) mb_chr($cp, 'UTF-8')) === '' ? '' : $whole;
            };
            $s = (string) preg_replace_callback('/&#x([0-9a-f]{1,6});/i', static fn (array $m): string => $drop((int) hexdec($m[1]), $m[0]), $s);
            $s = (string) preg_replace_callback('/&#([0-9]{1,7});/', static fn (array $m): string => $drop((int) $m[1], $m[0]), $s);
            $next = self::clean(Normalizer::canonical($s));
            if ($next === $s) {
                break;
            }
            $s = $next;
        }

        return $s;
    }

    /** A class name for a message: no namespace, and an anonymous class loses the file and line PHP appends to its name. */
    public static function className(string $class): string
    {
        return basename(str_replace('\\', '/', (string) preg_replace('/@anonymous.*/s', '@anonymous', $class)));
    }

    public static function safe(bool $notice, string $s): string
    {
        $s = self::clean($s);

        return $notice ? SlackText::escape($s) : $s;
    }
}
