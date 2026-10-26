<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Observe\FileSink;
use Watcher\Observe\SinkFailed;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\SpyLogger;

final class FileSinkTest extends TestCase
{
    private string $dir;
    private FrozenClock $clock;
    private int $umask;

    protected function setUp(): void
    {
        $this->umask = umask(0);
        $this->dir = sys_get_temp_dir() . '/filesink-' . bin2hex(random_bytes(6));
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T12:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        umask($this->umask);
        if (is_dir($this->dir)) {
            chmod($this->dir, 0700);
            foreach (scandir($this->dir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    unlink($this->dir . '/' . $f);
                }
            }
            rmdir($this->dir);
        }
    }

    private function sink(string $rotate = 'size', int $maxBytes = 1000000, int $maxFiles = 3, int $days = 30, ?string $path = null, ?SpyLogger $log = null): FileSink
    {
        return new FileSink($path ?? $this->dir . '/obs.log', $rotate, $maxBytes, $maxFiles, $days, $this->clock, $log);
    }

    /** @return list<string> */
    private function names(): array
    {
        $n = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..']));
        sort($n);

        return $n;
    }

    private function failure(callable $fn): string
    {
        try {
            $fn();
        } catch (SinkFailed $e) {
            return $e->getMessage();
        }
        self::fail('SinkFailed expected');
    }

    public function testWritesNdjsonWithModes(): void
    {
        $this->sink()->emit([['a' => 'x/é', 'n' => 1], ['b' => 2]]);

        self::assertSame("{\"a\":\"x/é\",\"n\":1}\n{\"b\":2}\n", file_get_contents($this->dir . '/obs.log'));
        self::assertSame(0600, fileperms($this->dir . '/obs.log') & 0777);
        self::assertSame(0700, fileperms($this->dir) & 0777);
        self::assertSame(['obs.log'], $this->names());
    }

    public function testAppendsAndLeavesExistingDirectoryModeAlone(): void
    {
        mkdir($this->dir, 0750);
        $this->sink()->emit([['a' => 1]]);
        $this->sink()->emit([['a' => 2]]);

        self::assertSame("{\"a\":1}\n{\"a\":2}\n", file_get_contents($this->dir . '/obs.log'));
        self::assertSame(0750, fileperms($this->dir) & 0777);
    }

    public function testSizeRotationKeepsMaxFiles(): void
    {
        $sink = $this->sink('size', 30, 2);
        foreach (['1', '2', '3', '4'] as $i) {
            $sink->emit([['k' => str_repeat($i, 15)]]); // 24 bytes per line: one per file
        }

        self::assertSame(['obs.log', 'obs.log.1', 'obs.log.2'], $this->names());
        self::assertStringContainsString('4444', (string) file_get_contents($this->dir . '/obs.log'));
        self::assertStringContainsString('3333', (string) file_get_contents($this->dir . '/obs.log.1'));
        self::assertStringContainsString('2222', (string) file_get_contents($this->dir . '/obs.log.2'));
    }

    public function testDailyRotationByFileDate(): void
    {
        $this->sink('daily')->emit([['d' => 1]]);
        touch($this->dir . '/obs.log', (int) (new DateTimeImmutable('2026-10-23T23:59:00+00:00'))->format('U'));

        $this->sink('daily')->emit([['d' => 2]]);

        self::assertSame(['obs.log', 'obs.log-2026-10-23'], $this->names());
        self::assertSame("{\"d\":1}\n", file_get_contents($this->dir . '/obs.log-2026-10-23'));
        self::assertSame("{\"d\":2}\n", file_get_contents($this->dir . '/obs.log'));
    }

    public function testDailyKeepsAtMostMaxFiles(): void
    {
        mkdir($this->dir, 0700);
        foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $d) {
            file_put_contents($this->dir . '/obs.log-' . $d, 'x');
        }
        file_put_contents($this->dir . '/obs.log', "y\n");
        chmod($this->dir . '/obs.log', 0600);
        touch($this->dir . '/obs.log', (int) (new DateTimeImmutable('2026-10-23T10:00:00+00:00'))->format('U'));

        $this->sink('daily', 1, 2)->emit([['d' => 1]]);

        self::assertSame(['obs.log', 'obs.log-2026-10-22', 'obs.log-2026-10-23'], $this->names());
    }

    public function testRetentionDeletesOldFilesByPrefixOnly(): void
    {
        mkdir($this->dir, 0700);
        $old = (int) (new DateTimeImmutable('2026-09-01T00:00:00+00:00'))->format('U');
        foreach (['obs.log.1', 'obs.log-2026-09-01', 'other.txt'] as $f) {
            file_put_contents($this->dir . '/' . $f, 'x');
            touch($this->dir . '/' . $f, $old);
        }

        $this->sink()->emit([['a' => 1]]);

        self::assertSame(['obs.log', 'other.txt'], $this->names());
    }

    public function testRotatedFilesAreMode0600(): void
    {
        $sink = $this->sink('size', 30, 2);
        $sink->emit([['k' => str_repeat('1', 15)]]);
        $sink->emit([['k' => str_repeat('2', 15)]]);
        self::assertSame(['obs.log', 'obs.log.1'], $this->names());
        self::assertSame(0600, fileperms($this->dir . '/obs.log') & 0777);

        $this->sink('daily')->emit([['d' => 1]]);
        touch($this->dir . '/obs.log', (int) (new DateTimeImmutable('2026-10-23T23:59:00+00:00'))->format('U'));
        $this->sink('daily')->emit([['d' => 2]]);
        self::assertSame('600', decoct(fileperms($this->dir . '/obs.log') & 0777));
        self::assertSame('600', decoct(fileperms($this->dir . '/obs.log-2026-10-23') & 0777));
    }

    public function testPruneAndCountIgnoreLookalikeFiles(): void
    {
        mkdir($this->dir, 0700);
        $old = (int) (new DateTimeImmutable('2026-09-01T00:00:00+00:00'))->format('U');
        $decoys = ['obs.log.bak', 'obs.logger', 'obs.log-other', 'obs.log.1x', 'obs.log-2026-09-01x'];
        foreach (array_merge($decoys, ['obs.log.1']) as $f) {
            file_put_contents($this->dir . '/' . $f, 'x');
            touch($this->dir . '/' . $f, $old);
        }

        $this->sink()->emit([['a' => 1]]);

        $expected = array_merge($decoys, ['obs.log']);
        sort($expected);
        self::assertSame($expected, $this->names());
    }

    public function testSymlinkRefused(): void
    {
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/real', '');
        symlink($this->dir . '/real', $this->dir . '/obs.log');

        self::assertSame('file is a symlink', $this->failure(fn () => $this->sink()->emit([['a' => 1]])));
        self::assertSame('', file_get_contents($this->dir . '/real'));
    }

    public function testInvalidPathsRefused(): void
    {
        foreach (['obs.log', $this->dir . '/../obs.log', ''] as $p) {
            self::assertSame('file path invalid', $this->failure(fn () => $this->sink(path: $p)->emit([['a' => 1]])));
        }
    }

    public function testUnwritableDirectoryFailsWithoutLeakingPath(): void
    {
        mkdir($this->dir, 0500);
        $msg = $this->failure(fn () => $this->sink()->emit([['a' => 1]]));
        self::assertSame('file not writable', $msg);
        self::assertStringNotContainsString($this->dir, $msg);

        $msg = $this->failure(fn () => $this->sink(path: $this->dir . '/sub/deeper/obs.log')->emit([['a' => 1]]));
        self::assertSame('file not writable', $msg);
    }

    /** An active file from yesterday, so the next daily flush wants to rotate it onto obs.log-2026-10-23. */
    private function staleActive(): void
    {
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/obs.log', "old\n");
        chmod($this->dir . '/obs.log', 0600);
        touch($this->dir . '/obs.log', (int) (new DateTimeImmutable('2026-10-23T10:00:00+00:00'))->format('U'));
    }

    /** @return array<string, array{callable(string): void}> */
    public static function hostileDatedNames(): array
    {
        return [
            'symlink to a victim' => [static function (string $dir): void {
                symlink($dir . '/victim', $dir . '/obs.log-2026-10-23');
            }],
            'dangling symlink' => [static function (string $dir): void {
                symlink($dir . '/not-there', $dir . '/obs.log-2026-10-23');
            }],
            'hard link to a victim' => [static function (string $dir): void {
                link($dir . '/victim', $dir . '/obs.log-2026-10-23');
            }],
            'fifo' => [static function (string $dir): void {
                posix_mkfifo($dir . '/obs.log-2026-10-23', 0600);
            }],
        ];
    }

    #[DataProvider('hostileDatedNames')]
    public function testDailyRotationNeverWritesThroughAHostileDatedName(callable $plant): void
    {
        $this->staleActive();
        file_put_contents($this->dir . '/victim', 'VICTIM');
        chmod($this->dir . '/victim', 0640);
        $plant($this->dir);
        $log = new SpyLogger();

        $this->sink('daily', log: $log)->emit([['d' => 2]]); // fails open: no exception

        self::assertSame('VICTIM', file_get_contents($this->dir . '/victim'));
        self::assertSame(0640, fileperms($this->dir . '/victim') & 0777);
        self::assertFalse(file_exists($this->dir . '/not-there'));
        self::assertSame("old\n{\"d\":2}\n", file_get_contents($this->dir . '/obs.log'), 'no rotation: the active file keeps taking events');
        self::assertCount(1, $log->records);
        self::assertStringNotContainsString($this->dir, $log->records[0][1]);
    }

    public function testDailyRotationAppendsToAnOwnedRegularDatedFileInChunks(): void
    {
        $this->staleActive();
        $big = str_repeat("x\n", 300000); // above one copy chunk
        file_put_contents($this->dir . '/obs.log', $big);
        chmod($this->dir . '/obs.log', 0600);
        touch($this->dir . '/obs.log', (int) (new DateTimeImmutable('2026-10-23T10:00:00+00:00'))->format('U'));
        file_put_contents($this->dir . '/obs.log-2026-10-23', "first\n");
        chmod($this->dir . '/obs.log-2026-10-23', 0600);

        $this->sink('daily')->emit([['d' => 2]]);

        self::assertSame('first' . "\n" . $big, file_get_contents($this->dir . '/obs.log-2026-10-23'));
        self::assertSame("{\"d\":2}\n", file_get_contents($this->dir . '/obs.log'));
    }

    public function testAGroupWritableDirectoryIsRefused(): void
    {
        mkdir($this->dir, 0770);
        chmod($this->dir, 0770);

        self::assertSame('file directory not private', $this->failure(fn () => $this->sink()->emit([['a' => 1]])));
        self::assertSame([], $this->names());
    }

    public function testAStickyWorldWritableDirectoryIsAccepted(): void
    {
        mkdir($this->dir, 0700);
        chmod($this->dir, 01777);

        $this->sink()->emit([['a' => 1]]);

        self::assertSame(['obs.log'], $this->names());
    }

    public function testAHardLinkedActiveFileIsRefused(): void
    {
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/victim', 'VICTIM');
        chmod($this->dir . '/victim', 0600);
        link($this->dir . '/victim', $this->dir . '/obs.log');

        self::assertSame('file is a hard link', $this->failure(fn () => $this->sink()->emit([['a' => 1]])));
        self::assertSame('VICTIM', file_get_contents($this->dir . '/victim'));
    }

    public function testAnActiveFileWithOpenPermissionsIsRefusedNotChmodded(): void
    {
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/obs.log', '');
        chmod($this->dir . '/obs.log', 0644);

        self::assertSame('file mode not private', $this->failure(fn () => $this->sink()->emit([['a' => 1]])));
        self::assertSame(0644, fileperms($this->dir . '/obs.log') & 0777);
    }
}
