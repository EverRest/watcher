# Contract: configuration added by spec 014

Flag pattern of spec 012: master `enabled` is unchanged (default off). Every key below is optional; unset means today's
behaviour exactly. Unknown keys still fail via the section's `only()` list. Messages name the key (Principle VI).

| Key | Env var | Default | Validation message |
|---|---|---|---|
| `schedule.run_at` | `WATCHER_RUN_AT` (comma list: `06:00,14:00`) | required unless `every` | `schedule.run_at must be HH:MM or a list of HH:MM`; `schedule.run_at entries must be unique`; `schedule.run_at entries must be at least 1 hour apart` |
| `schedule.every` | `WATCHER_EVERY` | null | `schedule.every must be one of PT1H, PT2H, PT3H, PT4H, PT6H, PT8H, PT12H`; `schedule.run_at and schedule.every are mutually exclusive`; neither set: `schedule.run_at (or schedule.every) is required` |
| `schedule.window` (`WATCHER_WINDOW` not added; unchanged) | - | `PT24H` for a single `run_at`; the gap to the previous boundary otherwise | unchanged messages. A window shorter/longer than the period is **valid**; `check` warns (below) |
| `schedule.catch_up` | `WATCHER_CATCH_UP` | `1` | `schedule.catch_up must be an integer from 1 to 24`; `schedule.catch_up above 1 needs schedule.every` |
| `schedule.ingestion_delay` | `WATCHER_INGESTION_DELAY` | `PT0S` | `schedule.ingestion_delay must be an ISO-8601 duration`; `schedule.ingestion_delay must be shorter than the period` |
| `analysis.max_runs_per_day` | `WATCHER_MAX_RUNS_PER_DAY` | none for one run a day; `2 x runs_per_day` otherwise | `analysis.max_runs_per_day must be an integer >= 1` |
| `analysis.run_budget_usd` | `WATCHER_RUN_BUDGET_USD` | none for one run a day; `daily_budget_usd / runs_per_day x 2` otherwise | `analysis.run_budget_usd must be greater than zero` |
| `baseline.mode` | `WATCHER_BASELINE_MODE` | `previous` | `baseline.mode must be one of previous, rolling, weekday, same_slot_yesterday, same_slot_last_week`; `baseline.mode rolling/weekday needs whole-day periods; use same_slot_yesterday or same_slot_last_week with schedule.every`; `state.retention (N s) is shorter than baseline.mode same_slot_last_week needs (691200 s)` |
| `heartbeat.stale_after` | existing | `ceil(1.5 x period)`; list: 1.5 x the longest gap; single daily unchanged | unchanged |
| `notify.digest.rollup` | - | not accepted in v1 (research R9) | unknown-key error, as today |

## Allowed values and rules

- `every` set and `window` unset: windows are contiguous, `window` is not written back into the config array.
- `ingestion_delay < min(period, window)`; for a `run_at` list the shortest gap.
- `runs_per_day` is derived (nominal: `86400 / period`, or the list length) and exposed read-only to `Doctor`.
- With `enabled = false` the keys are still validated (spec 012 US1-3).
- Shipped `config/watcher.php`: sets `run_at` only when `WATCHER_EVERY` is empty and `every` only when it is set, so
  both are never present together; `config/watcher.example.php` documents both forms.

## `watcher check` warnings (do not fail the check, shown as `warn`)

- `schedule.window (PT30M) is shorter than the period (PT1H): 30 minutes of every hour are not read`
- `schedule.window (PT3H) is longer than the period (PT1H): windows overlap; each event is counted once (see operations.md)`
- `analysis.max_runs_per_day (N) is below the nominal runs per day (M): the last runs of the day will be capped`
- `schedule.catch_up is 1: an outage longer than one period leaves a gap, reported in the next digest`
