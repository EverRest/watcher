<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;

/** T013 / SC-001: src/Mcp can write nothing, post nothing, open no socket and run no process. */
final class NoWriteStaticTest extends TestCase
{
    private const FORBIDDEN = [
        'a notifier' => '/\bWatcher\\\\Notify\\\\|\b[A-Za-z]*Notifier\b/',
        'a state write' => '/->(?:put|increment|acquire|release|purge)\(/',
        'file_put_contents' => '/\bfile_put_contents\s*\(/',
        'a write-mode fopen' => '/\bfopen\s*\([^;]*,\s*[\'"][^\'"]*[waxc+][^\'"]*[\'"]/',
        'a file removal or creation' => '/\b(?:unlink|rmdir|mkdir|rename|copy|touch|chmod|chown|symlink|link|tempnam|tmpfile)\s*\(/',
        'a network call' => '/\b(?:curl_\w+|stream_socket_server|stream_socket_client|fsockopen|socket_create)\s*\(|\bGuzzle|\bClient::|\bHttpClient/',
        'a process' => '/\b(?:exec|shell_exec|system|passthru|popen|proc_open|pcntl_exec)\s*\(|`[^`]*`/',
        'the environment file' => '/\.env\b/',
    ];

    /** Entry points that may build collaborators (not call them) and are named by the contract. */
    private const ALLOWED = [
        'a network call' => ['Tools/CheckTool.php', 'Tools/DryRunTool.php', 'McpCommand.php'],
        'a notifier' => ['McpCommand.php'],
    ];

    /** @return list<string> */
    public static function violations(string $code, string $file = ''): array
    {
        $code = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $code); // comments may mention what the class does not do
        $bare = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $code); // string literals: the secret-shape patterns and prose
        $out = [];
        foreach (self::FORBIDDEN as $what => $re) {
            if (preg_match($re, $what === 'a write-mode fopen' ? $code : $bare) === 1 && !in_array($file, self::ALLOWED[$what] ?? [], true)) {
                $out[] = $what;
            }
        }

        return $out;
    }

    public function testNoFileOfSrcMcpDoesAnyOfIt(): void
    {
        $root = realpath(__DIR__ . '/../../../src/Mcp');
        self::assertIsString($root);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $n = 0;
        foreach ($files as $f) {
            if ($f->getExtension() === 'php') {
                ++$n;
                $rel = substr($f->getPathname(), strlen($root) + 1);
                // 0.13.2: the `actions` group runs artisan commands by design (confirm, rate limit, audit). This is the only file allowed to spawn a process.
                if ($rel === 'ArtisanCommand.php') {
                    continue;
                }
                self::assertSame([], self::violations((string) file_get_contents($f->getPathname()), $rel), $rel);
            }
        }
        self::assertGreaterThan(30, $n);
    }

    public function testADeliberatelyBadFixtureIsCaught(): void
    {
        $bad = "<?php\n\$s->put('k', [], 1);\nfile_put_contents('/tmp/x', 'y');\nfopen('/tmp/x', 'w');\nunlink('/tmp/x');\ncurl_init();\n\$n = new SlackWebhookNotifier();\nexec('ls');\n\$x = file_get_contents('.env');\n";
        self::assertEqualsCanonicalizing(['a notifier', 'a state write', 'file_put_contents', 'a write-mode fopen', 'a file removal or creation', 'a network call', 'a process'], self::violations($bad));
        self::assertSame([], self::violations("<?php\n// ->put( is described here\n\$a = fopen(\$p, 'rn');\n"));
    }
}
