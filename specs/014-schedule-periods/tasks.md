---
description: "Task list for Schedule Periods and Several Runs per Day (everrest/watcher)"
---

# Tasks: Schedule Periods and Several Runs per Day

**Input**: `specs/014-schedule-periods/` (spec.md, plan.md, research.md, data-model.md, contracts/, quickstart.md)

**Tests**: REQUIRED (Constitution III). Each implementation task names the test task it turns green; the test task is
written first and must fail first. No test touches the network.

**Format**: `[ID] [P?] [Story] Description`. Paths relative to the repository root. `[P]` = different files, no
unfinished dependency. Tasks marked **SEQ** touch `src/Watcher.php`, `src/Config/WatchConfig.php`,
`src/Config/Factory.php`, `src/Schedule/RunGate.php` or `src/Baseline/*` (other work edits `src/` in parallel): run them
one at a time in the stated order and re-read the file just before editing.

**Gate command**: `composer test; echo $?` then `composer stan; echo $?` then `composer style; echo $?`. Read exit codes.

**No commits, pushes or tags** unless the owner asks.

---

## Phase 0: Gate

- [x] T001 Run the three gates on the current tree and note the baseline (all green expected). No code.

## Phase 1: Configuration and boundary arithmetic (blocks everything)

**Status (2026-10-25):** Phase 1 shipped on `feat/014-schedule-periods` except DST matrix (T006–T007) and full `RunGateEvery` coverage; `schedule.every` due-window arithmetic needs follow-up (T007).

- [x] T002 [P] [US1/2/7] Write `tests/Unit/Config/ScheduleConfigTest.php` (red): `run_at` string/list rules and messages, `every` allowed set, mutual exclusion and "neither", `catch_up` range and "needs every", `ingestion_delay` < period, `max_runs_per_day`, `run_budget_usd`, derived `runs_per_day`, derived defaults (none for one run a day, `2 x` / `daily/runs x 2` otherwise), `stale_after` default, `rolling`/`weekday` rejected for sub-day, `same_slot_*` retention rule, env-list parsing of `WATCHER_RUN_AT`. Messages exactly as in contracts/config.md.
- [x] T003 [US1/2/7] **SEQ: `src/Config/WatchConfig.php`**. Implement the schedule/analysis/baseline/heartbeat parsing to turn T002 green. Keep every existing key and message; single-string `run_at` stays byte-identical (store as list, expose `window` unchanged). No other file.
- [x] T004 [P] [US1/2/7] Write `tests/Unit/Schedule/RunGateEveryTest.php` and `RunGateListTest.php` (red): partial (list + every-grid deferred). `pending($now)` newest first for `every` (whole period due), list (1 h due), `catch_up` limit, done-independent (pure), `ingestion_delay` shift, contiguous default start, explicit `window` = real `end - window`, `latest()` for dry run, legacy single-`run_at` cases copied from the existing `RunGateTest` still pass.
- [x] T005 [US1/2/7] **SEQ: `src/Schedule/RunGate.php`**. Constructor takes the parsed schedule (list, every, delay, catch-up, window); add `pending()`, keep `decide()` as the newest of `pending()` for old callers and tests, generalise `runAtOn` to arbitrary wall times and `endBack()` to any boundary (needed by T018). Turns T004 green.
- [ ] T006 [P] [US1] Write `tests/Unit/Schedule/RunGateDstTest.php` (red first where it differs): the matrix of quickstart #24-#28 (Kyiv 29 Mar and 25 Oct 2026, New York 8 Mar and 1 Nov 2026, PT1H) and #26 (Kyiv spring PT2H/PT3H): boundary count per local date, each window's real length, contiguity (`window[n].end == window[n+1].start`), no boundary twice.
- [ ] T007 [US1] **SEQ: `src/Schedule/RunGate.php`**. Fix whatever T006 shows red (drop nonexistent wall times for `every`, first occurrence for ambiguous). Same file as T005: after it.
- [x] T008 [US1] **SEQ: `src/Config/Factory.php`, `src/Watcher.php`**. Add `Factory::gate(WatchConfig)` and replace the two `new RunGate(...)` in `Watcher` (run and baseline resolver) with it. No behaviour change; existing suite green (SC-007 checkpoint).

**Checkpoint**: config parses, gate decides, DST matrix green, old suite unchanged.

## Phase 2: Hourly runs (US1, US2, US7) - MVP

- [ ] T009 [P] [US1] Write `tests/EndToEnd/HourlyRunsTest.php` (red): quickstart #6 (24 triggers, 24 run records, contiguous, one digest, quiet runs update the heartbeat), #1 repeated trigger `skipped_done`, #20 delay applied to source windows, list schedule #4 end to end.
- [x] T010 [US1] **SEQ: `src/Watcher.php`**. Replace `decide/latest` use with `pending()`: partial (one window per trigger). drop windows that are done (run record or delivered marker, before the lock), run the rest oldest first through the existing `locked()` path; dry run uses `latest()`. `catch_up` is still 1 here. Turns T009 green.

## Phase 3: No double reporting (US3)

- [ ] T011 [P] [US3] Write `tests/Unit/State/KeysTest.php` additions (`highWater`, `runsToday`, TTL kinds) and `tests/EndToEnd/OverlapTest.php` (red): quickstart #7, #8, #9 plus "snapshot stores the effective start" and "dry run does not write `hw`".
- [x] T012 [US3] **SEQ: `src/State/Keys.php` (small), `src/Watcher.php`**. Read `hw` before the source read, clamp the window to `[max(start, hw), end)`, write `hw` with the run record only for reported/quiet/baselined and only forward, store the effective start in the snapshot. Turns T011 green. Note: leave `Keys::*` signatures alone, add only new builders and kinds. (overlap e2e still open in T011.)

## Phase 4: Outages and catch-up (US4)

- [ ] T013 [P] [US4] Write `tests/EndToEnd/CatchUpTest.php` (red): quickstart #10, #11, #12; plus "outage longer than the stored `hw`'s TTL behaves as a baseline of one period".
- [ ] T014 [US4] **SEQ: `src/Watcher.php`; also `src/Notify/DigestRenderer.php`, `src/Notify/NoticeRenderer.php`, `DigestData`**. Loop over several pending windows (oldest first, each its own deadline; stop when the fleet time budget is spent), compute the gap from `hw` and the effective start, add the gap line / quiet-run notice and `gap`/`catchUp` in the run record and JSON summary. Wording from contracts/digest-wording.md.

## Phase 5: Cost guards (US5)

- [ ] T015 [P] [US5] Write `tests/EndToEnd/RunCapTest.php` and `RunBudgetTest.php` (red): quickstart #13, #14, #15; token-budget fixture case with a per-run share (Principle V); failure notices limited to 6 a day for multi-run schedules (FR-015).
- [ ] T016 [US5] **SEQ: `src/Watcher.php`; also `src/RunOutcome.php`, `src/Analyze/BudgetReservation.php`**. Atomic `increment` of `runs:` after the lock, `capped` outcome with once-a-day notice and heartbeat reason, run-budget ceiling in the reservation, digest line, per-day failure-notice counter, CLI/JSON/exit-code mapping for `capped`.

## Phase 6: Baselines for sub-day windows (US6)

- [ ] T017 [P] [US6] Write `tests/Unit/Baseline/SameSlotBaselineTest.php` (red): quickstart #16, #17, #18, #29; per-hour scaling when lengths differ; `windows` ignored.
- [ ] T018 [US6] **SEQ: `src/Baseline/BaselineResolver.php`, `src/Baseline/Comparison.php`** (needs T005's `endBack`). Add the two modes, `slot_missing` fallback, digest labels. `weekday`/`rolling` code paths untouched.

## Phase 7: Operability, fleet, privacy (US8)

- [ ] T019 [P] [US8] Write `tests/Unit/Operate/HeartbeatScheduleTest.php`, `DoctorScheduleTest.php` (check warnings, contracts/config.md), `tests/Unit/Cli/CliScheduleTest.php` (red): quickstart #8 (check part), #22, #23, #32.
- [ ] T020 [US8] **SEQ: `src/Config/Factory.php`**; also `src/Operate/Heartbeat.php`, `HeartbeatStatus.php`, `Doctor.php`. Heartbeat stale default from the period, weekly line `runs/day` without skip counters for multi-run schedules, `check` warnings, CLI help text for `--now` with `--dry-run`.
- [ ] T021 [P] [US8] Write `tests/EndToEnd/FleetSchedulesTest.php` (quickstart #30): confirm `src/Fleet/FleetRunner.php` needs no change; fix only if red. Fleet heartbeat default stale from the shortest period (`src/Fleet/*`, small).
- [ ] T022 [P] Extend `tests/Unit/PrivacyCorpusTest.php` (quickstart #31): seeded personal values in messages and service names around gap, slot, budget, cap lines; assert none in any payload. Must be red before T014/T016 wording exists, green after.

## Phase 8: Documentation and release

- [ ] T023 [P] Update `docs/configuration.md` (keys, env table, defaults), `docs/operations.md` (choosing a period; cost per hour estimate `runs/day x P(new group in an hour) x tokens per run x price` bounded by `min(daily, runs x run_budget)`, checked with `--dry-run --estimate`; noise control; host contract "trigger at least every period / 2; hourly cron works for PT1H; due period = the whole period"; outages and `catch_up`; overlap semantics R2; `ingestion_delay` with Loki, recommend `PT2M`; DST behaviour table), `README.md`, `config/watcher.php`, `config/watcher.example.php`, `CHANGELOG.md` (MINOR), `docs/roadmap.md` status to Implemented when done. Docs only; no src.
- [ ] T024 [D, deferred, own later spec/PR] `notify.digest.rollup = 'daily'` per research R9: tests first (`tests/EndToEnd/RollupTest.php`), `day:` document, privacy corpus, flag default off. Not part of the v1 gate.
- [ ] T025 Final: run the three gates, read each exit code; walk quickstart.md scenarios 1-32 and tick them; update the status line of this spec and `docs/roadmap.md`.

## Dependencies

- T002 -> T003; T004 -> T005 -> T007 (same file); T006 before T007; T008 after T003, T005.
- T009 -> T010 (after T008). T011 -> T012 (after T010). T013 -> T014 (after T012). T015 -> T016 (after T014; same file as T010/T012/T014: strictly sequential).
- T017 -> T018 (after T005). T019 -> T020. T022 before T014/T016 (red first), green after both.
- `[P]` test tasks may be written together early (T002, T004, T006, T009, T011, T013, T015, T017, T019, T021, T022): they only add new files. All **SEQ** implementation tasks run one at a time: T003, T005, T007, T008, T010, T012, T014, T016, T018, T020.

## MVP

T001-T010 give hourly runs with today's single-window semantics. T011-T016 make them safe (no double report, no storm,
cost bounded) and are required before recommending `PT1H` to anyone. T017-T022 polish; T024 is deferred.
