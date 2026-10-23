---

description: "Task list for Operability Tools (everrest/watcher)"
---

# Tasks: Operability Tools

**Input**: Design documents from `specs/002-operability-tools/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/cli.md, quickstart.md

**Tests**: REQUIRED (Constitution Principle III). Every test task comes before its implementation task and must fail
first. No test touches the network. Gates after each story: `composer test`, `composer stan`, `composer style`, each
exit 0 (read `$?`).

**Organization**: grouped by user story (spec.md). US1 and US2 are P1 and independent; US3, US4 are P2; US5 is P3.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1..US5 from spec.md
- **[W]**: edits `src/Watcher.php` - never in parallel with another [W] task
- **[A]**: edits `src/Cli/Application.php` - never in parallel with another [A] task
- Paths are relative to the repository root `/path/to/watcher`

---

## Phase 1: Foundational (shared by US1, US5)

- [x] T001 [P] Test then add keys: `Keys::heartbeat($service)` (`hb`), `Keys::trigger($service)` (`trig`), `Keys::week($service, $isoWeek, $counter)` (`hbweek`), `Keys::weekSent($service, $isoWeek)` (`hbsent`); `Keys::ttl()` returns 3 x retention for `hb`/`trig` and 14 days for `hbweek`/`hbsent`; every new kind has a non-zero TTL; components stay URL-encoded so a `:` in a service name cannot collide in `tests/Unit/State/KeysTest.php`, `src/State/Keys.php`
- [x] T002 [P] Test then add the optional `heartbeat` config section: `stale_after` (ISO-8601 duration > 0, default 1.5 x `schedule.window` in seconds), `weekly_summary` (bool, default false); unknown nested key rejected; error names the setting; add the key to the top-level whitelist; document in `tests/Unit/Config/WatchConfigTest.php`, `src/Config/WatchConfig.php`, `config/watcher.example.php`, `docs/configuration.md`

**Checkpoint**: Unit suite green.

---

## Phase 2: User Story 1 - Know that it ran (P1)

**Goal**: heartbeat per service, `watcher status`, optional weekly line.

**Independent Test**: quickstart 1-8.

### Tests first

- [x] T003 [P] [US1] Test `Heartbeat` and `NoticeRenderer::weekly()` (red): `recordRun()` writes `hb:` with all data-model fields and carries `lastOkAt` forward on `failed`; `recordTrigger()` writes only `trig:`; a skip never touches `hb:`; TTL = 3 x retention (assert via `InMemoryStateStore`); `status()` returns `ok`, `stale` (with both instants), `failed`, `unknown` (no record, expired, store throws - never `ok`); limit from `heartbeat.stale_after` or 1.5 x window; two services in one store are independent; every write is wrapped so a throwing store is logged to the host log (`SpyLogger`) and swallowed; weekly counters increment per ISO week/outcome/cost; `weeklyDue()` is false when no reported/quiet/baselined/failed run in the previous week; failure `reason` is cut at 200 chars and has configured secrets masked; weekly text has counts and total cost only in `tests/Unit/Heartbeat/HeartbeatTest.php`, `tests/Unit/Notify/NoticeRendererTest.php`
- [x] T004 [P] [US1] End-to-end (red until T006): quiet, reported, baselined, failed run then read `hb:`; `skipped_done`/`skipped_locked`/`skipped_not_due` leave `hb:` unchanged and update `trig:`; `FailingStateStore` on heartbeat writes leaves outcome and exit code unchanged (FR-005); weekly summary on: sent once by the first due run of the next week, not sent for a week with no due run, not sent when off; clock advanced past the limit gives `stale` in `tests/EndToEnd/HeartbeatTest.php`

### Implementation

- [x] T005 [US1] Implement `Heartbeat` (read/write/status/weekly counters, optional `LoggerInterface`) and `HeartbeatStatus` in `src/Heartbeat/Heartbeat.php`, `src/Heartbeat/HeartbeatStatus.php`; add `NoticeRenderer::weekly()` in `src/Notify/NoticeRenderer.php`
- [x] T006 [W] [US1] Wire into `Watcher::run()`: split the current body into a private method, record the heartbeat after the result is known (all four finishing outcomes plus the `catch` branches that return `Failed`), record `trig:` for skip outcomes (including the not-due return before the gate; wrap in try/catch, research R2), send the weekly line from the first due run of a new week through the existing `noticeOnce` pattern (research R10); nothing may change the returned `RunResult` in `src/Watcher.php`
- [x] T007 [A] [US1] Test then add `watcher status [--json]` (new optional `Closure $statusFactory` constructor argument: array -> callable(): HeartbeatStatus; exit codes 0/1/3/2; prints both compared instants; invalid config exit 2) in `tests/Unit/CliTest.php`, `src/Cli/Application.php`, `bin/watcher`
- [x] T008 [P] [US1] Test then add `StatusCommand` (`watcher:status {--json}`) and register it; `RunCommand` untouched here in `tests/Unit/Laravel/StatusCommandTest.php`, `src/Laravel/StatusCommand.php`, `src/Laravel/WatcherServiceProvider.php` [provider: not parallel with T014]

**Checkpoint**: quickstart 1-8 green; US1 shippable alone.

---

## Phase 3: User Story 2 - Verify the setup (P1)

**Goal**: `watcher check [--post-test]`, no secret in output, no spend by default.

**Independent Test**: quickstart 9-14.

### Tests first

- [x] T009 [P] [US2] Test `Doctor` (red) with fakes failing one dependency at a time (source throws `SourceUnavailable`, unauthorised, bad model id, missing price, `FailingStateStore`, invalid webhook): each failure is named with the fixed-vocabulary reason, the other checks still run, `ok()` false; invalid config gives `skip: config invalid` for the rest; all-green gives exit-0 report; no `Notifier::send` and no `Analyzer` call without `post_test`; with it exactly one labelled message per configured destination (`digest` and `notice`; when both webhooks are equal, still one per key); state check uses unique `check:{random}` keys with 60 s TTL and leaves real keys untouched on an empty store; two Doctors on one store do not interfere; report and JSON contain none of the seeded secrets, webhook URL, or log lines; a provider without `ModelCatalog` yields `skip` in `tests/Unit/Check/DoctorTest.php`
- [x] T010 [P] [US2] Test `AnthropicModelCatalog` with a fake Guzzle handler (red): package scheme (Bearer) 200 -> pass; Bearer 401 + `x-api-key` 200 -> the explicit "analyzer needs a code change" result; both 401 -> fail with both statuses; timeout -> `timeout` (5 s option asserted); model ids parsed from the listing and matched to the three configured ids; response body and key never appear in any reason string; request goes to the models endpoint with GET only (extend the read-only architecture test) in `tests/Unit/Analyze/AnthropicModelCatalogTest.php`, `tests/Unit/ArchitectureTest.php`

### Implementation

- [x] T011 [US2] Implement the optional `ModelCatalog` interface (`probe(): array{scheme results}`, `modelIds(): list<string>`), `AnthropicModelCatalog`, and `Factory::modelCatalog(WatchConfig, Client): ?ModelCatalog` (null for other providers) in `src/Analyze/ModelCatalog.php`, `src/Analyze/AnthropicModelCatalog.php`, `src/Config/Factory.php` [Factory: not parallel with T025]
- [x] T012 [US2] Implement `Doctor`, `CheckReport`, `CheckResult`; build collaborators with the existing `Factory` (`source`, `state`, `analyzer` is NOT constructed: no model call), 5-minute `Window` for the source query, `instanceof ModelCatalog` for provider checks, `Notifier` only for `--post-test` in `src/Check/Doctor.php`, `src/Check/CheckReport.php`, `src/Check/CheckResult.php`
- [x] T013 [A] [US2] Test then add `watcher check [--post-test] [--json]` (new optional `Closure $checkFactory`; human report on stdout, or on stderr under `--json`; exit 0/1/2 per contract) in `tests/Unit/CliTest.php`, `src/Cli/Application.php`, `bin/watcher`
- [x] T014 [P] [US2] Test then add `CheckCommand` (`watcher:check {--post-test} {--json}`) and register it in `tests/Unit/Laravel/CheckCommandTest.php`, `src/Laravel/CheckCommand.php`, `src/Laravel/WatcherServiceProvider.php` [provider: not parallel with T008]

**Checkpoint**: quickstart 9-14 green; then update `docs/smoke-test.md` section 2 to point at `watcher check` (docs only, same task as T014's commit if the owner asks for a commit).

---

## Phase 4: User Story 3 - Dry run (P2)

**Goal**: full pipeline, output to stdout, zero state change, `--estimate` makes no model call.

**Independent Test**: quickstart 15-19.

### Tests first

- [x] T015 [P] [US3] Test (red) `DryRunStateStore`: `get` reads through but returns null for `run:` keys; `put`/`release` write nothing; `acquire` returns true without writing; `increment` writes nothing and returns `$by`; wrapped `FileStateStore` directory is byte-for-byte identical before and after a sequence of calls; no `Purgeable`. `CapturingNotifier`: prints the `== message to <digest|notice> ==` blocks and the DRY RUN banner to the injected stream, never calls the real notifier, never prints a webhook. `EstimatingAnalyzer`: zero network, records groups and `Budget::estimateTokens` per step, returns `interesting` for all and zero `Usage`, `analyze()` returns an empty zero-usage result in `tests/Unit/State/DryRunStateStoreTest.php`, `tests/Unit/Notify/CapturingNotifierTest.php`, `tests/Unit/Analyze/EstimatingAnalyzerTest.php`
- [x] T016 [P] [US3] End-to-end (red until T018): same window real vs dry-run on identical fakes gives identical digest text; after a dry run the state store and `FakeNotifier` are untouched for reported, quiet, baselined and failed outcomes (SC-003); window already reported by a real run still executes and prints the label; dry-run model calls are real (`EchoAnalyzer` called), `spend:`/`alert:` keys unchanged and no budget alert fired; `--estimate` makes zero analyzer calls and prints per-step groups/tokens/cost; an interrupted dry run (analyzer throws) leaves nothing to clean; `skipped_not_due` carries the `--now` hint in `tests/EndToEnd/DryRunTest.php`

### Implementation

- [x] T017 [US3] Implement `DryRunStateStore`, `CapturingNotifier`, `EstimatingAnalyzer`, and `RunOptions` (dryRun, estimate, out stream) in `src/State/DryRunStateStore.php`, `src/Notify/CapturingNotifier.php`, `src/Analyze/EstimatingAnalyzer.php`, `src/RunOptions.php`
- [x] T018 [W] [US3] Add `?RunOptions` to `Watcher::fromConfig()` and build the wrapped state/notifier/analyzer there (the `Watcher` constructor and pipeline code stay as they are); the captured output includes the "window already reported" label when the real `run:` record existed (read it through the unwrapped store before wrapping) in `src/Watcher.php`
- [x] T019 [A] [US3] Test then add `--dry-run` and `--estimate` (usage error exit 2 for `--estimate` alone) to `watcher run`; change the run factory signature to `Closure(array, RunOptions): callable(?DateTimeImmutable): RunResult` and adapt `bin/watcher` and the existing `CliTest` call sites; add `{--dry-run} {--estimate}` to `RunCommand` and its tests in `tests/Unit/CliTest.php`, `src/Cli/Application.php`, `bin/watcher`, `tests/Unit/Laravel/RunCommandTest.php`, `src/Laravel/RunCommand.php`, `src/Laravel/WatcherServiceProvider.php` [A + RunCommand + provider: not parallel with T008, T013, T014, T023]

**Checkpoint**: quickstart 15-19 green.

---

## Phase 5: User Story 4 - Machine-readable summary (P2)

**Goal**: exactly one JSON line on stdout for every outcome.

**Independent Test**: quickstart 20-21.

### Tests first

- [x] T020 [P] [US4] Extend the privacy corpus first (Principle I): assert the JSON line, the heartbeat record and the check report contain none of the seeded values from `tests/Fixtures/privacy/seeded-values.php` for a corpus run that reports. Test (red) `RunStats`/`RunResult` (fourth optional argument, `summary()` unchanged) and `JsonSummary`: all field names from data-model.md present for reported, quiet, baselined, failed, skipped and invalid-config (nulls for unknown values), one line without a newline inside, `reason` sanitised like `summary()`, `dry_run` flag, `version` falls back to `unknown` in `tests/EndToEnd/PrivacyTest.php`, `tests/Unit/RunResultTest.php`, `tests/Unit/Cli/JsonSummaryTest.php`

### Implementation

- [x] T021 [US4] Implement `RunStats`, extend `RunResult` with the optional stats, implement `JsonSummary` (package version via `Composer\InstalledVersions`) in `src/RunStats.php`, `src/RunResult.php`, `src/Cli/JsonSummary.php`
- [x] T022 [W] [US4] Fill `RunStats` at every finishing path of `locked()` (events read, groups, candidates, findings, calls per step from `RouterResult`, today's total from `Spend`, release from `DeployInfo`, window, duration) and for the failure/skip results built in `run()`; counts only, never group content in `src/Watcher.php`
- [x] T023 [A] [US4] Test then add `--json` to `run`, `check`, `status`: stdout is exactly one line, human text and (under `--dry-run`) the captured digest go to stderr; the line is printed also for `failed` and for invalid config (service read from the raw array only if it is a string); exit codes unchanged; add `{--json}` to `RunCommand` in `tests/Unit/CliTest.php`, `src/Cli/Application.php`, `tests/Unit/Laravel/RunCommandTest.php`, `src/Laravel/RunCommand.php` [A + RunCommand: not parallel with T007, T013, T019]

**Checkpoint**: quickstart 20-21 green.

---

## Phase 6: User Story 5 - File state purge (P3)

**Goal**: the file store cleans itself; lock sidecars removed safely; a failed write keeps the old record.

**Independent Test**: quickstart 22-24.

- [x] T024 [P] [US5] Test (red) purge on `FileStateStore`: expired `.json` removed, live kept; `.lock` sidecars and `.tmp` leftovers older than the lock lifetime removed, younger kept; files not matching the watcher name patterns left alone; at most once per day (second call within 24 h does nothing, `.purge` marker mtime); bounded by a time budget and file count (inject a clock/limit, 5,001 expired files -> 5,000 removed); a held sidecar (`flock` taken by the test) is not removed; a waiter whose sidecar was removed re-opens and gets mutual exclusion (inode revalidation); a failed write (simulated short write) keeps the previous record and throws; `Purgeable` is not implemented by the other stores; `Watcher` run calls `purge()` after the gate and a throwing purge does not change the result in `tests/Unit/State/FileStateStorePurgeTest.php`, `tests/Contract/FileStateStoreTest.php`
- [x] T025 [W] [US5] Implement `Purgeable` and `FileStateStore::purge()` (constructor takes an optional lock lifetime, default 900, passed by `Factory::state` from `schedule.lock_ttl`), add the post-`flock` inode check to `locked()`, and call `purge()` from `Watcher::run()` after the gate and before the lock (try/catch, host-log warning) in `src/State/Purgeable.php`, `src/State/FileStateStore.php`, `src/Config/Factory.php`, `src/Watcher.php` [W + Factory: after T022 and T011]

**Checkpoint**: quickstart 22-24 green. Then run the full quickstart, `composer test`, `composer stan`, `composer style`; add README and `docs/operations.md` sections (status probe, check, dry-run, `--json`) and a `CHANGELOG.md` entry.

---

## Dependencies & Execution Order

```text
T001 ─┬─► T003 ─► T005 ─► T006[W] ─► T007[A] ─► T008
T002 ─┘   T004 ──────────┘

US2 (independent of US1 except sharing Application.php / provider / bin):
T009, T010 ─► T011 ─► T012 ─► T013[A] ─► T014

US3: T015, T016 ─► T017 ─► T018[W] ─► T019[A]
US4: T020 ─► T021 ─► T022[W] ─► T023[A]
US5: T024 ─► T025[W]
```

**Files that force sequential order (NOT parallel with each other)**

| Hot file | Tasks (run in this order) |
|---|---|
| `src/Watcher.php` | T006 -> T018 -> T022 -> T025 |
| `src/Cli/Application.php` | T007 -> T013 -> T019 -> T023 |
| `src/Config/Factory.php` | T011 -> T025 |
| `src/Laravel/WatcherServiceProvider.php` | T008 -> T014 -> T019 |
| `src/Laravel/RunCommand.php`, `tests/Unit/Laravel/RunCommandTest.php` | T019 -> T023 |
| `bin/watcher` | T007 -> T013 -> T019 |
| `tests/Unit/CliTest.php` | T007 -> T013 -> T019 -> T023 |
| `tests/EndToEnd/PrivacyTest.php` | T020 only (others add their own E2E files) |

Stories may be developed by different people on different branches, but their [W] and [A] tasks merge in the order
above (US1, US2, US3, US4, US5). Within one story: tests -> value objects/services -> wiring.

## Parallel Examples

- **Phase 1**: T001 and T002 together.
- **US1**: T003 and T004 together (T004 stays red until T006); T008 can run beside T006/T007 only if it does not edit the provider at the same time as T014.
- **US2**: T009 and T010 together; T011 and T012 are sequential (T012 uses the interface).
- **US3**: T015 and T016 together.
- **Across stories**: after T002, the test-only tasks T003, T004, T009, T010, T015, T016, T020, T024 are all parallel (distinct new files; T020 alone edits `PrivacyTest.php`). Non-hot implementation tasks T005, T011-T012, T017, T021 are parallel to each other once their tests are red.

## Edge cases from the spec and where they are covered

| Edge case | Task |
|---|---|
| check before any state exists; scratch key, never real data | T009, T012 |
| two checks at once | T009 |
| heartbeat and main store are the same; unreadable -> `unknown`, not `ok` | T003, T005, T007 |
| dry run interrupted | T016 |
| several services sharing one store | T001, T003 |
| wrong host clock: status states the compared times | T003, T007 |
| skip must not overwrite last run | T003, T004, T006 |
| heartbeat write failure does not change outcome/exit | T003, T004, T006 |
| no weekly message when the week had no due run | T003, T004 |
| provider rejects the package auth scheme, alternative works | T010, T011 |
| JSON line also for failure and invalid config | T020, T023 |
| failed write keeps previous record; foreign files untouched; sidecar race | T024, T025 |

## Implementation Strategy

1. T001-T002, then US1 (cheapest signal, answers "is it alive"), stop and validate quickstart 1-8.
2. US2 (`check`) removes the manual smoke steps; validate quickstart 9-14.
3. US3 dry run, US4 JSON, US5 purge, in that order (matches the hot-file merge order).
4. Docs and changelog last; commit only when the owner asks.
