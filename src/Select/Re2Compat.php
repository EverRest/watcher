<?php

declare(strict_types=1);

namespace Watcher\Select;

/**
 * Conservative check that a PCRE body is also valid RE2 (Loki's engine) with the same meaning. Anything PCRE-only
 * (lookaround, backreferences, atomic/possessive, \K, recursion, conditionals, verbs, comments) returns false; false
 * only means "not pushed to the backend", the rule is still evaluated locally.
 */
final class Re2Compat
{
    public static function supports(string $body): bool
    {
        $n = strlen($body);
        $inClass = false;
        $quantified = false; // previous token was an unescaped quantifier outside a class
        for ($i = 0; $i < $n; ++$i) {
            $c = $body[$i];
            if ($c === '\\') {
                $next = $body[$i + 1] ?? '';
                if ($next === '' || preg_match('/[1-9gkKGRZXCN]/', $next) === 1) {
                    return false;
                }
                ++$i;
                $quantified = false;
                continue;
            }
            if ($inClass) {
                $inClass = $c !== ']';
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                $quantified = false;
                if (($body[$i + 1] ?? '') === ']') {
                    ++$i; // a leading ] is a literal
                } elseif (($body[$i + 1] ?? '') === '^' && ($body[$i + 2] ?? '') === ']') {
                    $i += 2;
                }
                continue;
            }
            if ($c === '(') {
                $rest = substr($body, $i + 1, 3);
                if ($rest !== '' && $rest[0] === '*') {
                    return false;
                }
                if ($rest !== '' && $rest[0] === '?' && preg_match('/^\?(?::|P<|<[A-Za-z_]|[imsU]*-?[imsU]*[:)])/', $rest . ($body[$i + 4] ?? '')) !== 1) {
                    return false;
                }
                $quantified = false;
                continue;
            }
            if ($quantified && $c === '+') {
                return false; // possessive
            }
            $quantified = $c === '*' || $c === '+' || $c === '?' || $c === '}';
            if ($quantified && $c !== '}' && ($body[$i + 1] ?? '') === '?') {
                ++$i; // lazy
                $quantified = false;
            }
        }

        return !$inClass;
    }
}
