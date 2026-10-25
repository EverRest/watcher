<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/**
 * The word-level privacy floor for a template that is shown, stored or proposed as a rule (review M-2). A template folds many messages,
 * but a word that never varies in it may still be one person's name. A literal word stays readable only when it is common log
 * vocabulary or was seen at `hygiene.literal_min_sites` different sites in the same pass (then it is a word of this log, not of one
 * writer); every other word becomes `<w>`. Placeholders (`<N>`, `<*>`, `<EMAIL>`) are untouched.
 * Trade-off: a domain word used at one site only (`tenant Acme`) is hidden too; name the site's directory in `hygiene.site_allow_dirs`
 * or lower `literal_min_sites` to see more.
 */
final class LiteralFloor
{
    private const VOCABULARY = 'the a an and or of to in on at for from with by is are was were be been not no yes failed failure fail error '
        . 'errors warning warn exception user users login logged logout request response received sent send sending '
        . 'started start stopped stop completed complete created create deleted delete updated update loaded load saved '
        . 'save connection connect connected disconnected timeout timed out retry retrying attempt attempts reason '
        . 'status code id name email token password session invalid missing unable cannot could found unknown denied '
        . 'access permission permissions payment order invoice service job queue task processing processed success '
        . 'successful result value values data file path host url api call called database db query cache memory disk '
        . 'space limit exceeded rate too many slow took ms seconds bytes count total after before while during when if '
        . 'then address phone tenant account customer client server message event handler worker process thread upload '
        . 'download import export sync webhook notification mail sms unexpected null empty expired refused rejected '
        . 'unauthorized forbidden authentication authorization auth write exhausted available unavailable released '
        . 'acquired transaction commit rollback migration migrated controller repository manager factory provider helper '
        . 'util utils model view listener logger log audit middleware command config settings validator mapper builder '
        . 'parser writer scanner notifier dispatcher resolver gateway adapter policy entity storage done end ended finished run running '
        . 'check checked lookup health heartbeat schedule scheduled now ';

    /** @param array<string, true> $common words seen at enough different sites in this pass (lower case) */
    public function __construct(private readonly array $common)
    {
    }

    /** Exactly the placeholders the Normalizer and the scan write; anything else in angle brackets is text a log writer chose. */
    private const PLACEHOLDER = '/(<(?:\*|w|N|ID|EMAIL|HOST|URL|IP|TOKEN|PAN|PATH|USER|NAME|STR|TRUNC|UNSCANNABLE)(?::[A-Za-z0-9._-]+)?>)/';

    public function mask(string $template): string
    {
        $parts = preg_split(self::PLACEHOLDER, $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$template];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue; // an exact placeholder (the odd pieces are the captured delimiters)
            }
            $parts[$i] = (string) preg_replace_callback('/\p{L}[\p{L}\p{N}_\']*/u', fn (array $m): string => $this->known($m[0]) ? $m[0] : '<w>', $part);
        }

        return implode('', $parts);
    }

    /** Whether a word is common log vocabulary or code structure (also used for the words of a file name). */
    public static function isVocabulary(string $word): bool
    {
        static $set = null;
        $set ??= array_fill_keys(explode(' ', trim(self::VOCABULARY)), true);
        $w = mb_strtolower($word);

        return isset($set[$w]) || (str_ends_with($w, 's') && isset($set[substr($w, 0, -1)]));
    }

    private function known(string $word): bool
    {
        return self::isVocabulary($word) || isset($this->common[mb_strtolower($word)]);
    }
}
