<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/** JSON-RPC 2.0 framing: pure functions (FR-001, FR-005). Messages are fixed strings; an offending value is never echoed. */
final class JsonRpc
{
    public const PARSE = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD = -32601;
    public const PARAMS = -32602;
    public const INTERNAL = -32603;

    private const MESSAGES = [
        self::PARSE => 'parse_error',
        self::INVALID_REQUEST => 'invalid_request',
        self::METHOD => 'method_not_found',
        self::PARAMS => 'invalid_params',
        self::INTERNAL => 'internal_error',
    ];

    /** An id is a string or an integer; anything else is never echoed. */
    public static function validId(mixed $id): bool
    {
        return is_string($id) || is_int($id);
    }

    public static function result(string|int $id, mixed $result): string
    {
        return self::line(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    public static function error(string|int|null $id, int $code, ?string $message = null): string
    {
        return self::line(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message ?? self::MESSAGES[$code]]]);
    }

    /** One line, no embedded newline (json_encode escapes them). */
    public static function line(mixed $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
