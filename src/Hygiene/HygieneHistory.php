<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * The aggregates hygiene keeps between passes (FR-016): day records, the call-site registry, declined proposals.
 * Everything expires with `state.retention`. A store failure propagates: the caller reports it, it is never "no history".
 */
final class HygieneHistory
{
    public function __construct(
        private readonly StateStore $store,
        private readonly string $service,
        private readonly int $retention,
        private readonly DateTimeZone $timezone,
    ) {
    }

    /** The service-local calendar date of an instant, the unit of a day record. */
    public function dateOf(DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->timezone)->format('Y-m-d');
    }

    public function putDay(DayRecord $record): void
    {
        $this->store->put(Keys::hygieneDay($this->service, $record->date), $record->toArray(), $this->retention);
    }

    public function day(string $date): ?DayRecord
    {
        $raw = $this->store->get(Keys::hygieneDay($this->service, $date));

        return $raw === null ? null : DayRecord::fromArray($raw);
    }

    /**
     * Records of the `$count` dates ending at the date of `$end`, newest first; missing and invalid ones are skipped.
     *
     * @return array<string, DayRecord> date => record
     */
    public function days(DateTimeImmutable $end, int $count): array
    {
        $out = [];
        $at = $end->setTimezone($this->timezone);
        for ($i = 0; $i < $count; ++$i) {
            $date = $at->modify("-$i day")->format('Y-m-d');
            $record = $this->day($date);
            if ($record !== null) {
                $out[$date] = $record;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    public function registry(): array
    {
        /** @var array<string, array<string, mixed>> $reg */
        $reg = $this->store->get(Keys::hygieneSites($this->service)) ?? [];

        return $reg;
    }

    /**
     * Writes the registry MERGED with the stored one, per id: a pass that lost its lock and writes its older copy afterwards must not
     * erase what the other pass recorded (a report mark, a cleared retraction). The newer report mark wins; a retraction is
     * no longer pending when the other copy has reported the site since. This pass's own counting (seen, absent) is not merged: it restarts whenever a site returns.
     *
     * @param array<string, array<string, mixed>> $registry
     */
    public function putRegistry(array $registry): void
    {
        $stored = $this->registry();
        foreach ($stored as $key => $theirs) {
            if (!isset($registry[$key])) {
                continue; // a site this copy dropped (retracted or pruned) stays dropped; a site only the other pass knows is found again by the next pass
            }
            $mine = $registry[$key];
            $newer = self::at($theirs['reportedAt'] ?? null) > self::at($mine['reportedAt'] ?? null) ? $theirs : $mine;
            $registry[$key] = [
                ...$mine,
                'first' => self::at($theirs['first'] ?? null) !== 0 && self::at($theirs['first'] ?? null) < self::at($mine['first'] ?? null) ? $theirs['first'] : $mine['first'],
                'last' => self::at($theirs['last'] ?? null) > self::at($mine['last'] ?? null) ? $theirs['last'] : $mine['last'],
                'reportedAt' => $newer['reportedAt'] ?? null,
                'reportedCount' => $newer['reportedCount'] ?? null,
                'gonePending' => ($mine['gonePending'] ?? false) === true && self::at($theirs['reportedAt'] ?? null) <= self::at($mine['reportedAt'] ?? null)
                    && self::at($theirs['goneSentAt'] ?? null) <= self::at($mine['goneSentAt'] ?? null), // the other pass reported or retracted it since: no second notice
                'goneSentAt' => self::at($theirs['goneSentAt'] ?? null) > self::at($mine['goneSentAt'] ?? null) ? $theirs['goneSentAt'] : ($mine['goneSentAt'] ?? null),
            ];
        }
        $this->store->put(Keys::hygieneSites($this->service), $registry, $this->retention);
    }

    private static function at(mixed $v): int
    {
        return is_string($v) ? (int) strtotime($v) : 0;
    }

    /** Declines expire at `$now + $days`; entries already past `$now` are dropped on the way. */
    public function decline(string $id, DateTimeImmutable $now, int $days): void
    {
        $kept = [];
        foreach ($this->declinedMap() as $other => $until) {
            if ($until > $now) {
                $kept[$other] = $until;
            }
        }
        $kept[$id] = $now->modify("+$days day");
        $furthest = max(array_map(static fn (DateTimeImmutable $t): int => $t->getTimestamp(), $kept));
        $this->store->put(
            Keys::hygieneDeclined($this->service),
            array_map(static fn (DateTimeImmutable $t): string => $t->format(DATE_ATOM), $kept),
            max($this->retention, $furthest - $now->getTimestamp()),
        );
    }

    /** When the report `$name` was last posted, or null. */
    public function sentAt(string $name): ?DateTimeImmutable
    {
        $iso = $this->store->get(Keys::hygieneSent($this->service))[$name] ?? null;
        $t = is_string($iso) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $iso) : false;

        return $t === false ? null : $t;
    }

    public function markSent(string $name, DateTimeImmutable $at): void
    {
        $all = $this->store->get(Keys::hygieneSent($this->service)) ?? [];
        $all[$name] = $at->format(DATE_ATOM);
        $this->store->put(Keys::hygieneSent($this->service), $all, $this->retention);
    }

    public function isDeclined(string $id, DateTimeImmutable $now): bool
    {
        $until = $this->declinedMap()[$id] ?? null;

        return $until !== null && $until > $now;
    }

    /** @return array<string, DateTimeImmutable> */
    private function declinedMap(): array
    {
        $out = [];
        foreach ($this->store->get(Keys::hygieneDeclined($this->service)) ?? [] as $id => $iso) {
            $t = is_string($iso) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $iso) : false;
            if ($t !== false) {
                $out[(string) $id] = $t;
            }
        }

        return $out;
    }
}
