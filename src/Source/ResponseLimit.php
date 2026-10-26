<?php

declare(strict_types=1);

namespace Watcher\Source;

use Psr\Http\Message\ResponseInterface;

/**
 * Bounds what one backend reply may cost (A4-10): a declared Content-Length above MAX_BYTES aborts at the headers, a
 * decoded body above it is refused before json_decode, and JSON deeper than JSON_DEPTH is not parsed. The body is also counted
 * while it streams in (decoded bytes, see CappedSink), so a chunked reply without Content-Length or a gzip bomb never sits in memory whole.
 */
final class ResponseLimit
{
    public const MAX_BYTES = 16 * 1024 * 1024;
    public const JSON_DEPTH = 64;

    /**
     * @param \ArrayObject<string, bool> $tripped set to ['large' => true] when the headers aborted the request, so the caller can
     *        name the cause (the HTTP layer only reports a failed request)
     * @return array{on_headers: \Closure(mixed): void, decode_content: bool, sink: CappedSink}
     */
    public static function options(\ArrayObject $tripped, int $max = self::MAX_BYTES): array
    {
        return [
            'on_headers' => static function (mixed $r) use ($tripped, $max): void {
                if ($r instanceof ResponseInterface && self::declared($r) > $max) {
                    $tripped['large'] = true;
                    throw new SourceUnavailable('response too large', true);
                }
            },
            'decode_content' => true, // so the sink counts decoded bytes
            'sink' => new CappedSink($max, $tripped), // one per call: callers build the options inside their retry closure
        ];
    }

    /**
     * The Content-Length the server declared: 0 when absent or not digits, PHP_INT_MAX when too long to be a size.
     * A header is remote input, so it is never cast as is: an out-of-range cast warns.
     */
    private static function declared(ResponseInterface $response): int
    {
        $length = trim($response->getHeaderLine('Content-Length'));
        if (preg_match('/^\d+$/D', $length) !== 1) {
            return 0;
        }

        return strlen($length) > 15 ? PHP_INT_MAX : (int) $length;
    }

    /**
     * @return array<mixed>
     * @throws SourceUnavailable
     */
    public static function decode(ResponseInterface $response, string $name, int $max = self::MAX_BYTES): array
    {
        $body = $response->getBody();
        if (self::declared($response) > $max || ($body->getSize() ?? 0) > $max) {
            throw new SourceUnavailable($name . ' response too large', true);
        }
        $data = json_decode((string) $body, true, self::JSON_DEPTH);

        return is_array($data) ? $data : throw new SourceUnavailable($name . ' returned a non-JSON body');
    }
}
