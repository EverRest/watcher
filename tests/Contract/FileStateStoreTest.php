<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
use RuntimeException;
use Watcher\State\FileStateStore;
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
        self::assertNotInstanceOf(Purgeable::class, new \Watcher\Tests\Fakes\InMemoryStateStore($this->clock));
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
}
