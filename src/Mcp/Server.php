<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Throwable;

/** The stdio loop: one JSON-RPC message per line, one response line per request, nothing else on the output (FR-001..FR-005). */
final class Server
{
    public const MAX_LINE = 65536;

    /** Revisions of the MCP specification whose stdio framing and methods used here are identical (research R1), newest first. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private bool $initialized = false;

    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly string $version,
        private $in,
        private $out,
        private $err,
        private readonly ?DocsEndpoint $docs = null,
    ) {
    }

    /** Runs until stdin closes. Never throws for client input. */
    public function serve(): int
    {
        while (($line = $this->readLine()) !== null) {
            $response = $line === false ? JsonRpc::error(null, JsonRpc::INVALID_REQUEST) : $this->handle($line);
            if ($response !== null) {
                fwrite($this->out, $response . "\n");
                fflush($this->out);
            }
        }

        return 0;
    }

    /** @return string|false|null the line, false for an oversize line (discarded to its newline), null at EOF */
    private function readLine(): string|false|null
    {
        while (true) {
            $chunk = fgets($this->in, self::MAX_LINE + 2);
            if ($chunk === false) {
                return null;
            }
            if (str_ends_with($chunk, "\n")) {
                $chunk = rtrim($chunk, "\r\n");
                if ($chunk === '') {
                    continue;
                }

                return strlen($chunk) > self::MAX_LINE ? false : $chunk;
            }
            if (feof($this->in)) {
                return strlen($chunk) > self::MAX_LINE ? false : ($chunk === '' ? null : $chunk);
            }
            // no newline within the cap: drop the rest of the line
            while (($more = fgets($this->in, 8192)) !== false && !str_ends_with($more, "\n")) {
            }

            return false;
        }
    }

    private function handle(string $line): ?string
    {
        try {
            $msg = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return JsonRpc::error(null, JsonRpc::PARSE);
        }
        if (!is_array($msg) || $msg === [] || array_is_list($msg)) {
            return JsonRpc::error(null, JsonRpc::INVALID_REQUEST); // scalar, empty, or a batch
        }
        $hasId = array_key_exists('id', $msg);
        $id = $msg['id'] ?? null;
        if (($hasId && !JsonRpc::validId($id)) || ($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'] ?? null)) {
            return JsonRpc::error($hasId && JsonRpc::validId($id) ? $id : null, JsonRpc::INVALID_REQUEST);
        }
        $method = $msg['method'];
        $params = $msg['params'] ?? [];
        if (!$hasId) {
            if ($method === 'notifications/initialized') {
                $this->initialized = true;
            }

            return null; // notifications never get output, unknown ones included
        }
        /** @var string|int $id */
        if (!is_array($params)) {
            return JsonRpc::error($id, JsonRpc::PARAMS);
        }
        if (!$this->initialized && !in_array($method, ['initialize', 'ping'], true)) {
            return JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'not_initialized');
        }

        try {
            return JsonRpc::result($id, $this->route($method, $params));
        } catch (InvalidParams $e) {
            return JsonRpc::error($id, JsonRpc::PARAMS, $e->getMessage());
        } catch (MethodNotFound) {
            return JsonRpc::error($id, JsonRpc::METHOD);
        } catch (Throwable $e) {
            fwrite($this->err, 'watcher [error] ' . $e::class . "\n");

            return JsonRpc::error($id, JsonRpc::INTERNAL);
        }
    }

    /**
     * @param array<mixed> $params
     * @return array<string, mixed>
     */
    private function route(string $method, array $params): array
    {
        $docs = $this->docs;
        switch ($method) {
            case 'initialize':
                $this->initialized = true; // the client's notification confirms it; a request after the reply is allowed either way
                $asked = $params['protocolVersion'] ?? null;
                $caps = ['tools' => ['listChanged' => false]];
                if ($docs !== null) {
                    $caps['resources'] = ['subscribe' => false, 'listChanged' => false];
                    $caps['prompts'] = ['listChanged' => false];
                }

                return [
                    'protocolVersion' => is_string($asked) && in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
                    'capabilities' => $caps,
                    'serverInfo' => ['name' => 'watcher', 'version' => $this->version],
                ];
            case 'ping':
                return [];
            case 'tools/list':
                return ['tools' => $this->tools->list()];
            case 'tools/call':
                $name = $params['name'] ?? null;
                $args = $params['arguments'] ?? [];
                if (!is_string($name) || !is_array($args)) {
                    throw new InvalidParams('invalid params: name');
                }

                return $this->tools->call($name, $args);
        }
        if ($docs !== null) {
            switch ($method) {
                case 'resources/list':
                    return ['resources' => $docs->resources()];
                case 'resources/templates/list':
                    return ['resourceTemplates' => []];
                case 'resources/read':
                    $uri = $params['uri'] ?? null;
                    if (!is_string($uri)) {
                        throw new InvalidParams('resource_not_found');
                    }

                    return ['contents' => [$docs->read($uri)]];
                case 'prompts/list':
                    return ['prompts' => $docs->prompts()];
                case 'prompts/get':
                    $name = $params['name'] ?? null;
                    $args = $params['arguments'] ?? [];
                    if (!is_string($name) || !is_array($args)) {
                        throw new InvalidParams('invalid params: name');
                    }

                    return $docs->prompt($name, $args);
            }
        }
        throw new MethodNotFound();
    }
}
