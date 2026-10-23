---

description: "Task list for Smarter Baselines and Better Templates (everrest/watcher)"
---

# Tasks: Smarter Baselines and Better Templates

**Input**: `specs/004-baselines-and-templates/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Tests**: REQUIRED (Constitution III). Each implementation task names the test task(s) it turns green; the test task
is written first and must fail first. No test touches the network.

**Format**: `[ID] [P?] [Story] Description`. Paths relative to `/path/to/watcher`. `[P]` = different files, no
unfinished dependency. Tasks marked **SEQUENTIAL** touch `src/Watcher.php`, `src/Reduce/Reducer.php`,
`src/Reduce/TemplateMiner.php` or `src/Notify/DigestRenderer.php` and must run one at a time in the stated order.

**Gate command** (used by every "green" step): `composer test; echo $?` then `composer stan; echo $?` then
`composer style; echo $?`. Read the exit codes.

**No commits, pushes or tags** unless the owner asks.

---

## Phase 0: Gate

- [ ] T001 Confirm the in-flight pointer work (`snap:<svc>:last`, fallback up to `max_age`, digest notice) is committed
  and green. Re-read `src/Watcher.php` (`locked()`, `writeSnapshot()`, the `$prev` lookup, the `DigestData` call) and
  record: the config key it uses for the maximum comparison age; the exact notice wording (copy into
  `contracts/digest-wording.md`, row 1); whether it already scales counts (if yes, move that test to T008 and drop the
  scaling case from T010). Run the gate command; all exit 0. **Blocks every task below.**

## Phase 1: Foundational (blocks the stories)

- [ ] T002 [P] Write red config tests in `tests/Unit/Config/BaselineConfigTest.php` for `baseline.*` and `grouping.*`:
  defaults; each validation message from `contracts/configuration.md`; non-whole-day window with `rolling`;
  `testRetentionTooShortForWeekdayMode` (FR-008); `max_age` above retention; `backend_patterns: true` rejected.
- [ ] T003 Implement the keys in `src/Config/WatchConfig.php` (follow `selection` parsing and `self::only()`); expose
  `baseline` and `grouping` arrays on `WatchConfig`; add them to `config/watcher.example.php`. Greens T002. Depends on
  T002. Keep T001's key name for `max_age`.
- [ ] T004 [P] Write a red test in `tests/Unit/Schedule/RunGateTest.php`: `endBack($end, $days)` returns the `run_at`
  instant `$days` local days earlier; across spring-forward and fall-back; for a `run_at` inside a gap; 7 and 28 days.
- [ ] T005 Add public `RunGate::endBack(DateTimeImmutable $end, int $days): DateTimeImmutable` in
  `src/Schedule/RunGate.php`, delegating to `runAtOn($end, -$days)`. Greens T004. Depends on T004.
- [ ] T006 [P] Write red tests: `tests/Unit/Reduce/GroupStatusTest.php` (`resolve` with a float comparison value and a
  `seen` flag; `Expected` when the value is 0 but seen; `Grown` at exactly `factor x value`; previous mode results are
  identical to today's), and `tests/Unit/Privacy/` case that `RedactedGroup` maps `Expected` to label `expected`.
- [ ] T007 Add `GroupStatus::Expected` and the float/`seen` signature in `src/Reduce/GroupStatus.php`; adapt
  `src/Reduce/ProblemGroup.php::withPrevious()` (keep `previousCount` an int for rendering); add the case to
  `src/Privacy/GroupStatusLabel.php` and the `match` in `src/Privacy/RedactedGroup.php`. Greens T006. Depends on T006.
  Check the analyzer prompt and schema fixtures under `tests/Fixtures/anthropic` and `src/Analyze` for an enumerated
  status list and update it in this task.

**Checkpoint**: gate command green.

## Phase 2: User Story 1 - A missed day or a clock change does not reset the comparison (P1, remainder)

**Goal**: the parts of US1 not covered by the in-flight work.
**Independent test**: scenarios 1-5 of quickstart.md.

- [ ] T008 [P] [US1] Write `tests/EndToEnd/MissedDayTest.php` with `testMissedMiddleDayStillReports`,
  `testTooOldRecordsBaseline`, `testFailedRunKeepsOlderSnapshotUsable`, using `Harness`. Also check by reading that
  `tests/EndToEnd/DstTest.php` covers spring and autumn with no second baseline, and that the Loki and Datadog contract
  tests have a transient-failure retry case (SC-006); add only the missing cases. Tests that the in-flight work already
  satisfies will pass immediately; note which. Depends on T001.
- [ ] T009 [US1] **SEQUENTIAL (`src/Watcher.php`, 1 of 3: T009, T014, T022)**. In `writeSnapshot()` add `start` (window
  start, UTC ISO-8601) to the stored document. Add the assertion to `MissedDayTest` or `RunSummaryTest` that the stored
  snapshot has `start`. Depends on T001, T008.

**Checkpoint**: US1 acceptance scenarios 1-5 pass; per-hour scaling arrives with T011.

## Phase 3: User Story 2 - Compare with what is normal for this day (P1)

**Goal**: previous / rolling / weekday comparison with a visible mode.
**Independent test**: quickstart scenarios 7-15.

- [ ] T010 [P] [US2] Write red `tests/Unit/Baseline/BaselineResolverTest.php` with `InMemoryStateStore` and
  `FrozenClock`: the 15 cases named in quickstart.md rows 6, 9-11, 13-15 (no `start`; rolling with 7/4/1 snapshots;
  never seen is new in every mode; seen on rare day is expected; exclusion; `since`; scaling to window length; weekday
  with 2 snapshots falls back to previous with `reason = short_history`; previous mode reproduces today's numbers).
  Depends on T003, T005, T007.
- [ ] T011 [P] [US2] Create `src/Baseline/Comparison.php` and `src/Baseline/BaselineResolver.php` per data-model.md:
  candidate ends via `RunGate::endBack`, one `get` per candidate, skip missing / excluded / before `since`, stream one
  snapshot at a time keeping only the requested fingerprints, per-hour scaling, mean / median, `statusFor`,
  `previousFor`. The previous-window case uses whatever lookup T001 found (pointer) behind the same interface.
  New files only. Greens T010. Depends on T010.
- [ ] T012 [P] [US2] Write `tests/EndToEnd/BaselineModesTest.php`: `testWeeklyPatternHasNoFalseGrowthInWeekdayMode`
  (5 weeks, constant per-weekday levels; weekday mode 0 grown, previous mode >= 1), `testRealJumpIsGrownInBothModes`
  (4x on one day), and `testDefaultModeIsUnchanged` (same output as before for `previous`). Red until T014. Also add
  the `CostTest` assertion that the number of model calls is the same in all modes for an unchanged workload.
- [ ] T013 [P] [US2] Write red `DigestTest::testComparisonLines` in `tests/EndToEnd/DigestTest.php` for every row of
  `contracts/digest-wording.md`, then implement them in `src/Notify/DigestRenderer.php` and `src/Notify/DigestData.php`
  (a `comparison` field carrying `Comparison`; trend text for `expected`). **SEQUENTIAL for `DigestRenderer.php` (1 of 2:
  T013, T022).** Depends on T007, T011 (the type), not on `Watcher.php`.
- [ ] T014 [US2] **SEQUENTIAL (`src/Watcher.php`, 2 of 3)**. In `locked()` replace the group-status lookup (the
  `$previousGroups` map and `withPrevious` call) and the `$prev === null` baseline decision with the `Comparison`
  from `BaselineResolver` built from `$c->baseline`, `RunGate::endBack` and the fingerprints of `$groups`; keep `$prev`
  for `DeployInfo` and `LevelAnomaly` (unchanged); pass the `Comparison` to the `DigestData` construction. Greens
  T012. Depends on T009, T011, T013 and on nobody else editing `Watcher.php`.

**Checkpoint**: US2 independently demonstrable; default mode output byte-identical to before.

## Phase 4: User Story 3 - Group messages by similarity, not by length (P1)

**Goal**: readable, deterministic, bounded templates; fingerprints unchanged.
**Independent test**: quickstart scenarios 16-25.

- [ ] T015 [P] [US3] Rewrite `tests/Unit/Reduce/TemplateMinerTest.php` for the new API
  (`add(event, tokens, fp)`, `template(fp)`, `stats()`): keep the existing assertions (varying position becomes `<*>`,
  small cluster withheld, identical-value known limit) and add `testSameLengthUnrelatedMessagesStaySeparate`,
  `testOrderIndependent` (50 shuffles of one multiset, identical results), `testSpacelessMessageNeverFails`,
  `testPlaceholderEqualityDoesNotMergeOppositeMessages` (`<ID> <NUM> failed` vs `... succeeded`),
  `testFingerprintWithTwoClustersTakesLargest`.
- [ ] T016 [P] [US3] Write `tests/Unit/Reduce/MinerBoundsTest.php`: `testCapReportsTruncationAndWithholds`,
  `testBudgetExhaustionWithholdsRemaining`, a 10x-scaled SC-005 case in the default suite and the full-size case under
  `@group perf` (add the group to `phpunit.xml.dist` as excluded by default); assert memory with
  `memory_get_peak_usage`.
- [ ] T017 [P] [US3] Extend the privacy corpus first (Constitution I): add to `tests/Fixtures/privacy/corpus.log` and
  `tests/Fixtures/privacy/seeded-values.php` unrelated same-length messages, a name varying inside a group, a name
  repeating in a retry loop of fewer events than `min_group_events_for_text`, and one repeating at or above it (documents
  the known limit, asserting the Normalizer mask still holds); run through `tests/Unit/PrivacyCorpusTest.php` and
  `tests/EndToEnd/PrivacyTest.php` for every baseline mode. Expect red on the new similarity paths; zero seeded values
  is the pass condition.
- [ ] T018 [P] [US3] Write `tests/Unit/Reduce/TemplateReadabilityTest.php` over the existing `tests/Fixtures/logs/*.log`
  plus a new `tests/Fixtures/logs/same_length_mixed.log` (three problems of equal word count with varying ids, names,
  numbers): at least 80% of groups with the minimum events have at least half their words literal (SC-003); also the
  `CostTest` payload-token check (+10% at most, SC-007). Use this test to confirm or retune `grouping.similarity`
  (default 0.5) and record the outcome in research.md R8.
- [ ] T019 [P] [US3] Add `testGoldenFingerprints` to `tests/Unit/Reduce/FingerprinterTest.php` with literal expected
  values computed from the current code, plus "same id at 1 event and at 5". Regression pin for FR-012; it is green
  immediately and must stay green.
- [ ] T020 [US3] **SEQUENTIAL (`src/Reduce/TemplateMiner.php`, 1 of 2: T020, T021)**. Rewrite `TemplateMiner` per
  plan.md: distinct-message table per bucket, cap and comparison budget from `GroupingSettings`, lazy deterministic
  leader clustering, similarity over literal tokens, `template(fp)`, `stats()`. Remove `keyOf`/`template(key)`. No change
  to `tokens()`. Greens T015, T016 and the miner-level parts of T017. Depends on T015, T016, T017, T019.
- [ ] T021 [US3] **SEQUENTIAL (`src/Reduce/Reducer.php`, after T020)**. Pass the fingerprint to the miner, drop the
  per-group cluster `key`, take the template from `template($fp)`, add `Reducer::mining(): MiningStats`, accept the
  grouping settings in the constructor (default values keep existing tests valid). Update
  `tests/Unit/Reduce/ReducerTest.php` for the new constructor (test edit first, red, then code). Greens T018.
  Depends on T020.
- [ ] T022 [US3] **SEQUENTIAL (`src/Watcher.php`, 3 of 3; `src/Notify/DigestRenderer.php`, 2 of 2)**. Pass
  `$c->grouping` to the `Reducer` construction, pass `$reducer->mining()` into `DigestData`, and render the two
  truncation lines from `contracts/digest-wording.md`. Test first: `DigestTest::testGroupingTruncationLines`. Greens
  T017's end-to-end cases. Depends on T014, T021.

**Checkpoint**: US3 independently demonstrable; fingerprints and comparisons unchanged from the previous release.

## Phase 5: User Story 4 - Backend patterns (P3, deferred)

- [ ] T023 [US4] **DO NOT START** until the owner answers O5 in research.md and the Loki patterns endpoint is verified
  on a live system. If approved: red contract test with a fake backend in `tests/Contract/LokiSourceTest.php`, an
  optional `src/Source/PatternProvider.php` implemented by `LokiSource` only, hints used only to choose which cluster a
  message joins (never as template text), fallback to built-in grouping on absence or error, and `grouping.backend_patterns`
  accepted. Touches `src/Reduce/TemplateMiner.php` and `src/Watcher.php`: sequential after T022.

## Phase 6: Polish

- [ ] T024 [P] Update docs: `docs/configuration.md` (new keys and validation), `docs/operations.md` (how to read the
  comparison line, excluding a date, resetting history, upgrade note: templates may change, fingerprints do not),
  `docs/privacy.md` (similarity runs on masked tokens; clustering is per distinct message; the one new payload value
  `expected`), `README.md` if it lists settings, `docs/roadmap.md` row 004, `docs/review-2026-10-23.md` H6 and P5 status,
  `CHANGELOG.md` under `## Unreleased`.
- [ ] T025 Final gate: `composer ci; echo $?` (style, stan, test) all exit 0; run quickstart.md section 2 in full and
  tick every row; run `vendor/bin/phpunit --group perf; echo $?` once; confirm with `git diff --stat` (read only) that
  no file outside the plan's touch list changed. Depends on all earlier tasks except T023.

---

## Dependencies and order

```text
T001 -> everything
Foundational:   T002 -> T003      T004 -> T005      T006 -> T007        (three chains, run in parallel)
US1:            T008 -> T009 (Watcher.php)
US2:            T010 -> T011 ;  T013 ;  T012 ;  T009+T011+T013 -> T014 (Watcher.php)
US3:            T015,T016,T017,T019 -> T020 -> T021 ;  T014+T021 -> T022 (Watcher.php) ;  T018 -> tune after T021
US4:            T023 (blocked)
Polish:         T024 (parallel with US3), T025 last
```

### Strictly sequential file sets

| File | Tasks, in order |
|---|---|
| `src/Watcher.php` | T009, T014, T022 (and T023 if ever built); never concurrent with another agent |
| `src/Reduce/TemplateMiner.php` | T020, then T023 |
| `src/Reduce/Reducer.php` | T021 (after T020) |
| `src/Notify/DigestRenderer.php` | T013, then T022 |

### Parallel opportunities

After T001: T002, T004, T006, T008, T015, T016, T017, T018, T019 can all start at once (tests only, different files).
T011 and T013 run in parallel once their red tests exist. T024 runs alongside US3.

### MVP

US2 (T001-T014) is the first shippable slice; US3 (T015-T022) is the second. Either can ship alone because fingerprints
do not change.

Task count: 25 (24 buildable, T023 deferred).
