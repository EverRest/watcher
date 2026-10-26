<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use Watcher\Hygiene\DayRecord;

/** Builds stored day records for the proposal tests. */
final class DayFactory
{
    /**
     * @param list<array{fp: string, tpl?: ?string, th?: ?string, level?: string, n?: int, first?: string, tokens?: int}> $families
     */
    public static function day(string $date, array $families): DayRecord
    {
        $rows = [];
        foreach ($families as $f) {
            $row = [
                'fp' => $f['fp'],
                'tpl' => $f['tpl'] ?? null,
                'level' => $f['level'] ?? 'info',
                'n' => $f['n'] ?? 100,
                'first' => $f['first'] ?? $date . 'T10:00:00+00:00',
                'last' => $date . 'T20:00:00+00:00',
                'tokens' => $f['tokens'] ?? 0,
            ];
            if (isset($f['th'])) {
                $row['th'] = $f['th'];
            }
            $rows[] = $row;
        }

        return new DayRecord($date, 1000, false, 0, [], $rows, [], []);
    }

    /**
     * Same families on `$count` consecutive dates ending 2026-10-07, newest first (as HygieneHistory::days returns them).
     *
     * @param list<array{fp: string, tpl?: ?string, th?: ?string, level?: string, n?: int, first?: string, tokens?: int}> $families
     * @return array<string, DayRecord>
     */
    public static function days(int $count, array $families): array
    {
        $out = [];
        $end = new DateTimeImmutable('2026-10-07');
        for ($i = 0; $i < $count; ++$i) {
            $date = $end->modify("-$i day")->format('Y-m-d');
            $out[$date] = self::day($date, $families);
        }

        return $out;
    }
}
