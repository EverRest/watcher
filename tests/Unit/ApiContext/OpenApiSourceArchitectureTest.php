<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use PHPUnit\Framework\TestCase;

/** Constitution II: the OpenAPI source only ever reads (GET) and only over https. */
final class OpenApiSourceArchitectureTest extends TestCase
{
    private function code(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../src/ApiContext/OpenApiSource.php');
    }

    public function testOnlyGetRequests(): void
    {
        preg_match_all("/->request\(\s*'([A-Z]+)'/", $this->code(), $m);
        self::assertSame(['GET'], array_values(array_unique($m[1])));
        self::assertSame(0, preg_match('/\b(POST|PUT|PATCH|DELETE|HEAD)\b/', $this->code()));
    }

    public function testRedirectsOffAndNoPlainHttpUrl(): void
    {
        self::assertSame(1, preg_match("/'allow_redirects'\s*=>\s*false/", $this->code()));
        self::assertSame(0, preg_match('~http://~', $this->code()));
    }

    public function testNoRequestBodyOptions(): void
    {
        self::assertSame(0, preg_match("/'(body|json|form_params|multipart)'/", $this->code()));
    }
}
