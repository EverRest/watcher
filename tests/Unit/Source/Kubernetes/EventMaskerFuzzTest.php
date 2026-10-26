<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use PHPUnit\Framework\TestCase;
use Watcher\Source\Kubernetes\EventMasker;

/**
 * Property: a secret canary written into any context the masker claims to support never survives it, whatever the quote style,
 * the encoding of the delimiters or the length of the name. Deterministic (seeded), so a failure names its exact input.
 */
final class EventMaskerFuzzTest extends TestCase
{
    private const CANARY = 'CANARYxq9zv7';

    private const KEYWORDS = ['secret', 'secrets', 'configmap', 'configmaps', 'container', 'containers', 'endpoint', 'endpoints', 'service', 'services', 'serviceaccount', 'serviceaccounts', 'persistentvolumeclaim', 'persistentvolumeclaims', 'pvc', 'pvcs', 'volume', 'volumes', 'claim', 'pod', 'image'];

    /** @return list<callable(string): string> */
    private static function contexts(): array
    {
        $quote = static fn (string $open, string $close): callable => static fn (string $n): string => "Error: secret {$open}{$n}{$close} not found";
        $kinds = ['secret', 'configmap', 'endpoint', 'service', 'serviceaccount', 'persistentvolumeclaim', 'pvc'];

        return [
            $quote('"', '"'), $quote("\u{201C}", "\u{201D}"), $quote("\u{201E}", "\u{201C}"), $quote("\u{201E}", "\u{201D}"),
            $quote("\u{00AB}", "\u{00BB}"), $quote("\u{2018}", "\u{2019}"), $quote("\u{2019}", "\u{2019}"), $quote("\u{201A}", "\u{2018}"), $quote("\u{300C}", "\u{300D}"), $quote("\u{300E}", "\u{300F}"), $quote("\u{275D}", "\u{275E}"), $quote("\u{2032}", "\u{2032}"), $quote("\u{FF02}", "\u{FF02}"), $quote("\u{FF07}", "\u{FF07}"), $quote("\u{2039}", "\u{203A}"), $quote('`', '`'), $quote('(', ')'), $quote('[', ']'), $quote('<', '>'),
            static fn (string $n): string => "Failed to update endpoint prod/{$n}: conflict",
            static fn (string $n): string => "{$kinds[random_int(0, 0) + (strlen($n) % 7)]}/{$n} missing",
            static fn (string $n): string => "back-off restarting failed container={$n} pod=api-1",
            static fn (string $n): string => "container id={$n} started",
            static fn (string $n): string => "container id: {$n} started",
            static fn (string $n): string => "Created container {$n}",
            static fn (string $n): string => "containers {$n}, other-{$n} failed",
            static fn (string $n): string => "secrets {$n} not found",
            static fn (string $n): string => "persistentvolumeclaim {$n} not found",
            static fn (string $n): string => "pvc:{$n} not found",
            static fn (string $n): string => "Unable to attach volume {$n}",
            static fn (string $n): string => "Get https://admin:{$n}@reg.io/v2/ failed",
            static fn (string $n): string => "dial admin:{$n}@db.internal:5432 refused",
            static fn (string $n): string => "Pulled docker://admin:{$n}@reg.io/app",
            static fn (string $n): string => "Back-off pulling image {$n}",
            static fn (string $n): string => "taint {dedicated: {$n}} not tolerated",
            // a URL after every kind of prefix character
            static fn (string $n): string => 'x ' . ['-', '.', '+', '1', '_', '/', '"', "'", '(', '=', 'a-', '1-', 'b.'][strlen($n) % 13] . "https://h.example/p?token={$n}",
            static fn (string $n): string => 'a-b-https://u:' . $n . '@h.example/',
            static fn (string $n): string => 'x-docker://u:' . $n . '@h.example/x',
            static fn (string $n): string => ' -https://h.example/p?token=' . $n,
            static fn (string $n): string => "container <{$n}> failed",
            static fn (string $n): string => "secret <{$n}> not found",
            static fn (string $n): string => "secret\u{00AD}{$n} not found",
            static fn (string $n): string => "container\u{200B}{$n} failed",
            static fn (string $n): string => "configmap\x00{$n} missing",
            static fn (string $n): string => "secret%20{$n} not found",
            static fn (string $n): string => "{\"secret\":{$n}} not found",
            // credential-shaped tokens whose userinfo holds a keyword followed by a separator the rewrites treat specially
            static fn (string $n): string => 'https://u:' . self::KEYWORDS[strlen($n) % count(self::KEYWORDS)] . ['%20', '+', '%09', '%0a', '%2520'][strlen($n) % 5] . $n . '@host.example/x',
            static fn (string $n): string => 'docker://' . self::KEYWORDS[strlen($n) % count(self::KEYWORDS)] . '%20x:' . $n . '@h.example',
            // stray quotes: one open quote and no close
            static fn (string $n): string => "secret \"{$n} not found",
            static fn (string $n): string => "secret \u{201C}{$n} and more text",
            static fn (string $n): string => "mixed \"x\u{201C} secret \u{00AB}{$n}\u{2039} y",
        ];
    }

    /** @return list<callable(string): string> */
    private static function variants(): array
    {
        $fullwidth = static fn (string $s): string => (string) preg_replace_callback('/[!-~]/', static fn (array $m): string => mb_chr(ord($m[0]) + 0xFEE0, 'UTF-8'), $s);

        return [
            static fn (string $s): string => $s,
            static fn (string $s): string => $s,
            static fn (string $s): string => str_replace(['@', ':'], ['%40', '%3A'], $s),
            static fn (string $s): string => str_replace('@', '&#64;', $s),
            static fn (string $s): string => str_replace(self::CANARY, substr(self::CANARY, 0, 6) . "\u{200B}" . substr(self::CANARY, 6), $s),
            static fn (string $s): string => $fullwidth($s),
        ];
    }

    public function testNoCanaryEverSurvivesAnySupportedContext(): void
    {
        mt_srand(20261024);
        $contexts = self::contexts();
        $variants = self::variants();
        $masker = new EventMasker(['shop']);
        $started = microtime(true);
        $bad = [];
        for ($i = 0; $i < 2000; ++$i) {
            $length = mt_rand(1, 400);
            $name = self::CANARY . substr(str_repeat('ab-c.d_e1', 50), 0, $length);
            if (mt_rand(0, 3) === 0) {
                $name = substr(str_repeat('x-y', 80), 0, mt_rand(0, 60)) . $name;
            }
            $message = $variants[mt_rand(0, count($variants) - 1)]($contexts[$i % count($contexts)]($name));
            $out = $masker->mask($message, [], []);
            $plain = str_replace("\u{200B}", '', $out);
            foreach ([self::CANARY, 'xq9zv7', 'ＣＡＮＡＲＹ'] as $needle) {
                if (str_contains($plain, $needle)) {
                    $bad[$message] = $out;
                }
            }
        }

        self::assertLessThan(5.0, microtime(true) - $started, 'the fuzz run is fast');
        self::assertSame([], array_slice($bad, 0, 3, true), count($bad) . ' inputs leaked; first three shown');
    }

    /** Every keyword followed by every separator, inside every credentialed shape: the password never survives, whatever the rewrites do with the keyword. */
    public function testNoCanarySurvivesAKeywordAndSeparatorInsideACredentialedToken(): void
    {
        $templates = [
            'https://u:%s@host.example/x', 'https://%s:pw@host.example/', 'docker://u:%s@h.example', 'dial u:%s@db.internal:5432', '"https://u:%s@host.example"',
            'url=https://u:%s@host.example', '{"url":"https://u:%s@host.example"}', 'postgres://app:%s@db.internal/x', 'https://u:p%s@host.example?x=1',
        ];
        $masker = new EventMasker(['shop']);
        $bad = [];
        foreach (self::KEYWORDS as $keyword) {
            foreach (['%20', '+', '%09', '%0a', '%0A', '%2520', '%250a', '%20%20', "\u{00AD}", "\u{200B}"] as $separator) {
                foreach ($templates as $template) {
                    foreach (self::variants() as $variant) {
                        $message = $variant('Pull failed: ' . sprintf($template, $keyword . $separator . self::CANARY));
                        $out = str_replace("\u{200B}", '', $masker->mask($message, [], []));
                        foreach ([self::CANARY, 'xq9zv7', 'ＣＡＮＡＲＹ', 'host.example', 'db.internal'] as $needle) {
                            if (str_contains($out, $needle)) {
                                $bad[$message] = $out;
                            }
                        }
                    }
                }
            }
        }

        self::assertSame([], array_slice($bad, 0, 3, true), count($bad) . ' inputs leaked; first three shown');
    }

    /**
     * Differential: each rewrite the masker applies to the text first, written out here as its own function. If the masker hides the
     * canary in the text as it is, it must hide it just as well after the rewrite: a rewrite may only ever add masking. A token with the
     * shape of a credential (a ':' before an '@', not a sha256 digest) is never rewritten: the masker has already replaced it whole
     * by then, and a test-side rewrite that put a space into it would test a different message.
     */
    public function testNoRewriteRuleMakesTheMaskerRevealMore(): void
    {
        $words = implode('|', self::KEYWORDS);
        $rules = [
            'invisible glue' => static fn (string $t): string => (string) preg_replace('/\b(' . $words . ')[\p{Cf}\p{Cc}\p{Cn}]++(?=[^\p{Cf}\p{Cc}\p{Cn}\s=:])/iu', '$1 ', $t),
            '%XX letters' => static fn (string $t): string => (string) preg_replace_callback('/%(3[0-9]|4[1-9a-f]|5[0-9a]|6[1-9a-f]|7[0-9a])/i', static fn (array $m): string => chr((int) hexdec($m[1])), $t),
            '%20 after a keyword' => static fn (string $t): string => (string) preg_replace('/\b(' . $words . ')%20(?=\S)/i', '$1 ', $t),
            'JSON key' => static fn (string $t): string => (string) preg_replace('/["\'](' . $words . ')["\']\s*:\s*(?=\S)/i', '$1=', $t),
            'plus as space' => static fn (string $t): string => str_replace('+', ' ', $t),
            'whitespace collapse' => static fn (string $t): string => (string) preg_replace('/\s+/u', ' ', $t),
            'fullwidth fold' => static fn (string $t): string => class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($t, \Normalizer::FORM_KC) : $t,
            'percent decode' => static fn (string $t): string => rawurldecode($t),
        ];
        $contexts = [];
        foreach (self::KEYWORDS as $keyword) {
            foreach (['%20', '+', '%09', '%0a', '%2520', ' ', '=', ':', ': ', '/'] as $separator) {
                $name = $keyword . $separator . self::CANARY;
                foreach ([
                    "{$name} not found", "error: {$name}", "\"{$name}\" missing", "'{$name}' missing", "x {$name}, y", "{\"{$keyword}\":{$separator}" . self::CANARY . '}',
                    "https://u:{$name}@host.example/x", "https://{$name}:pw@h.example", "docker://u:{$name}@h.example", "dial u:{$name}@db.internal:5432",
                    "\"https://u:{$name}@host.example\"", "url=https://u:{$name}@host.example", "Back-off pulling image {$name}", "container {$name} failed",
                ] as $context) {
                    $contexts[] = $context;
                }
            }
        }
        $masker = new EventMasker(['shop']);
        $bad = [];
        foreach ($contexts as $context) {
            if (str_contains($masker->mask($context, [], []), self::CANARY)) {
                continue; // shown as it is: nothing to compare (names the masker keeps by design)
            }
            foreach ($rules as $name => $rule) {
                $rewritten = (string) preg_replace_callback('/\S+/u', static fn (array $m): string => preg_match('/:.*@(?!sha256:)/s', $m[0]) === 1 ? $m[0] : $rule($m[0]), $context);
                if (str_contains($masker->mask($rewritten, [], []), self::CANARY)) {
                    $bad[$name . ' | ' . $context] = $rewritten;
                }
            }
        }

        self::assertGreaterThan(1000, count($contexts));
        self::assertSame([], array_slice($bad, 0, 5, true), count($bad) . ' rewrites revealed more; first five shown');
    }

    public function testHostileTwoThousandCharStringsDoNotBacktrack(): void
    {
        $masker = new EventMasker(['shop']);
        foreach ([
            str_repeat('secret a-', 220), str_repeat('container=', 200), str_repeat('containers a, ', 140), str_repeat('endpoint x/', 180),
            str_repeat('`', 1990), str_repeat("\u{201C}a", 600), str_repeat('pulling image ', 140), str_repeat('pvc:', 480), str_repeat('Secret/', 280),
            str_repeat('"', 1999), str_repeat("\u{00AB}\u{201C}`\"", 480), str_repeat('{', 1999), str_repeat('<a', 990), str_repeat('a:b@', 480),
            'secret ' . str_repeat('a', 1990), str_repeat('http://', 280), str_repeat('%4', 660), str_repeat('&#', 990),
        ] as $hostile) {
            $t = microtime(true);
            $masker->mask($hostile, [], []);

            self::assertLessThan(0.25, microtime(true) - $t, substr($hostile, 0, 20));
        }
    }
}
