<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Reduce\Normalizer;

/**
 * The span-replacing filter for model text: links, www. hosts and e-mail addresses always, command-shaped advice when
 * `$advice` is set. Shared by {@see OutputValidator} (the digest) and the MCP output guard: one copy of the denylist.
 */
final class CommandFilter
{
    public const WITHHELD = '[withheld]';

    /**
     * Command-shaped or credential-fishing advice, withheld from suggested_action / how_to_check / suspected_cause. A small, documented
     * denylist (docs/privacy.md), not a proof of safety: each hit replaces the matching text up to the end of its sentence with
     * "[withheld]" and the rest of the finding is kept.
     */
    private const COMMANDS = [
        '/\b(?:curl|wget)\b[^|\n]*\|[^.!?\n]*/iu',                          // curl ... | sh
        '/\|\s*(?:sudo\s+)?(?:ba|z|da|k)?sh\b[^.!?\n]*/iu',                   // ... | sh
        '/(?:&&|\|\||\$\(|`)[^.!?\n]*/u',                                  // shell chaining, command substitution, backticks
        '/;\s*(?:rm|sudo|curl|wget|chmod|chown|dd|kill|mkfs|nc|sh|bash)\b[^.!?\n]*/iu',
        '/\brm\s+-[^.!?\n]*/iu',
        '/\bpaste\b[^.!?\n]{0,60}?\b(?:token|password|passwd|secret|key|credential)s?\b[^.!?\n]*/iu',
        '/\brotate\s+all\b[^.!?\n]*/iu',
        '/\b(?:sudo|chmod|chown|mkfs)\b[^.!?\n]*/iu',
        '/\bdrop\s+(?:table|database|schema)\b[^.!?\n]*/iu',
        '/\btruncate\s+table\b[^.!?\n]*/iu',
        '/\bdelete\s+from\b[^.!?\n]*/iu',
        '/\bkubectl\s+(?:delete|exec|apply|patch|scale|replace|edit|drain|cordon|rollout\s+undo)\b[^.!?\n]*/iu',
        '/\bsystemctl\s+(?:stop|restart|disable|mask|kill|isolate)\b[^.!?\n]*/iu',
        '/\bservice\s+[\w.@-]+\s+(?:stop|restart)\b[^.!?\n]*/iu',
        '/\b(?:iptables|ip6tables|nft)\s+(?:-[FXZ]\b|flush\b)[^.!?\n]*/iu',
        '/\bufw\s+(?:disable|reset)\b[^.!?\n]*/iu',
        '/\b(?:curl|wget)\b[^\n]*?(?:\s-[a-z]*[dF]\s*\S*@|--(?:data[\w-]*|form|post-file|upload-file)\b|\s-T\b)[^.!?\n]*/iu', // upload of a local file
        '/\b(?:nc|ncat|netcat)\s(?:[^\n]*?\s)?-[a-z]*e\b[^.!?\n]*/iu',
        '/\bdd\s+if=[^.!?\n]*/iu',
        '/\b(?:shutdown|reboot|halt|poweroff)\s+(?:-\w+|now|\+?\d+)\b[^.!?\n]*/iu',
        '/\b(?:and|then|run|execute)\s+(?:a\s+)?(?:reboot|halt|poweroff|shutdown)\s*(?=[.!?;,]|$)/iu',
        '/\b(?:killall|pkill)\b[^.!?\n]*/iu',
        '/\bkill\s+-(?:9|KILL|SIGKILL)\b[^.!?\n]*/iu',
        '~>\s*/dev/(?!null\b)[^.!?\n]*~iu',
        '/\bgit\s+(?:push\b[^.!?\n]*?(?:--force|\s-f\b)|reset\s+--hard|clean\s+-[a-z]*f)[^.!?\n]*/iu',
        '/\bdocker\s+(?:container\s+|image\s+|system\s+|volume\s+)?(?:rm|rmi|stop|kill|prune)\b[^.!?\n]*/iu',
        '/\bbase64\s+(?:-d|--decode)\b[^.!?\n]*/iu',
        '/\b(?:python3?|perl|ruby)\s+-[ce]\b[^.!?\n]*/iu',
    ];

    /** The no-URL rule of the prompts, enforced on every text field: links, www. hosts and e-mail addresses. */
    private const LINKS = [
        '~\b[a-z][a-z0-9+.-]*://\S*~iu',
        '~\bwww\.\S*~iu',
        '~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u',
    ];

    /** Replaces matching spans with "[withheld]", on the text as given and on its canonical form (R-6). */
    public static function withhold(string $v, bool $advice = true): string
    {
        // The rules also run on the canonical text (format characters stripped, NFKC, percent and entity decoded): that is what the
        // Redactor and the renderers produce downstream, so `r<ZWSP>m -rf` must not pass here and be repaired there (R-6).
        $canon = Normalizer::canonical($v);
        $rules = $advice ? [...self::LINKS, ...self::COMMANDS] : self::LINKS;
        foreach ($rules as $re) {
            $v = (string) preg_replace($re, self::WITHHELD, $v);
            $canon = (string) preg_replace($re, self::WITHHELD, $canon);
        }
        if (str_contains($canon, self::WITHHELD)) {
            $v = $canon;
        }

        return str_contains($v, self::WITHHELD) ? rtrim((string) preg_replace('/(?:\[withheld\]\s*){2,}/u', self::WITHHELD . ' ', $v)) : $v;
    }
}
