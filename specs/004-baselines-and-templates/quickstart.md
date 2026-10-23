# Quickstart: validating Baselines and Templates

## 1. Gates (no network)

```bash
composer install
composer test; echo $?     # Unit + Contract + EndToEnd
composer stan; echo $?
composer style; echo $?
```

Read each exit code (`echo $?` on its own line), not the last line of output. The full-size performance scenario is
opt-in: `vendor/bin/phpunit --group perf; echo $?`.

## 2. Scenarios, mapped to tests

Fakes only: `FakeLogSource`, `InMemoryStateStore`, `FrozenClock`, `FakeAnalyzer`, `FakeNotifier` from `tests/Fakes`, and
the harness in `tests/EndToEnd/Harness.php`.

| # | Scenario | Expected | Test (file::method) | Spec |
|---|---|---|---|---|
| 1 | Three daily windows, the middle run failed | third run reports against the first snapshot; digest names the window used; no baseline | `tests/EndToEnd/MissedDayTest.php::testMissedMiddleDayStillReports` (T008) | US1-1, SC-001 |
| 2 | Only a snapshot older than `max_age` exists | baseline recorded, as today | `MissedDayTest::testTooOldRecordsBaseline` | US1-2 |
| 3 | Run across the spring and the autumn clock change | no second baseline, windows adjoin, due hour once per local day | existing `tests/EndToEnd/DstTest.php` (checked in T008) | US1-3 |
| 4 | Log source returns 503 once | absorbed by retry, run succeeds | existing `tests/Contract/LokiSourceTest.php` / `DatadogSourceTest.php` retry cases (checked in T008) | US1-4, SC-006 |
| 5 | A run that fails writes no snapshot and does not move the pointer | next run still compares with the older snapshot | `MissedDayTest::testFailedRunKeepsOlderSnapshotUsable` | US1-5 |
| 6 | Snapshot written before this feature (no `start`) | compared, assumed same length | `tests/Unit/Baseline/BaselineResolverTest.php::testSnapshotWithoutStartIsAssumedSameLength` (T010) | edge case |
| 7 | Weekly pattern, constant per weekday, 5 weeks | weekday mode: 0 grown; previous mode: >= 1 grown on Mondays | `tests/EndToEnd/BaselineModesTest.php::testWeeklyPatternHasNoFalseGrowthInWeekdayMode` (T012) | US2-1, SC-002 |
| 8 | Same pattern with a real 4x jump on one day | grown in both modes | `BaselineModesTest::testRealJumpIsGrownInBothModes` | SC-002 |
| 9 | Rolling N=7 with 4 snapshots / 1 snapshot | uses 4 / falls back to previous, digest says so | `BaselineResolverTest::testRollingUsesAvailableOrFallsBack` | US2-2 |
| 10 | Group in no used snapshot | `new` in every mode | `BaselineResolverTest::testNeverSeenIsNewInEveryMode` | US2-3 |
| 11 | Group seen only on past Mondays, today Monday | `expected`, not `new`; `grown` only at 3x | `BaselineResolverTest::testSeenOnRareDayIsExpected`; `GroupStatusTest::testExpected` (T006) | US2-4 |
| 12 | `retention` shorter than `windows x 7 d` | `InvalidConfig` naming `state.retention`, before any call | `tests/Unit/Config/BaselineConfigTest.php::testRetentionTooShortForWeekdayMode` (T002) | US2-5, FR-008 |
| 13 | `baseline.exclude` contains a holiday date | that window is left out of the average; digest prints `1 excluded` | `BaselineResolverTest::testExcludedDateIsSkipped` | US2-6 |
| 14 | `baseline.since` set | older windows ignored | `BaselineResolverTest::testSinceIgnoresOlderWindows` | edge case (reset) |
| 15 | Window length changed from 24 h to 12 h | per-hour comparison, digest line says so | `BaselineResolverTest::testScalesToWindowLength` | edge case |
| 16 | Three distinct problems of equal word count, varying ids and names | three groups, three readable templates | `tests/Unit/Reduce/TemplateMinerTest.php::testSameLengthUnrelatedMessagesStaySeparate` (T015) | US3-1/2 |
| 17 | Same events in shuffled order (property: 50 shuffles) | identical groups and templates | `TemplateMinerTest::testOrderIndependent` | US3-5, FR-010 |
| 18 | Cluster below `min_group_events_for_text` | template null | `TemplateMinerTest::testSmallClusterWithheld` | US3-3 |
| 19 | Privacy corpus: unrelated same-length messages, names varying inside a group, name repeating in a retry loop below the minimum | 0 seeded values in any outgoing template, for every baseline mode | `tests/Unit/PrivacyCorpusTest.php`, `tests/EndToEnd/PrivacyTest.php` (T017) | US3-4, FR-013, SC-004 |
| 20 | Fingerprint of a fixed event set | golden values unchanged; same id at 1 event and at 5 | `tests/Unit/Reduce/FingerprinterTest.php::testGoldenFingerprints` (T019) | US3-6, FR-012 |
| 21 | Sample logs of three services (fixtures) | >= 80% of groups with min events have >= half their words readable | `tests/Unit/Reduce/TemplateReadabilityTest.php` (T018) | SC-003 |
| 22 | 200,000 events / 100,000 distinct messages | within 256 MB and 60 s, or `truncated` reported; default suite runs a 10x smaller case | `tests/Unit/Reduce/MinerBoundsTest.php` (+ `@group perf` full size) (T016) | FR-010, SC-005 |
| 23 | Distinct messages above `message_cap` | counted, not stored, groups without stored messages have null template, digest line printed | `MinerBoundsTest::testCapReportsTruncationAndWithholds` | edge case |
| 24 | Unchanged workload with `similarity` mode | same number of model calls; payload tokens within +10% | `tests/EndToEnd/CostTest.php::testGroupingDoesNotChangeCallCount` (T018) | SC-007 |
| 25 | Message without spaces (CJK) | one token; groups only when equal; no failure | `TemplateMinerTest::testSpacelessMessageNeverFails` | edge case |
| 26 | Digest wording for each case in `contracts/digest-wording.md` | exact strings | `tests/EndToEnd/DigestTest.php::testComparisonLines` (T013) | FR-002, FR-005 |

## 3. Manual check on a real window (after merge)

`bin/watcher run --config=<file> --dry-run` with `baseline.mode => 'weekday'` against a service with at least four weeks
of snapshots. The dry run prints the digest without writing state; confirm the comparison line names four Mondays (or the
current weekday) and no Monday-after-weekend finding is marked `grown` without a real rise.

## 4. Upgrade check

Run the previous release for one window, upgrade, run again with default config: no baseline notice, no group reported
as `new` that was not new before, template text may differ.
