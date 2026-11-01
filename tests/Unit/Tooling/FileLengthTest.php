<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/check-file-length.php';

final class FileLengthTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/watcher-length-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src/Nested', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['src/a.php', 'src/Nested/b.php'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root . '/src/Nested');
        @rmdir($this->root . '/src');
        @rmdir($this->root);
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->root . '/' . $file, $content);
    }

    public function testFiveHundredLinesPassAndFiveHundredAndOneFails(): void
    {
        $this->write('src/a.php', str_repeat("x\n", 500));
        self::assertSame([], file_length_violations($this->root));

        $this->write('src/a.php', str_repeat("x\n", 501));
        $violations = file_length_violations($this->root);
        self::assertCount(1, $violations);
        self::assertSame(501, $violations[0]['lines']);
    }

    public function testFortyKibibytesPassAndOneMoreByteFails(): void
    {
        $this->write('src/a.php', str_repeat('a', 40960));
        self::assertSame([], file_length_violations($this->root));

        $this->write('src/a.php', str_repeat('a', 40961));
        $violations = file_length_violations($this->root);
        self::assertCount(1, $violations);
        self::assertSame(40961, $violations[0]['bytes']);
    }

    public function testScansNestedDirectoriesUnderSrcOnly(): void
    {
        $this->write('src/Nested/b.php', str_repeat("x\n", 600));
        $this->write('src/a.php', "<?php\n");
        $violations = file_length_violations($this->root);
        self::assertCount(1, $violations);
        self::assertStringEndsWith('src/Nested/b.php', $violations[0]['file']);
    }
}
