# Quickstart: Fleet Mode and Cross-Service Correlation

Scenarios are written so each one is a test (fakes only, no network). `H` = `tests/EndToEnd/FleetHarness.php`
(three fake services `api`, `bff-a`, `bff-b`, shared `InMemoryStateStore`, `FakeNotifier`, `FrozenClock`).
`composer test`, `composer stan`, `composer style` must each exit 0 (read `$?`).

| # | Scenario | Expected | Test (task) |
|---|---|---|---|
| **US1** | | | |
| 1 | Fleet of three, all with logs | each has its own result and snapshot/run keys; results equal three single runs (SC-001) | `FleetTest::testEqualsThreeSingleRuns` (T006) |
| 2 | `bff-a` source throws `SourceUnavailable` | `bff-a` failed + its own notice naming it; `api`, `bff-b` normal; exit 1 (SC-002) | `FleetTest::testFailureIsIsolated` (T006) |
| 3 | `bff-a` analyzer throws an unexpected `RuntimeException` | failed entry `internal error (RuntimeException)`; others unaffected | `FleetTest::testUnexpectedThrowIsContained` (T006) |
| 4 | Services with different `run_at`, hourly triggers | each runs only in its own hour; others `skipped_not_due`, exit 0 | `FleetTest::testEachServiceKeepsItsOwnHour` (T006) |
| 5 | Same hour triggered twice | second run: every service `skipped_done`, nothing posted twice | `FleetTest::testSecondTriggerSkipsAll` (T006) |
| 6 | Duplicate `service`; `/` in a name; differing `state`; differing api key | rejected before any call, entries and key named, exit 2, no source call | `FleetConfigTest` (T001) |
| 7 | One invalid entry (`selection.growth_factor: 1`) | whole fleet rejected, `services[1] (bff-a): selection.growth_factor must be greater than 1` | `FleetConfigTest::testHalfValidFleetIsRejected` (T001) |
| 8 | `time_budget` of 0 seconds after the first service | remaining services `skipped_time`, listed, exit 1 | `FleetTest::testTimeBudgetSkipsAreExplicit` (T006) |
| 9 | Fleet of one | equals `Watcher::fromConfig(...)->run()` outcome and messages (FR-006) | `FleetTest::testFleetOfOneEqualsSingle` (T006) |
| 10 | `fleet:run` CLI with a bad service config | exit 2, usage/invalid text on stderr, `--json` line `{"fleet":null,"exit":2,...}` | `CliTest::testFleetRun*` (T008) |
| **US2** | | | |
| 11 | Fleet cap tiny, three services with candidates | order is deterministic; third service triage-only; first two analysed; notice says `fleet cap` (SC-003) | `FleetBudgetTest::testFleetCapStopsLaterServices` (T011) |
| 12 | Service cap reached, fleet cap not | that service triage-only, notice says `service cap`, others unaffected | `FleetBudgetTest::testServiceCapOnly` (T011) |
| 13 | Fleet total crosses 25/50/75/90 % | one notice per threshold per day for the fleet total, plus per-service ones; second trigger same day: none | `FleetBudgetTest::testFleetThresholdsOncePerDay` (T011), `FleetBudgetTest` unit (T010) |
| 14 | Per-service digests; one entry without `notify` | each posts to its own webhook; the one without uses the fleet default | `FleetTest::testDigestDestinations` (T006) |
| 15 | `digest: combined`, two services with findings, one quiet | exactly one message, a heading per service, quiet service collapsed to one line; nothing reported -> nothing posted | `CombinedDigestTest` (T012), `FleetBudgetTest::testCombinedMode` (T011) |
| 16 | Combined, 20 services with findings | one message within the character limit, overflow collapsed, never split (SC-005) | `CombinedDigestTest::testTwentyServicesOneMessage` (T012) |
| 17 | Order by priority then name, dependencies first | `FleetOrder` table test; cycle keeps sorted order | `FleetOrderTest` (T003) |
| **US3** | | | |
| 18 | `api` has a new error group; `bff-a` groups reference `<URL:api>`; windows overlap | one correlated finding naming `api`, hypothesis, consumers with counts; `bff-a`'s own digest still contains its findings, each linked one annotated `likely caused by the api finding <fp>` | `FleetCorrelationTest::testCorrelatedFindingAndAnnotatedConsumer` (T018), `CorrelatorTest` (T017) |
| 19 | `bff-a` and `bff-b` both reference `api` | one finding, both consumers listed (SC-004) | `FleetCorrelationTest::testManyConsumersOneFinding` (T018) |
| 20 | `api` errors, no consumer references `api` | no correlated finding; `api` reports normally | `CorrelatorTest::testNoReferenceNoClaim` (T017) |
| 21 | Consumer references `svc-x`, not in the fleet | no correlation; existing dependency context line unchanged | `FleetCorrelationTest::testOriginOutsideFleet` (T018) |
| 22 | Windows do not overlap, or origin group is `unchanged` | no link | `CorrelatorTest` (T017) |
| 23 | A depends on B and B on A | single finding, origin = earlier `first_seen`, no loop | `CorrelatorTest::testCycle` (T017) |
| 24 | Correlation enabled with a counting `FakeAnalyzer` | zero extra analyzer calls for the correlated finding (SC-006) | `FleetCorrelationTest::testNoModelCalls` (T018) |
| 25 | Seeded personal values in all logs | none in the correlated finding, combined digest, state, or host log (SC-007) | `FleetPrivacyTest` (T016) |
| 26 | Correlated post fails (notifier throws for the fleet destination) | loud: exit 1, host log, `correlated_posted=false`; **each consumer digest still contains its finding** (annotation included) | `FleetCorrelationTest::testCorrelatedPostFailureLosesNothing` (T018) |
| 27 | `--dry-run` | correlated finding and combined digest printed, `FakeNotifier` empty, fleet counters untouched | `FleetCorrelationTest::testDryRun` (T018) |
| **US4** | | | |
| 28 | Fleet config for two entries that exist only as log selections (no scheduler, no CLI) | digests produced with no code in those services | `FleetHostingTest::testServicesWithoutPackage` (T022) |
| 29 | `fleet:status` after a run, then clock past `stale_after` | fleet and per-service lines; stale -> exit 1 | `FleetHostingTest::testFleetHeartbeat` (T022), `CliTest::testFleetStatus` (T023) |
| 30 | Services with different `deploy` settings | each digest shows its own release | `FleetHostingTest::testReleasePerService` (T022) |

Manual smoke (after T025, owner decides): `bin/watcher fleet:check --config=docs/examples/watcher-fleet.php`, then
`fleet:run --dry-run`, then a real run against Loki.
