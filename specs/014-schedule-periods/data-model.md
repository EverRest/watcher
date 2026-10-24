# Data Model: Schedule Periods

## Configuration shape (after validation, `WatchConfig::$schedule` etc.)

```php
schedule = [
  'run_at'        => ['06:00'],            // always a sorted list internally; [] when `every` is used
  'every'         => null,                 // or 'PT1H' ... 'PT12H'
  'timezone'      => 'UTC',
  'window'        => 'PT24H',              // string when set or single run_at; null = contiguous (derived)
  'catch_up'      => 1,
  'ingestion_delay' => 'PT0S',
  'lock_ttl' => 900, 'run_deadline' => 810,
  'runs_per_day'  => 1,                    // derived, nominal (24h / period; count of list entries)
]
analysis = [... 'max_runs_per_day' => null|int, 'run_budget_usd' => null|float]
baseline = ['mode' => 'previous|rolling|weekday|same_slot_yesterday|same_slot_last_week', ...]
heartbeat = ['stale_after' => <seconds>, ...]
```

## Boundary and window key

- **Boundary**: an instant. `every`: local midnight + k x period (wall clock) for k >= 0 while on the same local date;
  `run_at`: each listed local time. Nonexistent wall time: dropped (`every`) / first instant after the gap (`run_at`,
  unchanged). Ambiguous wall time: first occurrence.
- **Window**: `[start, end)` UTC. `end = boundary - ingestion_delay`. `start` = previous boundary - delay (default), or
  `end - window` (explicit `window`; whole-day windows keep the same-wall-clock rule of today).
- **Effective window** (what is read and stored): `[max(start, hw), end)`.
- **Window key**: `Watcher::windowKey(end)` = `Y-m-d\TH:i:s\Z` of the (shifted) end, unchanged.

## State keys (all prefixed by `Keys::` builders; components URL-encoded)

| Key | Value | Written | TTL | Notes |
|---|---|---|---|---|
| `hw:<service>` | `{v:1, end:'<ISO UTC>', run:'<window key>'}` | with the run record of a window finished reported/quiet/baselined, only if `end` is later than the stored one | 3 x retention (like heartbeat) | read before reading the source; dry run reads, never writes |
| `runs:<service>:<local day>` | integer counter | `increment` after the lock is held, real runs only | 172800 (like spend) | cap check = returned value > `max_runs_per_day` |
| `alert`/`notice` once-a-day marker for `capped` | via existing `noticeOnce` | | existing | no new key kind |
| `snap`, `run`, `lock`, `sent`, `triage`, `failn`, `reported`, `verdict` | unchanged | | unchanged | still keyed by window end |
| `failn:<service>:<local day>` (new part under existing kind) | counter | each failure notice, only when runs_per_day > 1 | spend TTL | FR-015 |

`Keys::ttl()`: `HIGH_WATER = 'hw'` joins `HEARTBEAT`/`TRIGGER` (3 x retention); `RUNS = 'runs'` joins `SPEND`/`ALERT`.

## Snapshot (`snap:<svc>:<end>`)

Unchanged fields. `start` now holds the **effective** start (so it may be later than `end - window`). Hourly snapshots
are small (groups only); 24 per day.

## Run record (`run:<svc>:<end>`)

Unchanged plus optional `gap: {from, to}` (UTC ISO) and `catchUp: <int>` for the JSON summary; `capped` runs write no run
record for the window (the window stays open), only the notice marker.

## RunOutcome

Adds `capped` (value `capped`). Listed in JSON summary, heartbeat OK set, `watcher status` text.

## Comparison

`usedMode` may be `same_slot_yesterday|same_slot_last_week`; `reason` may be `slot_missing`.
