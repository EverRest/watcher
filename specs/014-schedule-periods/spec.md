# Feature Specification: Schedule Periods and Several Runs per Day

**Feature Branch**: `014-schedule-periods`

**Created**: 2026-10-24

**Status**: Draft

**Input**: Owner request: configurable reading periods and several runs per day, including hourly.

## Overview

Today `schedule.run_at` is one `HH:MM`; a run is due once per local date, in the hour `[run_at, run_at + 1 h)`. `schedule.window` may be any ISO-8601 duration, so a 6-hour window exists, but nothing triggers it more than once a day: hourly operation is impossible, and `rolling` / `weekday` baselines only work for whole-day windows. An Operator who wants "tell me within the hour" has to run several services of the same name or give up.

This feature lets one service run several times per day on a fixed grid (`schedule.every`) or at listed times (`schedule.run_at` as a list), reads one contiguous period per run, never reports the same finding twice across overlapping windows, survives host outages without a storm, and keeps the cost of many small runs under explicit guards. **Every new key is optional and defaults to today's behaviour**; `enabled` (spec 012) is unchanged.

**Actors**: Operator (config and deploy), Host scheduler (cron / CronJob / Laravel scheduler), On-call reader (digests), Monitoring (reads `watcher status`).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Hourly (or N-hourly) runs (Priority: P1)

The Operator sets `schedule.every = PT1H`. The host triggers hourly. Each hour one window of the previous hour is read.

**Why this priority**: the owner's request; everything else supports it.

**Independent Test**: frozen clock over 24 hourly triggers with fakes: 24 run records, windows contiguous, none overlapping.

**Acceptance Scenarios**:

1. **Given** `every = PT1H`, `timezone = Europe/Kyiv`, **When** the host triggers at 09:05, **Then** the window is `[08:00, 09:00)` local, keyed by its end; a second trigger at 09:35 is `skipped_done`.
2. **Given** an hour with only known or unchanged groups, **Then** the run is `quiet`: no model call, no message, the heartbeat is updated.
3. **Given** an hour with a new or grown group, **Then** one digest is posted for that window only.
4. **Given** `every = PT3H` (or 2, 4, 6, 8, 12 h), **Then** boundaries are local midnight + k x period; `PT90M`, `PT5H`, `PT30M` and `P1D` are rejected at start.
5. **Given** neither `run_at` list nor `every`, i.e. today's config (`run_at = '06:00'`), **Then** behaviour, keys and output are byte-identical to before.

### User Story 2 - Several fixed times per day (Priority: P2)

The Operator lists times, for example `run_at = ['06:00', '14:00', '22:00']`. Each run reads from the previous listed time to this one (contiguous), unless `window` is set.

**Acceptance Scenarios**:

1. **Given** that list, **When** triggers arrive hourly, **Then** runs happen at 06, 14 and 22 (each due for the hour after its time, as today), 3 run records a day, windows `[22:00 yesterday, 06:00)`, `[06:00, 14:00)`, `[14:00, 22:00)`.
2. **Given** two entries less than one hour apart, duplicates, or a malformed entry, **Then** start fails naming `schedule.run_at`.
3. **Given** a single-element list `['06:00']`, **Then** it is the same as the string `'06:00'` (default window `PT24H`).

### User Story 3 - No double reporting across overlapping windows (Priority: P1)

The Operator keeps `window = PT3H` with `every = PT1H` (overlap) so a late-indexed line is more likely to be seen, or shortens `window` below the period.

**Acceptance Scenarios**:

1. **Given** overlapping windows, **When** consecutive runs both contain the same events, **Then** each event is counted in exactly one run, and a finding already reported is not reported again unless it grew.
2. **Given** `window` shorter than the period, **Then** the run works (events between windows are not read) and `watcher check` prints a warning naming the gap.
3. **Given** a first run after the feature is enabled, **Then** there is no high-water mark and the full window is read.

### User Story 4 - A host outage does not cause a storm (Priority: P1)

**Acceptance Scenarios**:

1. **Given** `every = PT1H`, `catch_up = 1` and no trigger for 5 hours, **When** the next trigger arrives, **Then** exactly one run happens (the latest boundary), the window is one period long, and the digest or a notice says which span was not read.
2. **Given** `catch_up = 3` and the same outage, **Then** the 3 latest missed windows run oldest first, each with its own run record, and the older span is reported as not read.
3. **Given** the outage left no trigger inside the due period of a boundary, **Then** nothing is lost silently: the span appears in the gap line of the next digest, or in a notice when the next run is quiet.

### User Story 5 - Cost guards for many small runs (Priority: P1)

**Acceptance Scenarios**:

1. **Given** `analysis.max_runs_per_day = 6`, **When** the 7th run of a local day starts, **Then** it ends `capped` before any read, once-per-day notice, exit 0.
2. **Given** `analysis.run_budget_usd` (default: daily budget / runs per day x 2 when more than one run a day), **When** one run's reserved spend would pass it, **Then** the remaining steps are skipped and the digest says so; later runs of the day still have their share.
3. **Given** a single daily run and no new keys, **Then** neither guard exists and behaviour is unchanged.

### User Story 6 - Baselines that make sense for an hour (Priority: P2)

**Acceptance Scenarios**:

1. **Given** `baseline.mode = same_slot_yesterday` and `every = PT1H`, **Then** the 14:00 run compares each group's count with the snapshot whose window ended at 14:00 local yesterday (per hour, normalised as in spec 004).
2. **Given** `same_slot_last_week`, **Then** the snapshot 7 local days earlier at the same wall-clock boundary.
3. **Given** the slot's snapshot is missing (first day, outage, DST gap), **Then** the run falls back to the previous window and the digest says why.
4. **Given** `rolling` or `weekday` with a sub-day period, **Then** start fails: `baseline.mode rolling/weekday needs whole-day periods; use same_slot_yesterday or same_slot_last_week`.

### User Story 7 - Late-indexed lines (Priority: P2)

**Acceptance Scenarios**:

1. **Given** `schedule.ingestion_delay = PT2M`, **Then** every window ends 2 minutes before its boundary, the run key is the shifted end, and consecutive windows still adjoin.
2. **Given** a delay not shorter than the period, **Then** start fails naming `schedule.ingestion_delay`.

### User Story 8 - Correct heartbeat, weekly line and DST (Priority: P2)

1. `heartbeat.stale_after` defaults to 1.5 x the period (1.5 x the longest gap for a `run_at` list); an explicit value wins.
2. The weekly line counts runs per outcome and runs per day; skipped duplicate triggers are not counted for multi-run schedules.
3. On DST days boundaries follow the local wall clock (see FR-009); the day has 23 / 24 or 24 / 24 windows for hourly (see quickstart), always contiguous.

## Requirements *(mandatory)*

- **FR-001**: `schedule.run_at` accepts a string `HH:MM` or a non-empty list of unique `HH:MM` at least 1 hour apart (also across midnight).
- **FR-002**: `schedule.every` (ISO-8601) accepts only PT1H, PT2H, PT3H, PT4H, PT6H, PT8H, PT12H; boundaries are local midnight + k x period in `schedule.timezone`. `run_at` and `every` are mutually exclusive; one is required.
- **FR-003**: Due detection: a trigger is due for the latest boundary <= now that is not done. For `every` the due period is the whole period; for `run_at` it stays `[time, time + 1 h)`.
- **FR-004**: The window of a run ends at `boundary - ingestion_delay` and by default starts at the previous boundary (contiguous). An explicit `schedule.window` makes the start `end - window`; the single-string `run_at` default stays `PT24H`.
- **FR-005**: Overlap must not double-count or double-report: the run reads `[max(window.start, hw), window.end)`, where `hw` is the end of the newest window finished as reported, quiet or baselined (key `hw:<service>`). Recorded in the snapshot as its `start`, so per-hour normalisation (spec 004) stays correct.
- **FR-006**: Idempotency stays keyed by window end: run record, lock, delivered marker, snapshot, triage checkpoint are unchanged.
- **FR-007**: `schedule.catch_up` (integer 1..24, default 1): at most this many missed boundaries are run per trigger, newest first chosen, oldest first executed. Only valid with `every`. Anything older is a gap, reported (FR-012).
- **FR-008**: `schedule.ingestion_delay` (ISO-8601, default `PT0S`) must be shorter than the period.
- **FR-009**: DST by local wall clock: a boundary whose wall time does not exist is not a boundary (the next window spans it; its real length is the real time since the previous boundary); an ambiguous wall time is a boundary at its first occurrence only.
- **FR-010**: `analysis.max_runs_per_day` (integer >= 1; default: none for one run a day, 2 x runs per day otherwise). Runs start counting after the lock is held; a retry of a failed window counts. Exceeding ends the run `capped`.
- **FR-011**: `analysis.run_budget_usd` (float > 0; default `daily_budget_usd / runs_per_day x 2` when more than one run a day, none otherwise). No billable step starts when run spend plus its reserved worst case would pass it. The daily cap still applies on top.
- **FR-012**: The digest (or, for a quiet run that would hide it, one notice) states a gap: events between `hw` and the window start were not analysed. Wording only from timestamps and integers.
- **FR-013**: `baseline.mode` adds `same_slot_yesterday` and `same_slot_last_week`; `rolling` / `weekday` are rejected for sub-day periods and for several runs a day.
- **FR-014**: `heartbeat.stale_after` default 1.5 x period; weekly summary per FR in User Story 8.
- **FR-015**: Failure notices stay at most 3 per window and gain at most 6 per local day per service when the schedule has more than one run a day.
- **FR-016**: `watcher check` warns (does not fail) when `window` is shorter than the period (gap) or longer (overlap), and when `max_runs_per_day` is below `runs_per_day`.
- **FR-017**: `--dry-run` previews the latest boundary <= `--now` regardless of the due period, writes nothing (including `hw`), and never counts toward `max_runs_per_day`.
- **FR-018**: Fleet: each service decides on its own schedule; a trigger may run a subset; fleet budget is per day (unchanged).
- **FR-019**: Environment variables for every new key (contracts/config.md).
- **FR-020**: No change to what leaves the service (Principle I): the new digest lines contain only timestamps, integers and fixed words; the privacy corpus is extended in the same change.
- **FR-021**: `notify.digest.rollup` (daily summary) is **not** in v1 (research R9). The key is not accepted until implemented.

## Success Criteria *(mandatory)*

- **SC-001**: 24 hourly triggers on an ordinary day produce 24 run records whose windows are contiguous and non-overlapping.
- **SC-002**: Hourly on 29 Mar 2026 (Europe/Kyiv) produces 23 windows, on 25 Oct 2026 24 windows (one 2 real hours long), and on 8 Mar / 1 Nov 2026 (America/New_York) 23 and 24; in each case contiguous with no gap.
- **SC-003**: A 5-hour outage followed by a trigger runs exactly one window (`catch_up = 1`) and the digest or notice names the unread span.
- **SC-004**: With windows of `PT3H` every `PT1H`, no event is counted in two runs and no fingerprint is reported twice unless it grew.
- **SC-005**: With `max_runs_per_day = N`, run N+1 of the day makes zero source reads and zero model calls.
- **SC-006**: With the default `run_budget_usd`, no single run spends more than twice the daily cap divided by runs per day.
- **SC-007**: With no new key set and `enabled = true`, the full existing suite passes unchanged and outputs/state equal the pre-feature ones.

## Edge Cases

- Host triggers every 15 minutes with `every = PT1H`: three of four triggers are `skipped_done`; fine.
- Host triggers once every 2 hours with `every = PT1H`: every second boundary is never triggered inside its period; the next trigger runs the latest boundary and reports the gap (host contract in operations.md: trigger at least every period / 2).
- A run fails (not recorded final): the next trigger inside the period retries the same window; if `window` is longer than the period, the next boundary's window still reaches back over the failed span (`hw` did not advance); with the default window the span becomes a reported gap (FR-012).
- The operator switches `daily` -> `PT1H`: first hourly run finds `snap:last`, compares with it (existing pointer rule, scaled per hour), and `hw` equals the last daily end.
- Operator changes `every`: boundaries move; old run records are keyed by old ends and are harmless.
- `every = PT12H` with `baseline.mode = previous`: unchanged semantics.
- Boundary at the nonexistent 02:30 for a listed `run_at`: resolves as today (first instant after the gap).
- Clock moves backwards (NTP): a boundary `> now` is never due; a done boundary stays done.
- Catch-up window and `source.event_cap`: each window has its own cap; large gaps are why `catch_up` defaults to 1.
- State store lost: `hw` and snapshots are gone; first run is a baseline of one period, as today.

## Assumptions

- The host triggers at least every period / 2 (any cadence from the period down to 1 minute works).
- `state.retention` (default 30 days) holds one snapshot per run: hourly = 24 per day per service; `same_slot_last_week` needs retention >= 8 days.
- The log backend indexes lines within `ingestion_delay`; otherwise late lines are missed and that is documented, not compensated by overlap (research R2).
- Prices and model routing are unchanged; tokens per run drop with window length, runs multiply (Principle V statement in plan.md).

## Owner decisions (2026-10-24)

O1 a run stopped by the daily cap ends `capped` with exit 0, sends one notice a day, and sets a heartbeat warning shown by `watcher status` (a budget guard working as designed is not a failure; it is never silent). O2 on a fall-back day 24 windows cover the 25 real hours (one window is 2 hours long), no boundary for the repeated hour. O3 `hw` is the end of the newest window finished reported, quiet or baselined, not the latest event time. O4 the daily rollup is deferred to its own spec. O5 the cap of 6 failure notices per day applies to multi-run schedules; daily schedules keep 3 per window. The dry-run period flag is not added (`--dry-run --now=<ISO>` covers it).
