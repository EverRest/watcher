<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\State\FileStateStore;
use Watcher\Tests\Fakes\FakeLogSource;

/** R-2: a state directory the store refuses is reported by name in the run notice, not as "internal error"; group write is fine. */
final class StateDirectoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watcher-statedir-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        chmod($this->dir, 0700);
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir);
    }

    /** @return array{\Watcher\RunResult, Harness} */
    private function trigger(int $mode): array
    {
        chmod($this->dir, $mode);
        $h = new Harness();
        $h->state = new FileStateStore($this->dir, $h->clock, 900);
        $events = Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha'); // a first run: Warning only records a baseline (FR-035)
        $r = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();

        return [$r, $h];
    }

    public function testAGroupWritableDirectoryRunsNormally(): void
    {
        [$r] = $this->trigger(0775);

        self::assertSame(RunOutcome::Baselined, $r->outcome);
    }

    public function testAWorldWritableDirectoryFailsWithAMessageNamingTheSettingAndNoPath(): void
    {
        [$r, $h] = $this->trigger(0777);

        self::assertSame(RunOutcome::Failed, $r->outcome);
        self::assertStringContainsString('state.file.path', (string) $r->failureReason);
        self::assertStringNotContainsString('internal error', (string) $r->failureReason);
        self::assertStringNotContainsString($this->dir, (string) $r->failureReason);
        self::assertStringContainsString('state.file.path', str_replace("\u{200B}", '', implode("\n", $h->sentTexts())));
    }
}
