<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
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

    public function testNeverWritesOutsideItsDirectory(): void
    {
        $parent = dirname($this->dir);
        $before = glob($parent . '/*evil*') ?: [];
        $this->store->put('../evil', ['x' => 1], 60);

        self::assertSame($before, glob($parent . '/*evil*') ?: []);
        self::assertNotEmpty(glob($this->dir . '/*'));
    }
}
