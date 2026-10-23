<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
use RuntimeException;
use Watcher\State\FileStateStore;
use Watcher\State\StateStore;

final class FileStateStoreTest extends StateStoreContractTest
{
    private string $dir;

    protected function makeStore(ClockInterface $clock): StateStore
    {
        $this->dir = sys_get_temp_dir() . '/watcher-state-' . bin2hex(random_bytes(6));

        return new FileStateStore($this->dir, $clock);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
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
}
