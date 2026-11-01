<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use DateTimeZone;

/** Reads the parts of a Datadog reply that are not entries: the buckets of an aggregate and the ISO timestamps. Pure. */
final class DatadogResponse
{
    public static function time(string $iso): ?DateTimeImmutable
    {
        try {
            return $iso === '' ? null : (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function buckets(array $data): array
    {
        $buckets = $data['data']['buckets'] ?? [];

        return is_array($buckets) ? array_values(array_filter($buckets, 'is_array')) : [];
    }
}
