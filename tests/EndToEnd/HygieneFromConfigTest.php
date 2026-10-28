<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\Hygiene;
use Watcher\Hygiene\HygieneOptions;

/**
 * `watcher hygiene` wired from config (Hygiene::fromConfig), one test per source driver. HTTP is a Guzzle MockHandler,
 * so the run reads only the recorded responses.
 */
final class HygieneFromConfigTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/hygiene-from-config-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /** @param list<Response> $queue */
    private function http(array $queue): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($queue))]);
    }

    private static function file(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /** @param array<string, mixed> $patch */
    private function harness(array $patch): HygieneHarness
    {
        return new HygieneHarness(array_replace_recursive([
            'state' => ['driver' => 'file', 'file' => ['path' => $this->stateFile]],
        ], $patch));
    }

    private function runHygiene(HygieneHarness $h, Client $http, bool $notify = false): \Watcher\Hygiene\HygieneResult
    {
        return Hygiene::fromConfig($h->config(), $h->clock, $http)->run(new HygieneOptions(notify: $notify));
    }

    public function testLokiDriverRunsFromConfig(): void
    {
        $h = $this->harness(['source' => ['driver' => 'loki']]);
        $empty = new Response(200, ['Content-Type' => 'application/json'], self::file(__DIR__ . '/../Fixtures/loki/empty.json'));

        $r = $this->runHygiene($h, $this->http(array_fill(0, 8, $empty)));

        self::assertStringNotContainsString('internal error', (string) $r->failure);
    }

    public function testDatadogDriverRunsFromConfig(): void
    {
        $h = $this->harness(['source' => ['driver' => 'datadog', 'datadog' => ['api_key' => 'DD-TEST-KEY', 'app_key' => 'DD-TEST-APP']]]);
        $empty = new Response(200, ['Content-Type' => 'application/json'], self::file(__DIR__ . '/../Fixtures/datadog/aggregate_empty.json'));

        $r = $this->runHygiene($h, $this->http(array_fill(0, 8, $empty)));

        self::assertStringNotContainsString('internal error', (string) $r->failure);
    }
}
