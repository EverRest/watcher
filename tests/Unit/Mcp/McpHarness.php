<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use Watcher\Mcp\DocsEndpoint;
use Watcher\Mcp\Envelope;
use Watcher\Mcp\SecretGuard;
use Watcher\Mcp\Server;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolRegistry;
use Watcher\Tests\Fakes\FrozenClock;

/** Drives a Server through php://memory streams. */
final class McpHarness
{
    public string $err = '';
    public string $raw = '';

    /**
     * @param list<Tool> $tools
     * @param list<string> $groups
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public static function server(array $tools, array $groups, bool $allowDryRun, ?string $service, $in, $out, $err, ?DocsEndpoint $docs = null): Server
    {
        $registry = new ToolRegistry($tools, $groups, $allowDryRun, new Envelope($service, new FrozenClock(new \DateTimeImmutable('2026-10-25T09:12:55Z'))), new SecretGuard(), $err);

        return new Server($registry, '0.4.0', $in, $out, $err, $docs);
    }

    /**
     * @param list<Tool> $tools
     * @param list<string|array<mixed>> $messages a string is sent verbatim, an array is JSON-encoded
     * @param list<string> $groups
     * @return list<array<string, mixed>> decoded responses, one per output line
     */
    public function run(array $messages, array $tools = [], array $groups = ['inspect', 'onboarding', 'docs'], bool $allowDryRun = false, ?DocsEndpoint $docs = null, bool $init = true): array
    {
        $in = self::mem();
        $out = self::mem();
        $err = self::mem();
        if ($init) {
            array_unshift($messages, ['jsonrpc' => '2.0', 'id' => 'init', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        }
        foreach ($messages as $m) {
            fwrite($in, (is_string($m) ? $m : json_encode($m, JSON_THROW_ON_ERROR)) . "\n");
        }
        rewind($in);
        $code = self::server($tools, $groups, $allowDryRun, 'orders-api', $in, $out, $err, $docs)->serve();
        \PHPUnit\Framework\Assert::assertSame(0, $code);
        rewind($out);
        rewind($err);
        $this->raw = (string) stream_get_contents($out);
        $this->err = (string) stream_get_contents($err);
        $lines = $this->raw === '' ? [] : explode("\n", rtrim($this->raw, "\n"));
        $decoded = array_map(static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lines);

        return $init ? array_slice($decoded, 1) : $decoded; // drop the initialize reply
    }

    /** @return resource */
    public static function mem()
    {
        return fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
    }

    /**
     * The decoded envelope of a tool-call response.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function envelope(array $response): array
    {
        $text = $response['result']['content'][0]['text'] ?? null;
        \PHPUnit\Framework\Assert::assertIsString($text);

        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function call(string $name, array $args = [], int $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]];
    }
}
