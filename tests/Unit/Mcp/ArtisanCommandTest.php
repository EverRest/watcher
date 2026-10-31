<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\ArtisanCommand;

final class ArtisanCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watcher-artisan-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        // A stand-in artisan: prints one line of its own output, then a JSON line, and exits with the code in argv[2].
        file_put_contents($this->dir . '/artisan', "<?php\necho \"digest text that must not come back\\n\";\necho json_encode(['argv' => array_slice(\$argv, 1)]), \"\\n\";\nexit((int) (\$argv[2] ?? 0));\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/artisan');
        @rmdir($this->dir);
    }

    public function testRunsTheCommandWithItsFlagsAndReturnsTheJsonLastLineOnly(): void
    {
        $r = (new ArtisanCommand(PHP_BINARY, $this->dir . '/artisan'))->run('watcher:fleet', ['--dry-run', '--json']);

        self::assertSame(0, $r['exit']);
        self::assertSame(['argv' => ['watcher:fleet', '--dry-run', '--json']], $r['json'] ?? null);
        self::assertStringNotContainsString('must not come back', json_encode($r) ?: '');
    }

    public function testReturnsTheExitCodeOfTheChild(): void
    {
        $r = (new ArtisanCommand(PHP_BINARY, $this->dir . '/artisan'))->run('watcher:health', ['3']);

        self::assertSame(3, $r['exit']);
    }
}
