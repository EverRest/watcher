---

description: "Task list for Fleet Mode and Cross-Service Correlation (everrest/watcher)"
---

# Tasks: Fleet Mode and Cross-Service Correlation

**Input**: `specs/003-fleet-and-correlation/` (plan, research, data-model, contracts, quickstart)

**Tests**: REQUIRED (Constitution III). Every test task comes before its implementation task and must fail first.
No network. Gates after each story: `composer test`, `composer stan`, `composer style`, each exit 0 (read `$?`).

**Organization**: by user story. US1, US2 are P1; US3, US4 are P2. Paths relative to the repository root.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallel (different files, no unfinished dependency)
- **[W]**: edits `src/Watcher.php` - never parallel with another [W]
- **[A]**: edits `src/Cli/Application.php` (and `bin/watcher`, `tests/Unit/CliTest.php`) - never parallel with another [A]
- **[R]**: edits `src/Fleet/FleetRunner.php` - sequential with the other [R] tasks
- `src/Config/Factory.php` and `src/Config/WatchConfig.php`: **no task in this feature edits them** (plan, Project
  Structure). If a task finds it must, stop and ask the owner.

---

## Phase 1: Foundational (blocks all stories)

- [x] T001 [P] Test `FleetConfig` (red): required `fleet` name; `defaults` merged under each entry; `priority`/`provides` stripped and defaulted (`0`, `[service]`); every error before any external call with entry index, service and key path (`services[1] (bff-a): selection.growth_factor must be greater than 1`); duplicate `service` names both entries named; `/` in a service name rejected; differing `state` or `analysis.api_key` after merge rejected; unknown fleet key rejected; `budget` and `digest`, `time_budget`, `correlation.tolerance` parsed and validated like `schedule.window`; directory form (`*.php` sorted, `_fleet.php` required); half-valid fleet runs nothing; error text never contains a configured secret; fleet of one valid in `tests/Unit/Fleet/FleetConfigTest.php`
- [x] T002 Implement `FleetConfig` (`fromArray`, `fromPath` for file or directory) and `ServiceEntry` over the unchanged `WatchConfig::fromArray` in `src/Fleet/FleetConfig.php`, `src/Fleet/ServiceEntry.php` (after T001)
- [x] T003 [P] Test `FleetOrder` (red): priority desc then name asc; dependencies in the fleet (by name or `provides`) before their consumers; dependency not in the fleet ignored; cycle keeps the sorted order and terminates; order is deterministic for shuffled input in `tests/Unit/Fleet/FleetOrderTest.php`
- [x] T004 [P] Implement `FleetOrder::of(list<ServiceEntry>): list<ServiceEntry>` in `src/Fleet/FleetOrder.php` (after T003; parallel with T002 once `ServiceEntry` exists - if T002 is not done, define against `ServiceEntry`'s constructor in the test)

**Checkpoint**: `tests/Unit/Fleet` green.

---

## Phase 2: User Story 1 - Watch several services from one place (P1)

**Goal**: one trigger runs every service, isolated, with the worst exit code. **Needs no `Watcher.php` change.**

**Independent Test**: quickstart 1-10.

- [x] T005 [P] [US1] Test `FleetResult` (red): exit code `2`/`1`/`0` per data-model (failed, `skipped_time`, failed fleet post); one-line JSON per contracts/cli.md with all fields, no group content, reason sanitised like `RunResult::summary()`; `skipped_not_due`/`done`/`locked` are exit 0 in `tests/Unit/Fleet/FleetResultTest.php`
- [x] T006 [P] [US1] Create `FleetHarness` (three fake services over one `InMemoryStateStore`, `FakeNotifier` per destination, per-service `RoutedLogSource`, `FrozenClock`, counting `FakeAnalyzer`) and the end-to-end suite (red until T007): quickstart 1-5, 8, 9, 14 in `tests/EndToEnd/FleetHarness.php`, `tests/EndToEnd/FleetTest.php`
- [x] T007 [US1][R] Implement `FleetResult` and `FleetRunner` (`__construct(FleetConfig, Closure $watcherFor, ClockInterface, ?LoggerInterface)`, `run(?DateTimeImmutable $now, RunOptions)`; static `fromConfig(array|string, ...)` builds the shared `StateStore` once via `Factory::state`, the shared `Analyzer` once via `Factory::analyzer`, and per entry `Factory::source` + `Factory::notifier`; try/catch `Throwable` per service -> `internal error (Class)`; `time_budget` check before each service; fleet `Heartbeat` with id `fleet/{name}`; fleet `Heartbeat` write failure never changes the result) in `src/Fleet/FleetResult.php`, `src/Fleet/FleetRunner.php` (after T002, T004, T005, T006)
- [x] T008 [US1][A] Test then add `fleet:run` (`--config=<file|dir>`, `--now`, `--only`, `--dry-run [--estimate]`, `--json`; new optional `Closure $fleetFactory` constructor argument returning `FleetResult`; exit 0/1/2; one JSON line also for an invalid fleet) in `tests/Unit/CliTest.php`, `src/Cli/Application.php`, `bin/watcher` (after T007)
- [x] T009 [P] [US1] Test then add `FleetCommand` (`watcher:fleet`, same options, reads `watcher.fleet`) and register it in `tests/Unit/Laravel/FleetCommandTest.php`, `src/Laravel/FleetCommand.php`, `src/Laravel/WatcherServiceProvider.php` (after T007; independent of T008 - different files)

**Checkpoint**: quickstart 1-10 green; US1 shippable alone (no budget sharing, no correlation yet: each service still has only its own cap).

---

## Phase 3: User Story 2 - One budget and one destination policy (P1)

**Goal**: fleet cap, fleet thresholds, per-service or combined digest.

**Independent Test**: quickstart 11-17.

### Tests first

- [x] T010 [P] [US2] Test `FleetBudget` and `FleetSeam` budget part (red): booking adds to the service and the fleet counters; `exhaustedCap()` returns `fleet`, `service` or null (cap checked on the fleet counters of `fleet/{name}`); thresholds 25/50/75/90 once per day for the fleet total and independent of the per-service ones; dry run (store wrapped) books nothing; and `NoticeRenderer::budgetExhausted($service, $env, ?$cap)` names the cap and the service in `tests/Unit/Fleet/FleetBudgetTest.php`, `tests/Unit/Notify/NoticeRendererTest.php`
- [x] T011 [P] [US2] End-to-end (red until T013-T015): quickstart 11, 12, 13, 15 in `tests/EndToEnd/FleetBudgetTest.php`
- [x] T012 [P] [US2] Test `CollectingNotifier` and `CombinedDigest` (red): notices pass through immediately, digest-destination messages are held; one message with a heading per service, quiet services collapsed to one line, nothing reported -> nothing posted; 20 services with findings stay within the message limit (collapse, never split); service names in every heading in `tests/Unit/Fleet/CombinedDigestTest.php`

### Implementation

- [x] T013 [US2] Implement `FleetBudget` (fleet `Spend` + `BudgetAlerts` with id `fleet/{name}`, tz from fleet config), `FleetSeam` budget methods (`book(Usage, float)`, `exhaustedCap(): ?string`) and `NoticeRenderer::budgetExhausted(..., ?string $cap = null)` in `src/Fleet/FleetBudget.php`, `src/Fleet/FleetSeam.php`, `src/Notify/NoticeRenderer.php` (after T010)
- [x] T014 [US2][W] Seam 1 in `Watcher`: add the optional last constructor argument `?FleetSeam $fleet = null`; `$triageOnly` also true when `$this->fleet?->exhaustedCap() !== null`; `metered()` also calls `$this->fleet?->book($usage, $cost)`; the budget-exhausted notice passes the cap name. No other change; `fromConfig` untouched in `src/Watcher.php` (after T013)
- [x] T015 [US2][R] Wire in `FleetRunner`: build `FleetSeam` once per run and pass it to each `Watcher`; fleet threshold alert after each service (before/after fleet totals) sent to the fleet notice destination; `digest: combined` wraps each service's notifier in `CollectingNotifier` and posts the `CombinedDigest` at the end; implement `CollectingNotifier`, `CombinedDigest`; a failed combined post sets the `FleetResult` post flag and exit 1 in `src/Fleet/CollectingNotifier.php`, `src/Fleet/CombinedDigest.php`, `src/Fleet/FleetRunner.php` (after T007, T012, T014)

**Checkpoint**: quickstart 11-17 green.

---

## Phase 4: User Story 3 - A finding that names its upstream cause (P2)

**Goal**: one additive correlated finding plus an annotation on each linked consumer finding; nothing lost if the post fails; zero model calls; privacy intact.

**Independent Test**: quickstart 18-27.

### Tests first

- [x] T016 [P] [US3] Extend the privacy corpus first (Principle I): seeded values from `tests/Fixtures/privacy/seeded-values.php` placed in every fake service's logs; assert the correlated finding text/array, the combined digest, fleet state keys/records and the host log contain none; `Correlator` signature accepts only `RedactedGroup` (reflection assertion) in `tests/EndToEnd/FleetPrivacyTest.php`
- [x] T017 [P] [US3] Test `Correlator` table (red): the four rule inputs (reference `<URL:d>`/`<HOST:d>`, `provides`, origin `new`/`grown` at or above `min_level`, window overlap with `tolerance`); no reference -> none; origin outside the fleet -> none; unchanged/non-overlapping origin -> none; many consumers -> one finding listing all with group and event counts; cycle -> earlier `first_seen` is the origin, equal -> sorted-first service; `correlation.enabled=false` -> none; deterministic output order in `tests/Unit/Fleet/CorrelatorTest.php`, `tests/Unit/Fleet/CorrelationRendererTest.php`
- [x] T018 [P] [US3] End-to-end (red until T019-T021): quickstart 18-24, 26, 27 in `tests/EndToEnd/FleetCorrelationTest.php`, including `testCorrelatedPostFailureLosesNothing` (correlated post fails -> consumer digest still contains its finding, exit 1)

### Implementation

- [x] T019 [US3] Implement `Correlator`, `CorrelationLink`, `CorrelatedFinding`, `CorrelationRenderer` (template wording, hypothesis label, character cap like `DigestRenderer`) in `src/Fleet/Correlator.php`, `src/Fleet/CorrelationLink.php`, `src/Fleet/CorrelatedFinding.php`, `src/Fleet/CorrelationRenderer.php` (after T017; parallel with T020)
- [x] T020 [US3][W] Seam 2 in `Watcher::locked()`: right after the existing redaction loop (not moved) call `$annotations = $this->fleet?->reduced($service, array_values($redactedBy)) ?? []` (fingerprint => annotation text); pass it as a new optional last argument of `DigestData`; `DigestRenderer` appends the annotation line to the matching finding (red test first in `tests/Unit/Notify/DigestRendererTest.php`). Groups, candidates and analysis are never changed; no behaviour when `$this->fleet === null` (existing suite stays green unchanged) in `src/Watcher.php`, `src/Notify/DigestData.php`, `src/Notify/DigestRenderer.php`, `tests/Unit/Notify/DigestRendererTest.php` (after T014; parallel with T019)
- [x] T021 [US3][R] Implement `FleetSeam::reduced()` (registry per service in fleet order, lookups against already-registered origins, returns annotation text per explained consumer fingerprint, records links) and wire `FleetRunner`: after the last service run `Correlator` and post one additive `CorrelatedFinding` per origin group to the fleet `notify.digest`; no state is written for it and nothing is removed from any digest; a failed post -> host log, `correlated_posted=false`, exit 1; dry run prints instead in `src/Fleet/FleetSeam.php`, `src/Fleet/FleetRunner.php` (after T015, T019, T020; T016 must be green)

**Checkpoint**: quickstart 18-27 green.

---

## Phase 5: User Story 4 - Services that cannot host the package (P2)

**Goal**: watch services with no CLI or scheduler; fleet heartbeat/status; documented hosting.

**Independent Test**: quickstart 28-30.

- [x] T022 [P] [US4] End-to-end (red/green as T021 allows): services defined only by `source` + entry (no scheduler, no CLI), `fleet:status`-equivalent via `FleetRunner` heartbeat and per-service heartbeat, release resolved per service from its own `deploy` settings: quickstart 28-30 in `tests/EndToEnd/FleetHostingTest.php`
- [x] T023 [US4][A] Test then add `fleet:status` (fleet record plus each service via `Heartbeat::status`, exit worst, `--json`) and `fleet:check` (per-service `Doctor`, one shared state check, resolved order, warning when a declared dependency is provided by no fleet service) in `tests/Unit/CliTest.php`, `src/Cli/Application.php`, `bin/watcher` (after T008, T021; uses `Factory::doctor`/`heartbeatStatus` as they are)
- [x] T024 [P] [US4] Docs and example: `docs/examples/watcher-fleet.php` (api + two BFFs from the audit rollout, names generic in the package: use `api`, `bff-a`, `bff-b`), `docs/operations.md` (hosting: dedicated job recommended, a fleet inside a watched service dies with it, heartbeat of the fleet, `--only`, correlation limits), `docs/configuration.md` (fleet keys), `README.md`, `CHANGELOG.md`; the `ArchitectureTest` consumer-name rule must pass for `src/Fleet` (no `orders-api`) in `docs/`, `README.md`, `CHANGELOG.md`

**Checkpoint**: quickstart 28-30 green.

---

## Phase 6: Polish

- [x] T025 Full gates and review: `composer test`, `composer stan`, `composer style` each exit 0 (read `$?`); confirm with `git diff --stat` that `src/Watcher.php` changed only at the three places in R6, `Factory.php` and `WatchConfig.php` are untouched; run quickstart 1-30; update this checklist. Commit only when the owner asks

---

## Dependencies & Execution Order

```text
T001 ─► T002 ─┐
T003 ─► T004 ─┴─► T007[R] ◄── T005, T006
                   ├─► T008[A]
                   └─► T009 [P]
US2:  T010, T011, T012 (parallel) ─► T013 ─► T014[W] ─► T015[R] ◄── T012
US3:  T016, T017, T018 (parallel) ─► T019 ─┐
                                     T020[W] (after T014) ─┴─► T021[R] (after T015)
US4:  T022 [P], T024 [P];  T023[A] (after T008, T021)
T025 last
```

**Sequential groups (never parallel inside a group)**

| Hot file | Tasks, in order |
|---|---|
| `src/Watcher.php` | T014 -> T020 (US1 and US4 need none); T020 also edits `src/Notify/DigestData.php`, `src/Notify/DigestRenderer.php` |
| `src/Cli/Application.php`, `bin/watcher`, `tests/Unit/CliTest.php` | T008 -> T023 |
| `src/Fleet/FleetRunner.php` | T007 -> T015 -> T021 |
| `src/Fleet/FleetSeam.php` | T013 -> T021 |
| `src/Laravel/WatcherServiceProvider.php` | T009 only |
| `src/Config/Factory.php`, `src/Config/WatchConfig.php` | none (called, not edited) |

## Parallel Examples

- **Start**: T001 and T003 together; then T002 and T004 together.
- **US1**: T005 and T006 together; T008 and T009 together after T007.
- **US2**: T010, T011, T012 together; T013 is the single implementation gate.
- **US3**: T016, T017, T018 together (distinct new files); T019 and T020 together (different files).
- **Across stories**: after T004 the test-only tasks T010, T011, T012, T016, T017, T018 can all be written before any US2/US3 implementation.
- **US4**: T022 and T024 any time after T021.

## Implementation Strategy

1. Phase 1, then US1 (fleet loop, isolation, CLI): ship-able without touching `Watcher.php`.
2. US2 (fleet cap, combined digest): first `Watcher.php` seam, deliberately tiny.
3. US3 (correlation): second seam; privacy corpus first.
4. US4 docs/status; gates last.

## Edge cases and where they are covered

| Edge case | Task |
|---|---|
| service with no logs in the window: quiet, not failed | T006 |
| dependency cycle | T003, T017 |
| two services referencing each other's hosts: one finding per origin | T017 |
| second concurrent fleet run skips services whose lock is held | T006 (`skipped_locked`) |
| service removed from the fleet: nothing deleted | T006 (state untouched assertion) |
| dozens of services, wall time bounded | T006 (time budget), T012 (size) |
