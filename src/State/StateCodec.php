<?php

declare(strict_types=1);

namespace Watcher\State;

use JsonException;
use UnexpectedValueException;

/**
 * Wire format of RedisStateStore values: JSON, or `z1:` + raw zlib bytes (Redis strings are binary-safe) when the JSON is
 * larger than the threshold. Only the Redis store uses it; the file and PDO stores keep plain JSON.
 */
final class StateCodec
{
    public const PREFIX = 'z1:';

    /** Hard cap on what a compressed entry may inflate to: a corrupt or hostile value must not exhaust memory. */
    public const MAX_BYTES = 16 * 1024 * 1024;

    private const LEVEL = 6;

    /** @param array<mixed> $value $minBytes 0 = never compress */
    public static function encode(array $value, int $minBytes, bool $zlib = true): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if ($minBytes > 0 && $zlib && strlen($json) > $minBytes) {
            return self::PREFIX . gzcompress($json, self::LEVEL);
        }

        return $json;
    }

    /**
     * Plain JSON that does not decode is null (as before); a `z1:` entry that cannot be read is an exception with a fixed message.
     *
     * @return array<mixed>|null
     * @throws UnexpectedValueException
     */
    public static function decode(string $raw, bool $zlib = true): ?array
    {
        if (!str_starts_with($raw, self::PREFIX)) {
            return self::json($raw, false);
        }
        if (!$zlib) {
            throw new UnexpectedValueException('compressed state entry and ext-zlib is not loaded');
        }
        set_error_handler(static fn (): bool => true); // gzuncompress warns on corruption and on the size cap
        try {
            $json = gzuncompress(substr($raw, strlen(self::PREFIX)), self::MAX_BYTES);
        } finally {
            restore_error_handler();
        }
        // zlib rounds the length limit up to its buffer size, so the exact cap is checked on the result as well.
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            throw new UnexpectedValueException('compressed state entry is corrupt or too large');
        }

        return self::json($json, true);
    }

    /** @return array<mixed>|null */
    private static function json(string $json, bool $strict): ?array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $strict ? throw new UnexpectedValueException('compressed state entry is not JSON') : null;
        }
        if (!is_array($value)) {
            return $strict ? throw new UnexpectedValueException('compressed state entry is not an object') : null;
        }

        return $value;
    }
}
