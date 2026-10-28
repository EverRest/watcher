<?php

declare(strict_types=1);

namespace Watcher\Source;

/** Reads the result of a Loki reply: the result list, the [epoch, count] points, and the records that continuation lines join. */
final class LokiResponse
{
    /**
     * @param array<mixed> $data
     *
     * @return list<mixed> each item is one reply entry as sent: the readers check its shape
     */
    public static function results(array $data): array
    {
        $result = $data['data']['result'] ?? [];

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * The [epoch seconds, count] pairs of one series, or null when a pair is unusable (the series is then counted as one bad entry).
     *
     * @return list<array{int, int}>|null
     */
    public static function points(mixed $values): ?array
    {
        if (!is_array($values)) {
            return null;
        }
        $points = [];
        foreach ($values as $point) {
            $at = is_array($point) ? RemoteValue::seconds($point[0] ?? null) : null;
            $count = is_array($point) ? RemoteValue::count($point[1] ?? null) : null;
            if ($at === null || $count === null) {
                return null;
            }
            $points[] = [$at, $count];
        }

        return $points;
    }

    /**
     * Loki returns one entry per physical line: join continuation lines to the record they follow,
     * per stream, so interleaved streams never mix. A record split by a page edge stays split.
     *
     * @param list<array{int, string, array<string, string>}> $entries time-ordered
     *
     * @return list<array{int, string, array<string, string>}> records ordered by their first line
     */
    public static function records(array $entries, LineParser $parser): array
    {
        $out = [];
        $open = []; // stream key => index into $out
        foreach ($entries as [$ts, $line, $labels]) {
            $stream = json_encode($labels);
            if (!isset($open[$stream]) && $parser->isOrphanTrace($line, isset($labels['level']))) {
                continue;
            }
            if (isset($open[$stream]) && $parser->continues($line, $out[$open[$stream]][1], isset($labels['level']))) {
                [$firstTs, $text, $firstLabels] = $out[$open[$stream]];
                $out[$open[$stream]] = [$firstTs, $text . "\n" . $line, $firstLabels];
                continue;
            }
            $open[$stream] = count($out);
            $out[] = [$ts, $line, $labels];
        }

        return $out;
    }
}
