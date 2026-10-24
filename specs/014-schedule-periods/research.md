# Research: Schedule Periods

Only decisions where more than one design was reasonable. Facts are from the code as of 2026-10-24
(`RunGate`, `Watcher::run/locked`, `Keys`, `BaselineResolver`, `Heartbeat`).

## R1. Due detection: from "the due hour" to "the latest undone boundary"

- **Decision**: `RunGate::pending($now)` is pure clock arithmetic and returns up to `catch_up` windows, newest first, for
  boundaries `<= now`. For `every` all boundaries within `catch_up` periods back qualify; for `run_at` only a boundary
  inside `[time, time + 1 h)` qualifies (today's rule, so a list needs no catch-up). `Watcher` then drops the ones with a
  run record or delivered marker (one `get` each) and runs the rest oldest first.
- **Why**: keeps "a trigger outside the due period costs nothing and touches no state" for the common not-due case
  (`pending` is empty before any read) while the done-check costs at most `catch_up` reads. An alternative that stores
  "next due" in state is a second source of truth next to the run records.
- **Alternatives**: store `lastDone` and compute from it (breaks when a record expires or the state is lost); loop over
  all boundaries since the last heartbeat (unbounded after long outages, exactly the storm to avoid).
- **Consequence**: a daily config behaves as before (one candidate, 1 h due). A trigger inside the period but after the
  boundary is due for `every`; this is the generalisation of "due hour" asked for by the owner.

## R2. Overlap, the high-water mark, and what it can and cannot do

- **Decision**: the read range is `[max(window.start, hw), window.end)` where `hw` = end of the newest window that
  finished `reported`, `quiet` or `baselined`. The snapshot stores the **effective** start, so the next run's
  "previous window" lookup (`snap:<svc>:<effective start>`) is exact and per-hour scaling is right.
  `hw` is written only with the run record, never with a snapshot alone: an analysis failure writes the snapshot (spec
  001, base for the next comparison) but must leave the window re-readable for the retry.
- **Why the lead's draft changed slightly**: the draft said `hw` = latest *event time* processed. Event time is not
  monotone with ingestion (late lines carry older timestamps), is unknown on a quiet run (no events), and equal-timestamp
  lines at the edge would be dropped. The window end is known, monotone, and half-open ranges tile exactly. Result: for
  contiguous windows `hw == window.start` and the clamp is a no-op (default behaviour unchanged, zero extra reads).
- **Honest limit**: with the clamp, a longer `window` does **not** re-read old events, so overlap cannot rescue
  late-indexed lines (their timestamps lie below `hw`). That job belongs to `ingestion_delay` (R6). What overlap does
  buy: after a failed (unrecorded) window `hw` stays old, so the next run re-reads the failed span up to `window` length;
  and a deliberately longer `window` bounds how far back such a recovery goes. Allowing `window` > period is therefore
  "bounded self-healing", not "second look at the same hour". Documented in operations.md.
- **Dedupe layers**: (1) clamp, so an event is counted in one run; (2) `reported:<svc>:<fp>` markers (existing): a group
  already reported is a candidate again only when `Grown` (count >= factor x the previous *effective* window);
  (3) lock/run record per window end. Counts for growth are per effective window (documented).
- **Alternatives**: per-event dedupe by id (sources have no stable ids; Datadog/Loki lines carry none we persist, and
  persisting them breaks Principle I); per-fingerprint `lastSeenTs` (needs a write per fingerprint per run).

## R3. Catch-up

- **Decision**: `catch_up` default 1: only the newest missed boundary runs, with a normal one-period window (`hw` is older than its start, so the clamp changes nothing and the
  window never grows past its configured length). The remaining span `[hw, window.start)` is the **gap** and is reported (FR-012). `catch_up = N` runs the N
  newest missed boundaries oldest first inside one process; the `service_time_budget` (fleet) or
  `schedule.run_deadline` x N bounds it (each window gets its own run deadline; the loop stops when the fleet budget is
  spent and the rest becomes gap).
- **Why not run everything missed**: a 3-day outage at PT1H would be 72 runs, each with model calls, in one trigger.
- **Gap definition**: `hw < window.start` after the clamp. It includes windows that failed and windows never triggered;
  wording does not claim which (R7).
- **Edge**: `catch_up > 1` only with `every` (a `run_at` list has a 1 h due period, nothing to catch up).

## R4. Cost guards

- **Decision**: `analysis.max_runs_per_day` is an atomic `increment` of `runs:<svc>:<local day>` taken after the lock is
  held and before any read; the 7th increment of a cap of 6 ends `capped` (new `RunOutcome::Capped`, exit 0, one notice
  a day via `noticeOnce`, heartbeat records it as ok-with-reason so `status` is not stale). `run_budget_usd` is an extra
  ceiling in the existing reservation: a step starts only if `run spend + worst case <= run_budget_usd` as well as the
  daily conditions.
- **Defaults**: guards exist only when more than one run a day: `max_runs_per_day = 2 x runs_per_day` (one retry per
  window), `run_budget_usd = daily_budget_usd / runs_per_day x 2` (the factor 2 lets a busy hour use two shares; the daily
  cap still bounds the day). A single daily run keeps today's behaviour exactly (flag pattern of spec 012).
- **Alternative**: a plain log warning when the cap is hit - rejected by Principle VII. Using `failed` for `capped` -
  rejected: it makes the host alert every hour for a limit working as designed; `capped` is loud through the notice.
- **Runs per day on DST days** is the count of boundaries of that local date (23/24/25-style variation), but the cap is
  computed from the nominal (non-DST) count so it does not change on those days.

## R5. Baselines for sub-day periods

- **Decision**: `same_slot_yesterday` / `same_slot_last_week` look up the one snapshot whose window **end** is the same
  local wall-clock boundary 1 / 7 local days earlier (via the generalised `RunGate::endBack`, which resolves DST gaps
  and repeats like runs do). One snapshot only (`windows` is ignored); value = its count scaled per hour as spec 004 R5.
  Missing -> `previous`, `reason = slot_missing`, digest says so (FR-013, Principle VII).
- **Why single-snapshot**: hourly noise on one day is high, but a median of several same-slot days needs a week-plus of
  history per slot and reuses `weekday`'s mechanics; deferred, not needed to answer the owner's request.
- **Rejected for sub-day**: `rolling`/`weekday` assume `endBack(end, k x wholeDays)` and one run a day (spec 004 R1/R2).
- **Storage**: hourly means 24 snapshots a day per service; at 30 days retention about 720 small documents. Documented;
  `same_slot_last_week` requires `state.retention >= 8 days` (validated).

## R6. Ingestion delay

- **Decision**: window end = boundary - `ingestion_delay`; start = previous boundary - delay (contiguous). Keys use the
  shifted end. The run still fires at the boundary (data to `boundary - delay` is indexed by then).
- **Why not "wait and re-read"**: re-reading the same range doubles source cost and needs dedupe by event id (R2).
- **Default** `PT0S` (today); recommendation for Loki behind promtail/Alloy: `PT2M`.
- **Daily configs** may set it too; windows stay adjoining because both ends shift.

## R7. DST

- **Decision**: grid = local midnight + k x period computed as wall-clock times, resolved with the existing
  `runAtOn` rules (nonexistent -> the transition instant is **not** used as a boundary for `every`: it is dropped; for a
  `run_at` list it keeps today's behaviour, first instant after the gap). Ambiguous -> first occurrence only.
  Window start = previous boundary instant (contiguous), so the window after a skipped boundary is the real time since
  the previous boundary (1 h for Kyiv PT1H, spring: wall clock 02:00 -> 04:00), and after a fall-back it is 2 real hours
  (Kyiv PT1H: 03:00 first occurrence -> 04:00).
- **Deviation from the brief, one word**: the brief says "the next window covers 2h" after spring-forward; that is
  true on the wall clock and false in real time (02:00 EET -> 04:00 EEST is one hour). Tests assert real time.
- **Counts**: 23 boundaries (spring) and 24 (fall) for PT1H; the fall-back day has 25 real hours covered by 24 windows,
  one of them 2 h. If the owner wants 25 windows (one per real hour) see O2.
- **Explicit `window`** keeps `Window::endingAt` (real time) semantics, as today for sub-day windows.

## R8. Heartbeat and weekly line

- **Decision**: `stale_after` default = ceil(1.5 x period) (for a list: 1.5 x the longest gap; for single daily:
  1.5 x window as today). `skipped_done` / `skipped_locked` are no longer weekly counters when the schedule has more
  than one run a day (triggers per period are unbounded by design); the line adds `runs/day`.
- **Why**: weekly counters increment per trigger; a 15-minute cron on PT1H would otherwise print 3 "skipped" per run.

## R9. Rollup (end-of-day summary) is deferred

- **Decision**: not in v1. The key `notify.digest.rollup` is not accepted until built (a typo-safe `only()` error
  already names it).
- **Why**: it needs an aggregate of fingerprints/titles across the day's runs, which no state key holds today (run
  records hold counts only), so it needs a new persisted per-day document (privacy corpus extension, TTL, ordering with
  the 23:00 run) - a feature in itself. The owner's request is satisfied by quiet-silent hourly runs plus the weekly line.
- **If built (T024, behind `notify.digest.rollup = 'daily'`, default off)**: one `day:<svc>:<local day>` document
  appended per reported run with fingerprint, short allowlisted title, count; sent by the first due run after the day
  ends; TTL 2 days.

## R10. `--dry-run`, `--now`, no `--period`

- **Decision**: no new flag. `--now=<ISO>` already selects the instant; with `--dry-run` the gate returns the latest
  boundary `<= now` ignoring the due period (today's `latest()`), so any past boundary is previewable. A dry run reads
  `hw` (to show what a real run would read) and writes nothing; it is exempt from `max_runs_per_day`.

## R11. Fleet

- **Decision**: `FleetRunner` already calls each service's `Watcher::run($now)`; a not-due service returns
  `skipped_not_due`. No change; a test pins that a trigger runs only the due subset and that the fleet daily budget
  counts all of them. The fleet heartbeat `stale_after` default is 1.5 x the shortest service period.

## Owner decisions

- **O1** `capped` ends exit 0 with a daily notice (R4). Alternative: exit 1 so the host alerts.
- **O2** Fall-back day: 24 windows, one of 2 h (R7), versus 25 windows (second occurrence is a boundary). Recommendation: as written.
- **O3** `hw` = window end, not latest event time (R2). Recommendation: as written; changing it needs persisted event ids.
- **O4** Rollup deferred (R9).
- **O5** Failure-notice daily limit of 6 for multi-run schedules (FR-015). Alternative: leave 3 per window.
