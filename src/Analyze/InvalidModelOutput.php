<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use RuntimeException;

/** Model output violated the schema. The message never carries more than a short excerpt of model text. */
final class InvalidModelOutput extends RuntimeException
{
    public static function at(string $path, string $problem, mixed $got = null): self
    {
        $excerpt = is_scalar($got) ? ' (got ' . self::cut(is_float($got) ? var_export($got, true) : (string) $got) . ')' : '';

        return new self($path . ': ' . $problem . $excerpt);
    }

    private static function cut(string $s): string
    {
        return mb_strlen($s) > 80 ? mb_substr($s, 0, 80) . '…' : $s;
    }
}
