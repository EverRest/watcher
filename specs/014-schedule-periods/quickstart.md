# Quickstart: validating Schedule Periods

## 1. Gates (no network)

```bash
composer install
composer test; echo $?
composer stan; echo $?
composer style; echo $?
```

Read each exit code on its own line, not the tail of the output. No commits, pushes or tags unless the owner asks.

## 2. Scenarios, mapped to tests

Fakes only: `FakeLogSource`, `InMemoryStateStore`, `FrozenClock`, `FakeAnalyzer`, `FakeNotifier`, harness
`tests/EndToEnd/Harness.php`.

| # | Scenario | Expected | Test | Spec |
|---|---|---|---|---|
| 1 | `every=PT1H`, trigger 09:05 and 09:35 | window `[08:00,09:00)` local; second is `skipped_done` | `tests/Unit/Schedule/RunGateEveryTest.php::testLatestBoundaryAndDone` | US1-1 |
| 2 | Invalid `every` (`PT90M`, `PT5H`, `P1D`), both `run_at` and `every`, neither | `InvalidConfig` with the contract message | `tests/Unit/Config/ScheduleConfigTest.php` | US1-4, FR-002 |
| 3 | Daily config `run_at='06:00'` | identical windows/keys/output to the existing suite | existing `RunGateTest`, `EndToEnd` suite unchanged | US1-5, SC-007 |
| 4 | `run_at=['06:00','14:00','22:00']`, hourly triggers for a day | 3 runs, windows `[22:00y,06:00)`,`[06:00,14:00)`,`[14:00,22:00)` | `tests/Unit/Schedule/RunGateListTest.php::testThreeTimesContiguous` | US2-1 |
| 5 | Entries < 1 h apart, duplicate, malformed | `InvalidConfig` naming `schedule.run_at` | `ScheduleConfigTest::testRunAtListRules` | US2-2 |
| 6 | 24 hourly triggers, one hour with a new group | 24 run records, contiguous, 1 digest, 23 quiet with heartbeat updates | `tests/EndToEnd/HourlyRunsTest.php::testFullDay` | US1-2/3, SC-001 |
| 7 | `window=PT3H`, `every=PT1H`, same events in 3 consecutive windows | each event counted once; a reported group not re-reported unless grown | `tests/EndToEnd/OverlapTest.php::testNoDoubleCounting`, `::testReportedNotRepeated` | US3-1, SC-004 |
| 8 | `window=PT30M`, `every=PT1H` | runs; `check` warns about the gap | `OverlapTest::testShorterWindowRuns`, `tests/Unit/Operate/DoctorScheduleTest.php` | US3-2, FR-016 |
| 9 | Failed window then next boundary, `window=PT2H` | failed span re-read once, `hw` not advanced by the failure | `OverlapTest::testFailedWindowIsReadAgain` | R2 |
| 10 | 5 h without triggers, `catch_up=1` | exactly one run; digest has the gap line `n=4` | `tests/EndToEnd/CatchUpTest.php::testOutageRunsOneWindowAndSaysSo` | US4-1, SC-003 |
| 11 | Same, `catch_up=3` | 3 windows oldest first, 3 run records, older span as gap | `CatchUpTest::testCatchUpThree` | US4-2 |
| 12 | Quiet run after an outage | gap sent as one notice, once per window | `CatchUpTest::testQuietRunStillReportsGap` | US4-3 |
| 13 | `max_runs_per_day=6`, 7th run | `capped`, 0 source reads, 0 model calls, one notice, exit 0 | `tests/EndToEnd/RunCapTest.php::testSeventhRunIsCapped` | US5-1, SC-005 |
| 14 | Default `run_budget_usd` with 24 runs | no run spends more than 2 x daily/24; later runs unaffected | `tests/EndToEnd/RunBudgetTest.php::testShareCapsOneRun` | US5-2, SC-006 |
| 15 | Single daily run, no new keys | no counter written, no budget line | `RunCapTest::testSingleDailyRunHasNoGuards` | US5-3 |
| 16 | `same_slot_yesterday`, 14:00 run | compared with yesterday 14:00 snapshot, per hour | `tests/Unit/Baseline/SameSlotBaselineTest.php::testYesterdaySlot` | US6-1 |
| 17 | `same_slot_last_week` | snapshot 7 days earlier | `SameSlotBaselineTest::testLastWeekSlot` | US6-2 |
| 18 | Slot snapshot missing | `previous` with `slot_missing`, digest says so | `SameSlotBaselineTest::testMissingSlotFallsBack` | US6-3 |
| 19 | `rolling`/`weekday` with `every=PT1H` | `InvalidConfig` with the contract message | `ScheduleConfigTest::testRollingNeedsWholeDays` | US6-4 |
| 20 | `ingestion_delay=PT2M` | window `[07:58,08:58)` for boundary 09:00; keys shifted; adjoin | `RunGateEveryTest::testIngestionDelayShiftsWindow` | US7-1 |
| 21 | Delay >= period | `InvalidConfig` naming `schedule.ingestion_delay` | `ScheduleConfigTest` | US7-2 |
| 22 | `stale_after` default | 5400 s for PT1H; list: 1.5 x longest gap | `tests/Unit/Operate/HeartbeatScheduleTest.php` | US8-1 |
| 23 | Weekly line, multi-run, 15-min triggers | no `skipped`, `runs/day` correct | `HeartbeatScheduleTest::testWeeklyLineCountsRuns` | US8-2 |
| 24 | **Europe/Kyiv 29 Mar 2026**, `PT1H` | 23 boundaries (03:00 absent), window ending 04:00 EEST is 1 real hour, all contiguous | `tests/Unit/Schedule/RunGateDstTest.php::testKyivSpringHourly` | FR-009, SC-002 |
| 25 | **Europe/Kyiv 25 Oct 2026**, `PT1H` | 24 boundaries, 03:00 first occurrence only, window ending 04:00 EET is 2 real hours | `RunGateDstTest::testKyivAutumnHourly` | SC-002 |
| 26 | Kyiv spring `PT2H` and `PT3H` | 12 windows (one 1 h) / 7 boundaries (window ending 06:00 is 5 real hours) | `RunGateDstTest::testKyivSpringTwoAndThreeHourly` | FR-009 |
| 27 | **America/New_York 8 Mar 2026**, `PT1H` | 23 boundaries (02:00 absent), contiguous | `RunGateDstTest::testNewYorkSpringHourly` | SC-002 |
| 28 | **America/New_York 1 Nov 2026**, `PT1H` | 24 boundaries, 01:00 first occurrence only, next window 2 real hours | `RunGateDstTest::testNewYorkAutumnHourly` | SC-002 |
| 29 | Same-slot on the day after a spring change | missing slot (absent wall time) falls back | `SameSlotBaselineTest::testDstGapSlot` | R5 |
| 30 | Fleet: three services (PT1H, PT6H, daily), one trigger at 12:05 | PT1H and PT6H and nothing else run; others `skipped_not_due` | `tests/EndToEnd/FleetSchedulesTest.php` | FR-018 |
| 31 | Privacy corpus with gap/slot/budget lines | no seeded value in any payload | `tests/Unit/PrivacyCorpusTest.php` | FR-020 |
| 32 | `--dry-run --now=<past ISO>` | previews that boundary, writes nothing (`hw`, `runs`), not capped | `tests/Unit/Cli/CliScheduleTest.php` | FR-017 |

## 3. Manual smoke (staging)

`WATCHER_EVERY=PT1H WATCHER_ENABLED=1`, hourly cron, `watcher run --dry-run --estimate` first; after two hours
`watcher status` shows `ok` and a window one hour long.
