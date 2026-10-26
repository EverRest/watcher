<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use InvalidArgumentException;

/**
 * A Kubernetes label selector (equality and set-based) evaluated in code, so attribution never depends on how coarse
 * the API's own filter is. Grammar: comma-separated `key`, `!key`, `key=v`, `key==v`, `key!=v`, `key in (a,b)`, `key notin (a,b)`.
 */
final class LabelSelector
{
    private const NAME = '/^([A-Za-z0-9]([-A-Za-z0-9_.]*[A-Za-z0-9])?\/)?[A-Za-z0-9]([-A-Za-z0-9_.]*[A-Za-z0-9])?$/';
    private const VALUE = '/^([A-Za-z0-9]([-A-Za-z0-9_.]*[A-Za-z0-9])?)?$/';

    /** @param list<array{op: string, key: string, values: list<string>}> $requirements */
    private function __construct(private readonly string $text, private readonly array $requirements)
    {
    }

    /** @throws InvalidArgumentException */
    public static function parse(string $selector): self
    {
        $text = trim($selector);
        if ($text === '') {
            throw new InvalidArgumentException('empty selector');
        }
        $requirements = [];
        foreach (self::split($text) as $part) {
            $requirements[] = self::requirement(trim($part));
        }

        return new self($text, $requirements);
    }

    public function text(): string
    {
        return $this->text;
    }

    /** @param array<string, string> $labels */
    public function matches(array $labels): bool
    {
        foreach ($this->requirements as ['op' => $op, 'key' => $key, 'values' => $values]) {
            $has = array_key_exists($key, $labels);
            $ok = match ($op) {
                'exists' => $has,
                'absent' => !$has,
                'eq', 'in' => $has && in_array($labels[$key], $values, true),
                default => !$has || !in_array($labels[$key], $values, true), // ne, notin
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($text) as $ch) {
            if ($ch === '(') {
                ++$depth;
            } elseif ($ch === ')') {
                --$depth;
            }
            if ($depth < 0) {
                throw new InvalidArgumentException('unbalanced parenthesis');
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if ($depth !== 0) {
            throw new InvalidArgumentException('unbalanced parenthesis');
        }
        $parts[] = $current;

        return $parts;
    }

    /** @return array{op: string, key: string, values: list<string>} */
    private static function requirement(string $r): array
    {
        if ($r === '') {
            throw new InvalidArgumentException('empty requirement');
        }
        if (preg_match('/^(\S+)\s+(in|notin)\s*\((.*)\)$/', $r, $m) === 1) {
            $values = array_map('trim', explode(',', $m[3]));
            foreach ($values as $v) {
                self::value($v, true);
            }

            return ['op' => $m[2], 'key' => self::key($m[1]), 'values' => $values];
        }
        if (preg_match('/^([^=!\s]+)\s*(==|=|!=)\s*(.*)$/', $r, $m) === 1) {
            $value = trim($m[3]);

            return ['op' => $m[2] === '!=' ? 'ne' : 'eq', 'key' => self::key($m[1]), 'values' => [self::value($value, false)]];
        }
        if ($r[0] === '!') {
            return ['op' => 'absent', 'key' => self::key(substr($r, 1)), 'values' => []];
        }

        return ['op' => 'exists', 'key' => self::key($r), 'values' => []];
    }

    private static function key(string $key): string
    {
        $name = str_contains($key, '/') ? substr($key, (int) strrpos($key, '/') + 1) : $key;
        if (strlen($key) > 253 || strlen($name) > 63 || preg_match(self::NAME, $key) !== 1) {
            throw new InvalidArgumentException('invalid label key');
        }

        return $key;
    }

    private static function value(string $value, bool $nonEmpty): string
    {
        if (strlen($value) > 63 || preg_match(self::VALUE, $value) !== 1 || ($nonEmpty && $value === '')) {
            throw new InvalidArgumentException('invalid label value');
        }

        return $value;
    }
}
