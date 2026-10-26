<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

/** Values a remote reply or a stored record can hold where a number or a string is expected. */
final class HostileValues
{
    /** @return array<string, array{mixed}> */
    public static function all(): array
    {
        return [
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'empty string' => [''],
            'text' => ['abc'],
            'numeric text' => ['12'],
            'exponent text' => ['1e400'],
            'zero' => [0],
            'negative' => [-1],
            'fraction' => [1.5],
            'huge float' => [1e308],
            'huge int' => [PHP_INT_MAX],
            'huge digits' => ['99999999999999999999999'],
            'nested list' => [['x']],
            'nested map' => [['a' => ['b' => 1]]],
            'empty list' => [[]],
        ];
    }
}
