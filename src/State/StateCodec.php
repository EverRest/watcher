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

    /**
     * Hard cap on the JSON a value may hold, inflated or plain: a corrupt or hostile value must not exhaust memory
     * (a `0,0,0,...` array costs ~16 PHP bytes per 2 JSON bytes, so the cap bounds json_decode too). A real snapshot is
     * ~150 KB at group_cap; 4 MB leaves a wide margin. Raise it only together with the memory_limit of the workers.
     */
    public const MAX_BYTES = 4 * 1024 * 1024;

    private const DEPTH = 64;

    private const CHUNK = 1024;

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
        $json = self::inflate(substr($raw, strlen(self::PREFIX)));

        return self::json($json, true);
    }

    /**
     * Inflates in small input slices and stops the moment the output passes the cap, so a bomb never costs more than
     * cap + what one slice can expand to (~1 MB at zlib's 1032:1 limit). A truncated stream or bytes after its end are corrupt.
     *
     * @throws UnexpectedValueException
     */
    private static function inflate(string $z): string
    {
        $ctx = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($ctx === false) {
            throw new UnexpectedValueException('compressed state entry is corrupt');
        }
        $out = '';
        set_error_handler(static fn (): bool => true); // inflate_add warns on corruption
        try {
            for ($i = 0, $n = strlen($z); $i < $n; $i += self::CHUNK) {
                if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                    throw new UnexpectedValueException('compressed state entry has trailing bytes');
                }
                $part = inflate_add($ctx, substr($z, $i, self::CHUNK), ZLIB_NO_FLUSH);
                if ($part === false) {
                    throw new UnexpectedValueException('compressed state entry is corrupt');
                }
                $out .= $part;
                if (strlen($out) > self::MAX_BYTES) {
                    throw new UnexpectedValueException('compressed state entry is too large');
                }
            }
        } catch (\ValueError) {
            throw new UnexpectedValueException('compressed state entry is corrupt');
        } finally {
            restore_error_handler();
        }
        if (inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            throw new UnexpectedValueException('compressed state entry is truncated');
        }

        return $out;
    }

    /** @return array<mixed>|null */
    private static function json(string $json, bool $strict): ?array
    {
        if (strlen($json) > self::MAX_BYTES) { // before json_decode: its allocation is not catchable
            return $strict ? throw new UnexpectedValueException('compressed state entry is too large') : null;
        }
        try {
            $value = json_decode($json, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $strict ? throw new UnexpectedValueException('compressed state entry is not JSON') : null;
        }
        if (!is_array($value)) {
            return $strict ? throw new UnexpectedValueException('compressed state entry is not an object') : null;
        }

        return $value;
    }
}
