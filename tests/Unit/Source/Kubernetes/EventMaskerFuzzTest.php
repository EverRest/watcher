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
