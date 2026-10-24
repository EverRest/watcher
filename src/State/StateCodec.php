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
     * Budget for the JSON a value may hold, inflated or plain: a corrupt or hostile value must not exhaust memory, and
     * json_decode's allocation is not catchable, so the size is checked on the text BEFORE decoding. Bytes alone do not
     * bound it (4 MB of `[0],` decodes to ~237 MB), so the structure is budgeted too: elements (commas plus containers)
     * and containers (`[` and `{`) are counted on the text. The worst accepted value decodes to roughly 16 MB.
     * A real snapshot is ~150 KB; the default group_cap (5000) is ~10k elements. A snapshot of more than ~90k groups
     * is refused at write (StateValueTooLarge). Raise the numbers only together with the workers' memory_limit.
     * The counts also include punctuation inside strings (conservative: it can only refuse more).
     */
    public const MAX_BYTES = 4 * 1024 * 1024;

    public const MAX_ELEMENTS = 100_000;

    public const MAX_CONTAINERS = 16_000;

    private const DEPTH = 64;

    private const CHUNK = 1024;

    private const LEVEL = 6;

    /** @param array<mixed> $value $minBytes 0 = never compress */
    public static function encode(array $value, int $minBytes, bool $zlib = true): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (!self::fits($json)) { // a value the reader would refuse must not be written: it would read back as missing
            throw new StateValueTooLarge('state value exceeds the readable size or structure budget');
        }
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
        if (inflate_get_read_len($ctx) < strlen($z)) { // bytes after the end of the stream, wherever they fall in a slice
            throw new UnexpectedValueException('compressed state entry has trailing bytes');
        }

        return $out;
    }

    /** Whether json_decode of this text is within the byte and structure budget (see MAX_BYTES). */
    public static function fits(string $json): bool
    {
        if (strlen($json) > self::MAX_BYTES) {
            return false;
        }
        $containers = substr_count($json, '[') + substr_count($json, '{');

        return $containers <= self::MAX_CONTAINERS && $containers + substr_count($json, ',') <= self::MAX_ELEMENTS;
    }

    /** @return array<mixed>|null */
    private static function json(string $json, bool $strict): ?array
    {
        if (!self::fits($json)) { // before json_decode: its allocation is not catchable
            throw new UnexpectedValueException('state entry is over the size or structure budget');
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
