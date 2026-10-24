<?php

declare(strict_types=1);

namespace Watcher\Observe;

use DateTimeImmutable;
use DateTimeZone;
use ErrorException;
use JsonException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Appends events as JSON lines to a local file (0600, dir 0700) under flock, with rotation and retention. */
final class FileSink implements EventSink
{
    public function __construct(
        private readonly string $path,
        private readonly string $rotate,
        private readonly int $maxBytes,
        private readonly int $maxFiles,
        private readonly int $retentionDays,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $log = null,
    ) {
    }

    public function emit(array $events): void
    {
        if ($this->path === '' || $this->path[0] !== '/' || in_array('..', explode('/', $this->path), true)) {
            throw new SinkFailed('file path invalid');
        }

        set_error_handler(static function (int $no, string $str, string $file, int $line): never {
            throw new ErrorException($str, 0, $no, $file, $line);
        });
        try {
            $this->write($events);
        } catch (SinkFailed $e) {
            throw $e;
        } catch (JsonException) {
            throw new SinkFailed('file event not encodable');
        } catch (Throwable) {
            throw new SinkFailed('file not writable');
        } finally {
            restore_error_handler();
        }
    }

    /** @param list<array<string, mixed>> $events */
    private function write(array $events): void
    {
        $payload = '';
        foreach ($events as $event) {
            $payload .= json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
            chmod($dir, 0700);
        }
        $this->requirePrivateDirectory($dir);

        // Bounded: a rotation or a concurrent rotator forces one reopen onto the fresh active file.
        for ($try = 0; $try < 3; $try++) {
            clearstatcache(true, $this->path);
            $before = $this->lstat($this->path);
            if ($before !== false && ($before['mode'] & 0170000) !== 0100000) {
                // Never open a symlink (it would write through it), a FIFO (it would block) or a device.
                throw new SinkFailed(($before['mode'] & 0170000) === 0120000 ? 'file is a symlink' : 'file not writable');
            }
            $old = umask(0077); // a file we create is 0600 from its first byte; PHP has no fchmod
            try {
                $fh = fopen($this->path, 'c+');
            } finally {
                umask($old);
            }
            if ($fh === false) {
                throw new SinkFailed('file not writable');
            }
            try {
                if (!flock($fh, LOCK_EX)) {
                    throw new SinkFailed('file not writable');
                }
                clearstatcache(true, $this->path);
                $path = lstat($this->path);
                $open = fstat($fh);
                if ($path === false || $open === false || ($path['mode'] & 0170000) === 0120000) {
                    throw new SinkFailed('file is a symlink');
                }
                if ($path['ino'] !== $open['ino'] || $path['dev'] !== $open['dev']) {
                    continue; // another process rotated it between open and lock
                }
                if ($open['nlink'] !== 1) {
                    throw new SinkFailed('file is a hard link');
                }
                if (($open['mode'] & 0170000) !== 0100000 || $this->foreign($open['uid'])) {
                    throw new SinkFailed('file not writable');
                }
                if (($open['mode'] & 077) !== 0) {
                    throw new SinkFailed('file mode not private'); // never chmod by name: the operator fixes it
                }
                if ($this->rotateIfNeeded(strlen($payload), $fh)) {
                    continue; // we hold the lock on the rotated-away file; reopen the new active one
                }
                if (fseek($fh, 0, SEEK_END) !== 0 || fwrite($fh, $payload) !== strlen($payload)) {
                    throw new SinkFailed('file not writable');
                }
                fflush($fh);
                $this->prune();

                return;
            } finally {
                fclose($fh);
            }
        }

        throw new SinkFailed('file not writable');
    }

    /** @return list<string> sibling names matching base + $suffix (anchored), so lookalikes are never touched */
    private function siblings(string $suffix): array
    {
        $re = '/^' . preg_quote(basename($this->path), '/') . $suffix . '$/';

        return array_values(array_filter(scandir(dirname($this->path)) ?: [], static fn (string $n): bool => preg_match($re, $n) === 1));
    }

    /** @param resource $active the locked active file */
    private function rotateIfNeeded(int $incoming, $active): bool
    {
        clearstatcache();
        $size = (int) filesize($this->path);
        if ($size === 0) {
            return false;
        }

        if ($this->rotate === 'daily') {
            $utc = new DateTimeZone('UTC');
            $day = (new DateTimeImmutable('@' . filemtime($this->path)))->setTimezone($utc)->format('Y-m-d');
            if ($day === $this->clock->now()->setTimezone($utc)->format('Y-m-d')) {
                return false;
            }
            $dated = $this->path . '-' . $day;
            clearstatcache(true, $dated);
            if ($this->lstat($dated) === false) {
                rename($this->path, $dated); // rename replaces a name planted meanwhile, it never follows it
            } elseif (!$this->appendInto($active, $dated)) {
                return false; // fail open: keep appending to the active file, try again at the next flush
            } else {
                unlink($this->path);
            }
            $kept = $this->siblings('-\d{4}-\d{2}-\d{2}');
            sort($kept);
            foreach (array_slice($kept, 0, max(0, count($kept) - $this->maxFiles)) as $old) {
                unlink(dirname($this->path) . '/' . $old);
            }

            return true;
        }

        if ($size + $incoming <= $this->maxBytes) {
            return false;
        }
        $last = $this->path . '.' . $this->maxFiles;
        if (file_exists($last)) {
            unlink($last);
        }
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            if (file_exists($this->path . '.' . $i)) {
                rename($this->path . '.' . $i, $this->path . '.' . ($i + 1));
            }
        }
        rename($this->path, $this->path . '.1');

        return true;
    }

    /**
     * Appends the active file to an existing dated file, only when that name is a regular file we own with one link
     * and the handle we opened is the file the name still points at. Any other kind of name (symlink, dangling link,
     * hard link, FIFO, foreign owner) is left untouched and the rotation is skipped, with a note without the path.
     * ponytail: between lstat and fopen a swap to a FIFO could block the open; O_NONBLOCK needs ext-dio.
     *
     * @param resource $active
     */
    private function appendInto($active, string $dated): bool
    {
        $st = $this->lstat($dated);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['nlink'] !== 1 || $this->foreign($st['uid'])) {
            return $this->skipped();
        }
        try {
            $out = fopen($dated, 'a');
        } catch (ErrorException) {
            return $this->skipped();
        }
        if ($out === false) {
            return $this->skipped();
        }
        try {
            clearstatcache(true, $dated);
            $held = fstat($out);
            $now = $this->lstat($dated);
            if ($held === false || $now === false || ($now['mode'] & 0170000) !== 0100000 || $held['dev'] !== $now['dev'] || $held['ino'] !== $now['ino'] || $held['nlink'] !== 1) {
                return $this->skipped();
            }
            if (rewind($active) === false) {
                throw new SinkFailed('file not writable');
            }
            while (!feof($active)) {
                $chunk = fread($active, 65536);
                if ($chunk === false || ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk))) {
                    throw new SinkFailed('file not writable');
                }
            }
        } finally {
            fclose($out);
        }

        return true;
    }

    /** @return array<int|string, int>|false false when the name does not exist (the error handler turns the warning into an exception) */
    private function lstat(string $path): array|false
    {
        try {
            return lstat($path);
        } catch (ErrorException) {
            return false;
        }
    }

    private function skipped(): bool
    {
        try {
            $this->log?->warning('observe file rotation skipped: the dated file is not a private regular file');
        } catch (Throwable) {
            // not a write failure: a broken logger must not fail the sink
        }

        return false;
    }

    private function foreign(int $uid): bool
    {
        return function_exists('posix_geteuid') && $uid !== posix_geteuid();
    }

    /** The state directory rule: ours, and not group/world writable unless it has the sticky bit. */
    private function requirePrivateDirectory(string $dir): void
    {
        $st = $this->lstat($dir);
        if ($st === false || ($st['mode'] & 0170000) === 0120000 || $this->foreign($st['uid']) || (($st['mode'] & 0022) !== 0 && ($st['mode'] & 01000) === 0)) {
            throw new SinkFailed('file directory not private');
        }
    }

    /** Delete this sink's files (anchored name match) older than the retention window. */
    private function prune(): void
    {
        $cutoff = $this->clock->now()->getTimestamp() - $this->retentionDays * 86400;
        $dir = dirname($this->path);
        foreach ($this->siblings('(\.\d+|-\d{4}-\d{2}-\d{2})?') as $name) {
            $f = $dir . '/' . $name;
            if (is_file($f) && filemtime($f) < $cutoff) {
                unlink($f);
            }
        }
    }
}
