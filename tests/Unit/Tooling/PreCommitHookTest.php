<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

/** Runs scripts/git-hooks/pre-commit inside a throw-away repository; the real repository is never touched. */
final class PreCommitHookTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v git bash')) === '') {
            self::markTestSkipped('git and bash are required');
        }
        $this->repo = sys_get_temp_dir() . '/watcher-hook-' . bin2hex(random_bytes(6));
        mkdir($this->repo . '/scripts', 0777, true);
        file_put_contents($this->repo . '/scripts/badges.php', "<?php\n");
        $this->git('init -q');
        $this->git('config user.name Tester');
        $this->git('config user.email EverRest@users.noreply.github.com');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repo));
    }

    public function testCleanCommitPasses(): void
    {
        $this->stage('a.txt', "hello\n");
        self::assertSame(0, $this->hook()[0]);
    }

    public function testEmptyEmailFails(): void
    {
        $this->git('config user.email ""');
        $this->stage('a.txt', "hello\n");
        [$code, $err] = $this->hook(['GIT_AUTHOR_EMAIL' => '']);
        self::assertSame(1, $code);
        self::assertStringContainsString('e-mail', $err);
    }

    public function testBlockedDomainFailsOnConfigAndOnAuthorOverride(): void
    {
        file_put_contents($this->repo . '/.git/hooks/blocked-author-domains', "# private\n\nPrivate.Example\n");
        $this->stage('a.txt', "hello\n");
        $this->git('config user.email me@private.example');
        self::assertSame(1, $this->hook(['GIT_AUTHOR_EMAIL' => 'EverRest@users.noreply.github.com'])[0]);
        $this->git('config user.email EverRest@users.noreply.github.com');
        self::assertSame(1, $this->hook(['GIT_AUTHOR_EMAIL' => 'me@private.example'])[0]);
        self::assertSame(0, $this->hook()[0]);
    }

    public function testSecretInFileNameWithSpacesIsStillScanned(): void
    {
        $this->stage('my file.txt', 'k = sk-ant-' . str_repeat('a', 30) . "\n");
        [$code, $err] = $this->hook();
        self::assertSame(1, $code);
        self::assertStringContainsString('secret-looking', $err);
    }

    public function testForbiddenFileWithSpacesIsReportedWhole(): void
    {
        $this->stage('some dir/id_rsa', "x\n");
        [$code, $err] = $this->hook();
        self::assertSame(1, $code);
        self::assertStringContainsString('some dir/id_rsa', $err);
    }

    public function testARedisDumpIsForbiddenBecauseItHoldsState(): void
    {
        $this->stage('dump.rdb', "REDIS0011\n");
        [$code, $err] = $this->hook();
        self::assertSame(1, $code);
        self::assertStringContainsString('dump.rdb', $err);
    }

    private function stage(string $name, string $content): void
    {
        $path = $this->repo . '/' . $name;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
        $this->git('add -- ' . escapeshellarg($name));
    }

    private function git(string $args): void
    {
        exec('git -C ' . escapeshellarg($this->repo) . ' ' . $args . ' 2>&1', $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string}
     */
    private function hook(array $env = []): array
    {
        $proc = proc_open(
            ['bash', dirname(__DIR__, 3) . '/scripts/git-hooks/pre-commit'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repo,
            $env + ['PATH' => (string) getenv('PATH'), 'HOME' => $this->repo, 'GIT_CONFIG_NOSYSTEM' => '1'],
        );
        self::assertIsResource($proc);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($proc), $err];
    }
}
