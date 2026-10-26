<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\ReadOnlyFileStore;
use Watcher\Mcp\ReadOnlyViolation;
use Watcher\State\FileStateStore;
use Watcher\Tests\Fakes\FrozenClock;

final class ReadOnlyFileStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcp-fs-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, string> file => md5 + mtime */
    private function snapshot(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            $out[basename($f)] = md5_file($f) . filemtime($f);
        }

        return $out;
    }

    public function testItReadsWhatTheDriverWroteAndNeverChangesTheDirectory(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-25T00:00:00Z'));
        $writer = new FileStateStore($this->dir, $clock);
        $writer->put('live', ['v' => 1], 3600);
        $writer->put('old', ['v' => 2], 10);
        $clock->advance('+1 minute');
        $before = $this->snapshot();

        $ro = new ReadOnlyFileStore($this->dir, $clock);
        self::assertSame(['v' => 1], $ro->get('live'));
        self::assertNull($ro->get('old')); // expired: ignored
        self::assertNull($ro->get('missing'));
        self::assertSame($before, $this->snapshot()); // the expired file is still there, nothing was created
    }

    public function testAMissingDirectoryIsNotCreated(): void
    {
        $ro = new ReadOnlyFileStore($this->dir . '/nope', new FrozenClock(new \DateTimeImmutable('2026-10-25T00:00:00Z')));
        self::assertNull($ro->get('x'));
        self::assertDirectoryDoesNotExist($this->dir . '/nope');
    }

    public function testSymlinkedRecordsAreNotFollowedAndWritesThrow(): void
    {
        mkdir($this->dir);
        symlink('/etc/hosts', $this->dir . '/' . hash('sha256', 'k') . '.json');
        $ro = new ReadOnlyFileStore($this->dir, new FrozenClock(new \DateTimeImmutable('2026-10-25T00:00:00Z')));
        self::assertNull($ro->get('k'));
        $this->expectException(ReadOnlyViolation::class);
        $ro->put('k', [], 1);
    }
}
