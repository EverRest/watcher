<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\ConfigFileReader;
use Watcher\Mcp\PathPolicy;
use Watcher\Mcp\ToolError;

final class PathPolicyTest extends TestCase
{
    private string $base;
    private string $root;

    protected function setUp(): void
    {
        $this->base = (string) realpath(sys_get_temp_dir()) . '/mcp-path-' . bin2hex(random_bytes(4));
        $this->root = $this->base . '/app';
        mkdir($this->root, 0755, true);
        mkdir($this->base . '/app-evil', 0755, true);
        file_put_contents($this->root . '/watcher.php', "<?php return [];\n");
        chmod($this->root . '/watcher.php', 0644);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwX ' . escapeshellarg($this->base) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->base));
    }

    private function policy(): PathPolicy
    {
        return new PathPolicy([$this->root]);
    }

    private function refuses(string $path): void
    {
        try {
            $this->policy()->approve($path);
            self::fail('accepted: ' . substr($path, 0, 60));
        } catch (ToolError $e) {
            self::assertSame('path_not_allowed', $e->errorCode);
            self::assertSame('path_not_allowed', $e->getMessage());
        }
    }

    public function testAnOrdinaryFileInTheRootIsApproved(): void
    {
        $a = $this->policy()->approve($this->root . '/watcher.php');
        self::assertSame($this->root . '/watcher.php', $a->real);
    }

    /** @return array<string, array{string}> */
    public static function literal(): array
    {
        return [
            'dotdot' => ['../app/../../etc/passwd'],
            'percent dotdot' => ['%2e%2e/%2e%2e/etc/passwd.php'],
            'absolute outside' => ['/etc/passwd'],
            'outside php' => [__FILE__],
            'backslash' => ['C:\\app\\watcher.php'],
            'nul' => ["/tmp/x.php\0.txt"],
            'long' => [str_repeat('a', 2000) . '.php'],
            'empty' => [''],
            'device' => ['/dev/null'],
            'directory' => ['/tmp'],
            'missing' => ['/definitely/not/here.php'],
        ];
    }

    /** @dataProvider literal */
    public function testHostileLiteralPaths(string $path): void
    {
        $this->refuses($path);
    }

    public function testRelativeDotDotThatEscapesTheRoot(): void
    {
        $this->refuses($this->root . '/../app-evil/../../etc/passwd');
    }

    public function testDirectoryExtensionAndCaseVariants(): void
    {
        mkdir($this->root . '/dir.php');
        $this->refuses($this->root . '/dir.php');
        foreach (['x.phtml', 'x.PHP', 'x.php.txt', 'x'] as $name) {
            file_put_contents($this->root . '/' . $name, '<?php return [];');
            chmod($this->root . '/' . $name, 0644);
            $this->refuses($this->root . '/' . $name);
        }
    }

    public function testRootsAreComparedWithASeparator(): void
    {
        file_put_contents($this->base . '/app-evil/watcher.php', '<?php return [];');
        chmod($this->base . '/app-evil/watcher.php', 0644);
        $this->refuses($this->base . '/app-evil/watcher.php');
    }

    public function testSymlinksAreRefusedWhateverTheyPointAt(): void
    {
        symlink('/etc/passwd', $this->root . '/to-passwd.php');
        symlink($this->root . '/watcher.php', $this->root . '/inside.php');
        symlink($this->root . '/inside.php', $this->root . '/chain.php');
        symlink($this->base . '/app-evil', $this->root . '/linkdir');
        file_put_contents($this->base . '/app-evil/x.php', '<?php return [];');
        chmod($this->base . '/app-evil/x.php', 0644);
        foreach (['to-passwd.php', 'inside.php', 'chain.php', 'linkdir/x.php'] as $name) {
            $this->refuses($this->root . '/' . $name);
        }
    }

    public function testGroupOrWorldWritableFilesAndParentsAreRefused(): void
    {
        chmod($this->root . '/watcher.php', 0664);
        $this->refuses($this->root . '/watcher.php');
        chmod($this->root . '/watcher.php', 0646);
        $this->refuses($this->root . '/watcher.php');
        chmod($this->root . '/watcher.php', 0644);
        self::assertSame($this->root . '/watcher.php', $this->policy()->approve($this->root . '/watcher.php')->real);
        chmod($this->root, 0777);
        $this->refuses($this->root . '/watcher.php');
        chmod($this->root, 01777); // sticky excuses a shared directory
        self::assertSame($this->root . '/watcher.php', $this->policy()->approve($this->root . '/watcher.php')->real);
    }

    public function testHardLinksAndFifosAreRefused(): void
    {
        link($this->root . '/watcher.php', $this->root . '/hard.php');
        $this->refuses($this->root . '/hard.php');
        $this->refuses($this->root . '/watcher.php'); // the original has two links now
        unlink($this->root . '/hard.php');
        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($this->root . '/fifo.php', 0644);
            $this->refuses($this->root . '/fifo.php');
        }
    }

    public function testReaderStopsAtTheCapWithoutParsing(): void
    {
        file_put_contents($this->root . '/big.php', '<?php /*' . str_repeat('a', ConfigFileReader::MAX_BYTES) . '*/ return [];');
        chmod($this->root . '/big.php', 0644);
        $approved = $this->policy()->approve($this->root . '/big.php');
        try {
            (new ConfigFileReader())->read($approved);
            self::fail('read');
        } catch (ToolError $e) {
            self::assertSame('file_too_large', $e->errorCode);
        }
        $ok = (new ConfigFileReader())->read($this->policy()->approve($this->root . '/watcher.php'));
        self::assertSame("<?php return [];\n", $ok->bytes);
        self::assertTrue($ok->stillTheSame());
        $ok->close();
    }

    public function testASwapAfterTheCheckIsRefusedByTheReader(): void
    {
        $approved = $this->policy()->approve($this->root . '/watcher.php');
        // symlink flip: the approved name now points elsewhere
        unlink($this->root . '/watcher.php');
        symlink('/etc/hosts', $this->root . '/watcher.php');
        try {
            (new ConfigFileReader())->read($approved);
            self::fail('read through a swapped symlink');
        } catch (ToolError $e) {
            self::assertSame('path_not_allowed', $e->errorCode);
        }
        // inode swap: a different regular file under the same name
        unlink($this->root . '/watcher.php');
        file_put_contents($this->root . '/watcher.php', '<?php return ["swapped" => true];');
        chmod($this->root . '/watcher.php', 0644);
        try {
            (new ConfigFileReader())->read($approved);
            self::fail('read a different inode');
        } catch (ToolError $e) {
            self::assertSame('path_not_allowed', $e->errorCode);
        }
        // FIFO swap: must not hang
        unlink($this->root . '/watcher.php');
        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($this->root . '/watcher.php', 0644);
            $this->expectException(ToolError::class);
            (new ConfigFileReader())->read($approved);
        }
    }

    public function testAnUnreadableFileIsFileUnreadable(): void
    {
        $approved = $this->policy()->approve($this->root . '/watcher.php');
        chmod($this->root . '/watcher.php', 0000);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads everything');
        }
        try {
            (new ConfigFileReader())->read($approved);
            self::fail('read');
        } catch (ToolError $e) {
            self::assertContains($e->errorCode, ['file_unreadable', 'path_not_allowed']);
        }
    }
}
