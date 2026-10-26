<?php

declare(strict_types=1);

namespace Watcher\Report;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Watcher\Observe\EventSchema;

/** Read-only scan of the observe.file JSONL for one service@environment and ISO week. */
final class EventLog
{
    public const MAX_LINE_BYTES = 524288;

    /** @var list<string> */
    public const RUN_FIELDS = ['outcome', 'release', 'dry_run', 'muted', 'groups_total', 'tokens_in', 'tokens_out', 'tokens_cached', 'cost_usd', 'findings_by_severity'];

    /** @var list<string> */
    public const GROUP_FIELDS = ['fingerprint', 'level', 'count', 'trend', 'watch_rules'];

    /** @var list<string> */
    public const FINDING_FIELDS = ['fingerprint', 'severity'];

    /**
     * @param array{enabled: bool, path: string, rotate: string, max_bytes: int, max_files: int, retention_days: int, include_text: bool} $file
     */
    public function __construct(
        private readonly array $file,
        private readonly string $service,
        private readonly string $environment,
    ) {
    }

    public function read(Week $week): EventLogSnapshot
    {
        if (!$this->file['enabled']) {
            throw new EventLogException('observe.file.path unreadable: file sink disabled');
        }
        $path = $this->file['path'];
        if ($path === '' || !is_readable($path) && !$this->hasRotated($path)) {
            throw new EventLogException('observe.file.path unreadable: ' . $path);
        }

        $runs = [];
        $groups = [];
        $skipped = ['bad_json' => 0, 'bad_schema' => 0, 'bad_type' => 0, 'bad_pattern' => 0, 'other_service' => 0, 'long_line' => 0];

        foreach ($this->paths($path) as $file) {
            $fh = fopen($file, 'rb');
            if ($fh === false) {
                throw new EventLogException('observe.file.path unreadable: ' . $path);
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    if (strlen($line) > self::MAX_LINE_BYTES) {
                        ++$skipped['long_line'];
                        continue;
                    }
                    $line = rtrim($line, "\r\n");
                    if ($line === '') {
                        continue;
                    }
                    try {
                        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        ++$skipped['bad_json'];
                        continue;
                    }
                    if (!is_array($row)) {
                        ++$skipped['bad_json'];
                        continue;
                    }
                    $this->parseLine($row, $week, $runs, $groups, $skipped);
                }
            } finally {
                fclose($fh);
            }
        }

        return new EventLogSnapshot($runs, $groups, $skipped, $this->file['path']);
    }

    private function hasRotated(string $path): bool
    {
        return $this->paths($path) !== [];
    }

    /** @return list<string> */
    private function paths(string $path): array
    {
        $dir = dirname($path);
        $base = basename($path);
        $out = [];
        if ($this->file['rotate'] === 'daily') {
            $dated = [];
            foreach (scandir($dir) ?: [] as $name) {
                if (preg_match('/^' . preg_quote($base, '/') . '-(\d{4}-\d{2}-\d{2})$/', $name, $m) === 1) {
                    $dated[$m[1]] = $dir . '/' . $name;
                }
            }
            ksort($dated);
            $out = array_values($dated);
        } else {
            for ($i = $this->file['max_files']; $i >= 1; --$i) {
                $p = $path . '.' . $i;
                if (is_file($p)) {
                    $out[] = $p;
                }
            }
        }
        if (is_file($path)) {
            $out[] = $path;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $runs
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, int> $skipped
     */
    private function parseLine(array $row, Week $week, array &$runs, array &$groups, array &$skipped): void
    {
        if (($row['schema'] ?? null) !== EventSchema::ID) {
            ++$skipped['bad_schema'];
            return;
        }
        $type = $row['event_type'] ?? null;
        if (!is_string($type) || !in_array($type, ['run', 'group', 'finding'], true)) {
            ++$skipped['bad_type'];
            return;
        }
        foreach (['service', 'environment', 'window_end'] as $f) {
            if (!$this->pattern($f, $row[$f] ?? null)) {
                ++$skipped['bad_pattern'];
                return;
            }
        }
        if ($row['service'] !== $this->service || $row['environment'] !== $this->environment) {
            ++$skipped['other_service'];
            return;
        }
        try {
            $end = new DateTimeImmutable((string) $row['window_end'], new DateTimeZone('UTC'));
        } catch (\Exception) {
            ++$skipped['bad_pattern'];
            return;
        }
        if (!$week->containsWindowEnd($end)) {
            return;
        }
        if ($type === 'run') {
            if (($row['dry_run'] ?? false) === true) {
                return;
            }
            $outcome = $row['outcome'] ?? null;
            if (!in_array($outcome, ['reported', 'quiet', 'baselined', 'failed'], true)) {
                return;
            }
            $end = (string) $row['window_end'];
            $ts = is_string($row['ts'] ?? null) ? $row['ts'] : '';
            $slice = $this->slice($row, self::RUN_FIELDS);
            $slice['ts'] = $ts;
            $prev = $runs[$end] ?? null;
            if ($prev === null) {
                $runs[$end] = $slice;
            } elseif ($ts >= ($prev['ts'] ?? '')) {
                $slice['tokens_in'] = (int) ($prev['tokens_in'] ?? 0) + (int) ($slice['tokens_in'] ?? 0);
                $slice['tokens_out'] = (int) ($prev['tokens_out'] ?? 0) + (int) ($slice['tokens_out'] ?? 0);
                $slice['cost_usd'] = (float) ($prev['cost_usd'] ?? 0) + (float) ($slice['cost_usd'] ?? 0);
                $slice['muted'] = (int) ($prev['muted'] ?? 0) + (int) ($slice['muted'] ?? 0);
                $runs[$end] = $slice;
            } else {
                $runs[$end]['tokens_in'] = (int) ($runs[$end]['tokens_in'] ?? 0) + (int) ($slice['tokens_in'] ?? 0);
                $runs[$end]['tokens_out'] = (int) ($runs[$end]['tokens_out'] ?? 0) + (int) ($slice['tokens_out'] ?? 0);
                $runs[$end]['cost_usd'] = (float) ($runs[$end]['cost_usd'] ?? 0) + (float) ($slice['cost_usd'] ?? 0);
                $runs[$end]['muted'] = (int) ($runs[$end]['muted'] ?? 0) + (int) ($slice['muted'] ?? 0);
            }
            return;
        }
        if ($type === 'group') {
            foreach (self::GROUP_FIELDS as $f) {
                if (!array_key_exists($f, $row)) {
                    ++$skipped['bad_pattern'];
                    return;
                }
            }
            if (!$this->pattern('fingerprint', $row['fingerprint']) || !$this->pattern('level', $row['level']) || !$this->pattern('trend', $row['trend'])) {
                ++$skipped['bad_pattern'];
                return;
            }
            $key = (string) $row['window_end'] . "\0" . (string) $row['fingerprint'];
            $ts = is_string($row['ts'] ?? null) ? $row['ts'] : '';
            $prev = $groups[$key] ?? null;
            $prevTs = is_array($prev) && is_string($prev['ts'] ?? null) ? $prev['ts'] : '';
            if ($prev === null || $ts >= $prevTs) {
                $groups[$key] = [
                    'window_end' => (string) $row['window_end'],
                    'fingerprint' => (string) $row['fingerprint'],
                    'level' => (string) $row['level'],
                    'count' => (int) $row['count'],
                    'trend' => (string) $row['trend'],
                    'ts' => $ts,
                    'release' => is_string($row['release'] ?? null) ? $row['release'] : null,
                ];
            }
            return;
        }
        foreach (self::FINDING_FIELDS as $f) {
            if (!array_key_exists($f, $row)) {
                ++$skipped['bad_pattern'];
                return;
            }
        }
        if (!$this->pattern('fingerprint', $row['fingerprint']) || !$this->pattern('severity', $row['severity'])) {
            ++$skipped['bad_pattern'];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function slice(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $out[$f] = $row[$f];
            }
        }

        return $out;
    }

    private function pattern(string $field, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (!is_string($value)) {
            return false;
        }
        $p = EventSchema::PATTERNS[$field] ?? EventSchema::ENUMS[$field] ?? null;
        if ($p === null) {
            return true;
        }
        if (is_array($p)) {
            return in_array($value, $p, true);
        }

        return preg_match($p, $value) === 1;
    }
}
