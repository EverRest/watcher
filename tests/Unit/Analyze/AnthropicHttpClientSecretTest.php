<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnthropicHttpClient;

final class AnthropicHttpClientSecretTest extends TestCase
{
    private const KEY = 'sk-ant-SECRET-never-printed';

    public function testNoDumpFormatPrintsTheKey(): void
    {
        $c = new AnthropicHttpClient(self::KEY, new Client());
        ob_start();
        var_dump($c);
        print_r($c);
        var_export($c);
        $out = (string) ob_get_clean();

        self::assertStringNotContainsString(self::KEY, $out);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($c));
    }

    public function testItCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new AnthropicHttpClient(self::KEY, new Client()));
    }

    public function testATraceOfAFailingCallNamesNoKey(): void
    {
        $trace = '';

        try {
            self::boom(new AnthropicHttpClient(self::KEY, new Client()));
        } catch (\Throwable $e) {
            $trace = $e->getTraceAsString() . print_r($e->getTrace(), true);
        }
        self::assertNotSame('', $trace);
        self::assertStringNotContainsString(self::KEY, $trace);
    }

    private static function boom(AnthropicHttpClient $c): never
    {
        throw new \RuntimeException('x');
    }

    public function testTheKeyParameterIsSensitive(): void
    {
        $p = (new \ReflectionClass(AnthropicHttpClient::class))->getConstructor()?->getParameters()[0];
        self::assertNotEmpty($p?->getAttributes(\SensitiveParameter::class));
    }
}
