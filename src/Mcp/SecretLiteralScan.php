<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Watcher\Config\KeyCatalog;

/**
 * FR-066: finds string literals assigned to a key the catalog marks `secret` (`'webhook' => 'https://...'`) with the PHP tokenizer and
 * reports the key path only. A heuristic with a stated limit: computed keys and values built at run time are not seen. Never returns a literal.
 */
final class SecretLiteralScan
{
    public static function available(): bool
    {
        return function_exists('token_get_all');
    }

    /** @return list<string> key paths holding a literal secret, sorted */
    public static function scan(string $php): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            $tokens = array_values(array_filter(token_get_all($php), static fn (mixed $t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        } catch (\Throwable) {
            return [];
        }
        $found = [];
        $stack = []; // key of each open array
        $pending = null;
        $n = count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $t = $tokens[$i];
            if ($t === '[' || (is_array($t) && $t[0] === T_ARRAY)) {
                $stack[] = $pending;
                $pending = null;
                if (is_array($t) && ($tokens[$i + 1] ?? null) === '(') {
                    ++$i;
                }
                continue;
            }
            if ($t === '(') {
                $stack[] = null; // a call's parentheses: not an array level
                continue;
            }
            if ($t === ']' || $t === ')') {
                array_pop($stack);
                continue;
            }
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
                $key = substr($t[1], 1, -1);
                $path = implode('.', [...array_filter($stack, static fn (?string $k): bool => $k !== null), $key]);
                $v = $tokens[$i + 2] ?? null;
                if (is_array($v) && $v[0] === T_CONSTANT_ENCAPSED_STRING && strlen($v[1]) > 2 && (KeyCatalog::find($path)['secret'] ?? false)) {
                    $found[$path] = true;
                }
                $pending = $key;
                $i += 1;
                continue;
            }
        }
        $out = array_keys($found);
        sort($out);

        return $out;
    }
}
