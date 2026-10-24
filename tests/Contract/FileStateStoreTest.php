<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
use RuntimeException;
use Watcher\State\FileStateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\State\Purgeable;
use Watcher\State\StateStore;

final class FileStateStoreTest extends StateStoreContractTest
{
    private string $dir;

    protected function makeStore(ClockInterface $clock): StateStore
    {
        $this->dir = sys_get_temp_dir() . '/watcher-state-' . bin2hex(random_bytes(6));

        return new FileStateStore($this->dir, $clock, 900);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(@scandir($this->dir) ?: [], ['.', '..']) as $file) {
            unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir);
    }

    public function testFailedWriteKeepsTheOldRecordAndLeavesNoTempFile(): void
    {
        $this->store->put('k', ['v' => 1], 60);
        chmod($this->dir, 0500);
        try {
            if (is_writable($this->dir)) {
                self::markTestSkipped('directory permissions do not apply (running as root?)');
            }
            try {
                $this->store->put('k', ['v' => 2], 60);
                self::fail('expected RuntimeException');
            } catch (RuntimeException $e) {
                self::assertSame('state write failed', $e->getMessage());
            }
        } finally {
            chmod($this->dir, 0700);
        }

        self::assertSame(['v' => 1], $this->store->get('k'));
        self::assertSame([], glob($this->dir . '/*.tmp') ?: []);
    }

    public function testNeverWritesOutsideItsDirectory(): void
    {
        $parent = dirname($this->dir);
        $before = glob($parent . '/*evil*') ?: [];
        $this->store->put('../evil', ['x' => 1], 60);

        self::assertSame($before, glob($parent . '/*evil*') ?: []);
        self::assertNotEmpty(glob($this->dir . '/*'));
    }

    private function stateFile(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    private function purgeable(): FileStateStore
    {
        self::assertInstanceOf(Purgeable::class, $this->store);
        self::assertInstanceOf(FileStateStore::class, $this->store);

        return $this->store;
    }

    private function age(string $path, int $seconds): void
    {
        touch($path, $this->clock->now()->getTimestamp() - $seconds);
    }

    public function testPurgeRemovesExpiredRecordsAndKeepsLiveOnes(): void
    {
        $this->store->put('old', ['v' => 1], 10);
        $this->store->put('live', ['v' => 2], 1000);
        $this->advance(11);

        $removed = $this->purgeable()->purge();

        self::assertSame(1, $removed);
        self::assertFileDoesNotExist($this->stateFile('old'));
        self::assertSame(['v' => 2], $this->store->get('live'));
    }

    public function testPurgeRemovesOldSidecarsAndTempFilesButKeepsYoungOnes(): void
    {
        $this->store->put('k', ['v' => 1], 1000);
        $oldLock = $this->dir . '/' . str_repeat('a', 64) . '.json.lock';
        $youngLock = $this->dir . '/' . str_repeat('b', 64) . '.json.lock';
        $oldTmp = $this->dir . '/' . str_repeat('a', 64) . '.json.deadbeef.tmp';
        $youngTmp = $this->dir . '/' . str_repeat('b', 64) . '.json.cafebabe.tmp';
        foreach ([$oldLock, $youngLock, $oldTmp, $youngTmp] as $f) {
            touch($f);
        }
        $this->age($oldLock, 901);
        $this->age($oldTmp, 901);
        $this->age($youngLock, 899);
        $this->age($youngTmp, 899);

        $this->purgeable()->purge();

        self::assertFileDoesNotExist($oldLock);
        self::assertFileDoesNotExist($oldTmp);
        self::assertFileExists($youngLock);
        self::assertFileExists($youngTmp);
        self::assertSame(['v' => 1], $this->store->get('k'));
    }

    public function testPurgeLeavesForeignFilesAlone(): void
    {
        $foreign = ['notes.txt', 'abc.json', str_repeat('a', 64) . '.json.bak', 'other.lock', str_repeat('A', 64) . '.json'];
        mkdir($this->dir, 0700, true);
        foreach ($foreign as $f) {
            file_put_contents($this->dir . '/' . $f, '{"expires_at":1,"value":[]}');
            $this->age($this->dir . '/' . $f, 100000);
        }

        $this->purgeable()->purge();

        foreach ($foreign as $f) {
            self::assertFileExists($this->dir . '/' . $f);
        }
    }

    public function testPurgeRunsAtMostOncePerDay(): void
    {
        $this->store->put('a', ['v' => 1], 10);
        $this->advance(11);
        self::assertSame(1, $this->purgeable()->purge());
        self::assertFileExists($this->dir . '/.purge');

        $this->store->put('b', ['v' => 1], 10);
        $this->advance(11);
        self::assertSame(0, $this->purgeable()->purge());
        self::assertFileExists($this->stateFile('b'));

        $this->advance(86400);
        self::assertSame(1, $this->purgeable()->purge());
    }

    public function testPurgeIsBoundedByFileCount(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->store->put("k$i", ['v' => $i], 10);
        }
        $this->advance(11);

        self::assertSame(5, $this->purgeable()->purge(5));
        self::assertCount(2, glob($this->dir . '/*.json') ?: []);
    }

    public function testPurgeIsBoundedByTime(): void
    {
        $this->store->put('a', ['v' => 1], 10);
        $this->advance(11);

        self::assertSame(0, $this->purgeable()->purge(5000, 0.0));
        self::assertFileExists($this->stateFile('a'));
    }

    public function testPurgeSkipsASidecarThatIsHeld(): void
    {
        $this->store->put('k', ['v' => 1], 10);
        $lock = $this->stateFile('k') . '.lock';
        $this->age($lock, 5000);
        $this->advance(11);
        $held = fopen($lock, 'c');
        self::assertNotFalse($held);
        flock($held, LOCK_EX);
        $this->age($lock, 5000);

        try {
            $this->purgeable()->purge();
        } finally {
            fclose($held);
        }

        self::assertFileExists($lock);
        self::assertFileExists($this->stateFile('k'));
    }

    public function testPurgeOnAMissingDirectoryDoesNothing(): void
    {
        self::assertSame(0, (new FileStateStore($this->dir . '/missing', $this->clock))->purge());
    }

    public function testOtherStoresAreNotPurgeable(): void
    {
        self::assertNotContains(Purgeable::class, class_implements(new \Watcher\Tests\Fakes\InMemoryStateStore($this->clock)) ?: []);
    }

    public function testAWaiterOnARemovedSidecarReopensAndStillGetsMutualExclusion(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl not available');
        }
        $this->store->put('seed', ['v' => 0], 1000);
        $lock = $this->stateFile('k') . '.lock';
        $old = fopen($lock, 'c');
        self::assertNotFalse($old);
        flock($old, LOCK_EX);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $this->store->put('k', ['v' => 1], 1000); // blocks on the old inode
            exit(0);
        }
        usleep(300_000);
        // Purge removes the sidecar and a new holder creates a fresh one, while the child still waits on the old inode.
        unlink($lock);
        $new = fopen($lock, 'c');
        self::assertNotFalse($new);
        flock($new, LOCK_EX);
        flock($old, LOCK_UN);
        fclose($old);
        usleep(500_000);

        $writtenWhileNewHolderLocked = is_file($this->stateFile('k'));
        flock($new, LOCK_UN);
        fclose($new);
        pcntl_waitpid($pid, $status);

        self::assertFalse($writtenWhileNewHolderLocked, 'two holders on one key');
        self::assertSame(['v' => 1], $this->store->get('k'));
    }

    public function testFilesAreCreatedPrivate(): void
    {
        $this->store->put('k', ['v' => 1], 60);

        self::assertSame(0600, fileperms($this->stateFile('k')) & 0777);
        self::assertSame(0600, fileperms($this->stateFile('k') . '.lock') & 0777);
        self::assertSame(0700, fileperms($this->dir) & 0777);
    }

    public function testAWorldWritableDirectoryWithoutTheStickyBitIsRefusedByNameWithoutItsPath(): void
    {
        mkdir($this->dir, 0700, true);
        chmod($this->dir, 0777);
        try {
            $this->store->put('k', ['v' => 1], 60);
            self::fail('expected a refusal');
        } catch (\Watcher\Config\InvalidConfig $e) {
            self::assertStringContainsString('state.file.path', $e->getMessage());
            self::assertStringNotContainsString($this->dir, $e->getMessage());
        } finally {
            chmod($this->dir, 0700);
        }
        self::assertSame([], glob($this->dir . '/*.json') ?: []);
    }

    public function testAGroupWritableDirectoryOwnedByTheUserIsAllowedAndFilesStayPrivate(): void
    {
        mkdir($this->dir, 0700, true);
        chmod($this->dir, 0775); // the usual Laravel storage mode
        $this->store->put('k', ['v' => 1], 60);

        self::assertSame(['v' => 1], $this->store->get('k'));
        self::assertSame(0600, fileperms($this->stateFile('k')) & 0777);
        chmod($this->dir, 0700);
    }

    public function testAStickyWorldWritableDirectoryOwnedByTheUserIsAllowed(): void
    {
        mkdir($this->dir, 0700, true);
        chmod($this->dir, 01777);
        $this->store->put('k', ['v' => 1], 60);

        self::assertSame(['v' => 1], $this->store->get('k'));
        chmod($this->dir, 0700);
    }

    public function testADirectoryNotOwnedByTheRunningUserIsRefused(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0 || fileowner('/usr') === posix_geteuid()) {
            self::markTestSkipped('needs a non-root user and a directory owned by someone else');
        }
        $store = new FileStateStore('/usr', new FrozenClock(new \DateTimeImmutable('2026-10-08T06:00:00Z')), 900);
        $this->expectException(\Watcher\Config\InvalidConfig::class);
        $this->expectExceptionMessage('state.file.path');
        $store->put('k', ['v' => 1], 60);
    }

    public function testASymlinkedPurgeMarkerIsNotWrittenThrough(): void
    {
        mkdir($this->dir, 0700, true);
        $victim = $this->dir . '-victim.txt';
        file_put_contents($victim, 'precious');
        symlink($victim, $this->dir . '/.purge');
        try {
            try {
                $this->purgeable()->purge();
                self::fail('expected a refusal');
            } catch (RuntimeException) {
                // fail closed
            }
            self::assertSame('precious', file_get_contents($victim));
        } finally {
            unlink($victim);
        }
    }

    public function testASymlinkedLockSidecarIsNotFollowed(): void
    {
        mkdir($this->dir, 0700, true);
        $victim = $this->dir . '-created-by-victim.txt';
        symlink($victim, $this->stateFile('k') . '.lock');
        try {
            try {
                $this->store->put('k', ['v' => 1], 60);
                self::fail('expected a refusal');
            } catch (RuntimeException) {
                // fail closed
            }
            self::assertFileDoesNotExist($victim);
        } finally {
            @unlink($this->stateFile('k') . '.lock');
        }
    }

    public function testASymlinkedRecordIsNeverReadAsState(): void
    {
        mkdir($this->dir, 0700, true);
        $forged = $this->dir . '-forged.json';
        file_put_contents($forged, json_encode(['value' => ['outcome' => 'reported'], 'owner' => null, 'expires_at' => time() + 1000]));
        symlink($forged, $this->stateFile('run'));
        try {
            $this->expectException(RuntimeException::class);
            $this->store->get('run');
        } finally {
            unlink($forged);
        }
    }

    public function testPurgeSkipsSymlinkedRecordsAndLeavesTheirTargets(): void
    {
        mkdir($this->dir, 0700, true);
        $target = $this->dir . '-target.json';
        file_put_contents($target, '{"expires_at":1,"value":[]}');
        symlink($target, $this->dir . '/' . str_repeat('c', 64) . '.json');
        try {
            $this->purgeable()->purge();
            self::assertFileExists($target);
        } finally {
            unlink($target);
        }
    }

    private function victim(string $content = 'precious'): string
    {
        $victim = $this->dir . '-victim.txt';
        file_put_contents($victim, $content);

        return $victim;
    }

    public function testASymlinkSwappedInAfterTheCheckIsReplacedNotWrittenThrough(): void
    {
        mkdir($this->dir, 0700, true);
        $victim = $this->victim();
        $record = $this->stateFile('k');
        // The clock is read after the sidecar lock, right before the record is written: plant the link there.
        $clock = new class ($this->clock, $record, $victim) implements ClockInterface {
            public function __construct(private ClockInterface $inner, private string $link, private string $victim)
            {
            }

            public function now(): \DateTimeImmutable
            {
                if (! is_link($this->link)) {
                    symlink($this->victim, $this->link);
                }

                return $this->inner->now();
            }
        };
        try {
            (new FileStateStore($this->dir, $clock, 900))->put('k', ['v' => 1], 60);

            self::assertSame('precious', file_get_contents($victim));
            self::assertFalse(is_link($record));
            self::assertSame(0600, fileperms($record) & 0777);
        } finally {
            unlink($victim);
        }
    }

    public function testADanglingSymlinkSidecarIsRefusedAndNothingIsCreated(): void
    {
        mkdir($this->dir, 0700, true);
        $target = $this->dir . '-dangling.txt';
        symlink($target, $this->stateFile('k') . '.lock');
        try {
            $this->store->put('k', ['v' => 1], 60);
            self::fail('expected a refusal');
        } catch (RuntimeException $e) {
            self::assertSame('state path is a symlink', $e->getMessage());
        } finally {
            unlink($this->stateFile('k') . '.lock');
        }
        self::assertFileDoesNotExist($target);
    }

    public function testASymlinkSidecarToAnExistingFileLeavesItUntouched(): void
    {
        mkdir($this->dir, 0700, true);
        $victim = $this->victim();
        symlink($victim, $this->stateFile('k') . '.lock');
        try {
            try {
                $this->store->get('k');
                self::fail('expected a refusal');
            } catch (RuntimeException $e) {
                self::assertSame('state path is a symlink', $e->getMessage());
            }
            self::assertSame('precious', file_get_contents($victim));
        } finally {
            unlink($this->stateFile('k') . '.lock');
            unlink($victim);
        }
    }

    public function testAHardLinkedSidecarIsRefused(): void
    {
        mkdir($this->dir, 0700, true);
        $victim = $this->victim();
        link($victim, $this->stateFile('k') . '.lock');
        try {
            try {
                $this->store->put('k', ['v' => 1], 60);
                self::fail('expected a refusal');
            } catch (RuntimeException $e) {
                self::assertSame('state path is a symlink', $e->getMessage());
            }
            self::assertSame('precious', file_get_contents($victim));
        } finally {
            unlink($this->stateFile('k') . '.lock');
            unlink($victim);
        }
    }

    public function testPurgeNeverFollowsOrRemovesLinkedSidecars(): void
    {
        mkdir($this->dir, 0700, true);
        $victim = $this->victim();
        $sym = $this->dir . '/' . str_repeat('d', 64) . '.json.lock';
        $hard = $this->dir . '/' . str_repeat('e', 64) . '.json.lock';
        symlink($victim, $sym);
        link($victim, $hard);
        $this->age($hard, 5000);
        try {
            $this->purgeable()->purge();

            self::assertTrue(is_link($sym));
            self::assertFileExists($hard);
            self::assertSame('precious', file_get_contents($victim));
        } finally {
            unlink($sym);
            unlink($hard);
            unlink($victim);
        }
    }

    public function testThePurgeMarkerIsWrittenByRenameWithMode0600(): void
    {
        $this->store->put('a', ['v' => 1], 10);
        $this->advance(11);
        $this->purgeable()->purge();

        self::assertSame(0600, fileperms($this->dir . '/.purge') & 0777);
        self::assertSame([], glob($this->dir . '/.purge.*') ?: []);
    }
}
