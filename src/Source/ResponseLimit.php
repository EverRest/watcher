<?php

declare(strict_types=1);

namespace Watcher\Source;

use Psr\Http\Message\ResponseInterface;

/**
 * Bounds what one backend reply may cost (A4-10): a declared Content-Length above MAX_BYTES aborts at the headers, a
 * decoded body above it is refused before json_decode, and JSON deeper than JSON_DEPTH is not parsed.
 * ponytail: a gzip bomb is only caught once the body is decoded (Guzzle has no streaming size cap); a sink wrapper if that bites.
 */
final class ResponseLimit
{
    public const MAX_BYTES = 16 * 1024 * 1024;
    public const JSON_DEPTH = 64;

    /**
     * @param \ArrayObject<string, bool> $tripped set to ['large' => true] when the headers aborted the request, so the caller can
     *        name the cause (the HTTP layer only reports a failed request)
     * @return array{on_headers: \Closure(mixed): void, decode_content: bool}
     */
    public static function options(\ArrayObject $tripped): array
    {
        return [
            'on_headers' => static function (mixed $r) use ($tripped): void {
                if ($r instanceof ResponseInterface && (int) $r->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    $tripped['large'] = true;
                    throw new SourceUnavailable('response too large');
                }
            },
            'decode_content' => true,
        ];
    }

    /**
     * @return array<mixed>
     * @throws SourceUnavailable
     */
    public static function decode(ResponseInterface $response, string $name): array
    {
        $body = $response->getBody();
        if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES || ($body->getSize() ?? 0) > self::MAX_BYTES) {
            throw new SourceUnavailable($name . ' response too large');
        }
        $data = json_decode((string) $body, true, self::JSON_DEPTH);

        return is_array($data) ? $data : throw new SourceUnavailable($name . ' returned a non-JSON body');
    }
}
