# Implementation Plan: Schedule Periods and Several Runs per Day

**Spec**: [spec.md](spec.md) | **Research**: [research.md](research.md) | **Data model**: [data-model.md](data-model.md)

## Summary

`RunGate` learns a boundary grid (`every`) and a list of times (`run_at`), returns the pending windows for a trigger (newest-first candidates, at most `catch_up`), and shifts window ends by `ingestion_delay`. `Watcher` clamps the read to `[max(start, hw), end)`, writes `hw:<service>` when a window finishes reported/quiet/baselined, counts runs per day, and applies a per-run budget share. `BaselineResolver` gains the two same-slot modes. Everything else (lock, run record, snapshot, triage checkpoint, delivered marker) stays keyed by window end and is untouched.

## Technical context

PHP ^8.1; no new dependency (Principle VIII). New state keys: `hw`, `runs` (data-model.md). New RunOutcome: `capped`. No new CLI flag. No new class except what `RunGate` needs: boundary arithmetic stays in `RunGate` (it already owns `runAtOn()` and DST resolution).

## Files touched

| File | Change | Note |
|---|---|---|
| `src/Config/WatchConfig.php` | parse/validate `run_at` list, `every`, `catch_up`, `ingestion_delay`, `analysis.max_runs_per_day`, `analysis.run_budget_usd`, new baseline modes, `stale_after` default | **shared with other work: SEQUENTIAL** |
| `src/Config/Factory.php` | `Factory::gate($config)` (one place builds `RunGate`); heartbeat default | **SEQUENTIAL** |
| `src/Schedule/RunGate.php` | boundary grid, `pending()`, delay, DST, generalised `endBack` | **SEQUENTIAL** |
| `src/Watcher.php` | window selection (loop over pending), `hw` clamp and write, run counter and `capped`, run budget, gap data | **SEQUENTIAL, largest change** |
| `src/Baseline/BaselineResolver.php`, `Comparison.php` | same-slot modes, fallback reason | **SEQUENTIAL** |
| `src/State/Keys.php` | `highWater()`, `runsToday()`, TTLs | small |
| `src/RunOutcome.php` | `Capped` | small |
| `src/Operate/Heartbeat.php`, `HeartbeatStatus.php`, `Doctor.php` | weekly runs per day, check warnings | small |
| `src/Notify/DigestRenderer.php`, `NoticeRenderer.php`, `DigestData` | gap line, run-budget line, cap notice | wording in contracts/digest-wording.md |
| `src/Fleet/FleetRunner.php` | none expected; test proves per-service decision | verify only |
| `config/watcher.php`, `config/watcher.example.php`, docs | new keys and variables | |

## Constitution Check

| Principle | Verdict |
|---|---|
| I Privacy fails closed | Pass. New digest lines carry timestamps, integers and fixed words only; no new field reaches the model. Privacy corpus extended (T022). |
| II Read-only | Pass. Only own state keys (`hw`, `runs`) and the notifier are written. |
| III Test-first | Pass. Every implementation task is preceded by its failing test task (tasks.md); DST matrix in `tests/Unit/Schedule`; no network. |
| IV Code decides | Pass. Selection of what is read (clamp by `hw`) and what is reported (`reported:` markers, growth) is deterministic code; the model sees nothing about schedules. |
| V Token and cost discipline | Pass with statement: more runs means more triage/analysis opportunities. Expected increase: zero tokens when nothing is new (quiet run = zero AI requests, unchanged); at most `max_runs_per_day` runs and `run_budget_usd` per run, daily cap unchanged. Token-budget fixtures extended with a per-run-share case (T015/T016). Shorter windows lower tokens per run. |
| VI Config over hard-coding | Pass. Period set, delays, caps are configuration with documented defaults; the allowed `every` set is a validated list, not a hard-coded schedule. |
| VII Failures loud | Pass. A gap is stated in the digest or a notice; `capped` posts one notice a day; per-day failure notice limit keeps loudness bounded. Every fallback (slot missing, catch-up) is visible. |
| VIII Simplicity | Pass with notes: no new class, no new CLI flag, no new store method (`increment` and `get/put` only), rollup deferred. |

No violations; complexity tracking empty.

## Compatibility and release

MINOR release (new optional keys; the `RunOutcome::Capped` value and the `run_at` list type are additive). `WATCHER_RUN_AT` gains comma-separated lists. Upgrade note: none required; existing configs behave identically (SC-007).

## Tests (order in tasks.md)

`tests/Unit/Config/ScheduleConfigTest`, `tests/Unit/Schedule/{RunGateEveryTest,RunGateListTest,RunGateDstTest}`, `tests/Unit/Baseline/SameSlotBaselineTest`, `tests/Unit/State/KeysTest`, `tests/Unit/Operate/HeartbeatScheduleTest`, `tests/EndToEnd/{HourlyRunsTest,OverlapTest,CatchUpTest,RunCapTest,RunBudgetTest,FleetSchedulesTest}`, privacy corpus.
