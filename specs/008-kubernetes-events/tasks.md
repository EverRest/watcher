---
description: "Task list for the Kubernetes Events Source (everrest/watcher)"
---

# Tasks: Kubernetes Events Source

**Input**: `specs/008-kubernetes-events/` (plan, research, data-model, contracts, quickstart)

**Tests**: REQUIRED (Constitution III). Each test task comes first and must fail before its implementation task. No
cluster, no network. Gates after each stage: `composer test`, `composer stan`, `composer style`, each exit 0 (read `$?`);
a green stage is committed (owner's standing permission), never pushed.

**Shared files**: `src/Config/Factory.php`, `src/Config/WatchConfig.php` (Lane B): hunks only. `src/Watcher.php` (Lane A
decomposes it): four hunks, logic in `src/Events/` (R8).

## Stage 0 - Spec pack
- [x] T001 plan, research, data-model, contracts, quickstart, tasks (this directory)

## Stage 1 - Pass-through fields (US1, R7)
- [x] T002 Test (red): `LogEvent` defaults `occurrences=1`, `podId=null`; `Reducer` adds `occurrences`, counts distinct `podId`, carries `origin`/`workloadKind` from labels; `TemplateMiner` weights `add()`; `ProblemGroup::withComparison/withPrevious` keep the new fields; `RedactedGroup::toArray()` has `src`/`pods`/`wk` only when origin set, otherwise byte-identical (`tests/Unit/Reduce/ReducerEventsTest.php`, `tests/Unit/Privacy/RedactedGroupTest.php`)
- [x] T003 Implement T002 in `src/Source/LogEvent.php`, `src/Reduce/{Reducer,ProblemGroup,TemplateMiner}.php`, `src/Privacy/RedactedGroup.php`

## Stage 2 - Configuration (FR-004, US4-4)
- [x] T004 Test (red): `kubernetes` section parsed with defaults; empty/missing `namespaces` rejected naming the key; bad selector, bad namespace name, `server` without `token`, unknown key, bad `involved_kinds`; `events.driver` must be `kubernetes` and needs the section; `source.driver: kubernetes` allowed with empty `stream_tags`; flag `kubernetes.enabled`; errors never contain the token (`tests/Unit/Config/KubernetesConfigTest.php`)
- [x] T005 Implement in `src/Config/WatchConfig.php` (new `kubernetes()`/`events()` methods; hunks in `TOP_LEVEL`, `FLAGS`, `source()`, constructor) and `src/Source/Kubernetes/KubeSettings.php`

## Stage 3 - Selecting, mapping, masking (US1, US4)
- [x] T006 [P] Test (red) `LabelSelector`: equality, `!=`, `in`, `notin`, exists, `!key`; invalid text rejected (`tests/Unit/Source/Kubernetes/LabelSelectorTest.php`); implement `LabelSelector.php`
- [x] T007 [P] Test (red) `ReasonLevels`: table in plan D4, `Unhealthy` escalation by repeats or restarts, Normal -> Info, unknown reason -> Warning, configurable list (`ReasonLevelsTest.php`); implement `ReasonLevels.php`
- [x] T008 Record fixtures `tests/Fixtures/kubernetes/{events-core.json,events-v1.json,pods.json,pods-page2.json}` by hand from the documented API shapes: crash loop (8 pods), OOMKilled status, pull failure, probe failure, HPA metric, eviction, Normal noise, series form, foreign namespace, third-party free text
- [x] T009 Test (red) privacy corpus for masking: `tests/Fixtures/kubernetes/seeded-values.php` + `EventMaskerTest` (pod hashes -> one text for 1/8/200 pods, node names and IPs, private registry image and tag/digest, container ids, quoted secret/configmap/volume names, probe URL with token, allowlisted registry/image kept, namespaces)
- [x] T010 Implement `EventMasker.php` (R5) until T009 is green
- [x] T011 Test (red) `EventMapper`: core/v1 and events.k8s.io/v1 and `series`; closed field list (annotations/labels/managedFields never reach `Observation`); involved kinds filter; selector on pods; owner references -> workload (R3); pod-status signals `OOMKilled` and `ContainerRestarted` (R2); pod missing -> stripped name; unknown fields dropped; text cap 300 (`EventMapperTest.php`)
- [x] T012 Implement `Observation.php`, `EventMapper.php`

## Stage 4 - Read-only client (US3, FR-002, FR-003)
- [x] T013 Test (red) `KubeApi`: allowlist of method+path (anything else throws before sending), in-cluster credentials and explicit credentials, `continue` paging, 404 fallback to core/v1, failure classes without URL/token/body, retry on 429, 60 s memo on the injected clock, no `verify => false` (`KubeApiTest.php`)
- [x] T014 Test (red) architecture `tests/Unit/Architecture/KubernetesReadOnlyTest.php`: scans the Kubernetes source files for non-GET methods, `watch`, `exec`, `log`, `proxy`, `secrets`, `configmaps`, `insecure`
- [x] T015 Implement `KubeApi.php`

## Stage 5 - Ledger (US1, retention decision, FR-007, FR-009)
- [x] T016 Test (red) `EventLedger`: delta rules (R6) incl. unchanged re-read adds 0, count reset, first sight; window replay `[start-tol, end-tol)` partition; 24 hourly reads == true count within 1 %; pruning; entry cap; gaps recorded when `last_ok` older than `event_ttl`; TTL on every put; persisted record holds no raw name/image/node (assert over `json_encode`) (`EventLedgerTest.php`)
- [x] T017 Implement `EventLedger.php`, `Keys::EVENTS`/`Keys::events()`, `Keys::ttl` default branch

## Stage 6 - The source (FR-001, FR-005, FR-006, FR-008)
- [x] T018 Test (red) `KubernetesEventsSource` through the shared `LogSourceContractTest` plus: `lines()` honours `ReadFilter` (min level, watch patterns, cap), `totals()` per level of deduplicated occurrences, `lastReadTruncated()`, `versions() === []`, `ingest()` merges and persists, `bindState()` once, dry-run store swallows writes but the run still sees the merged data (`tests/Contract/KubernetesEventsSourceTest.php`)
- [x] T019 Implement `KubernetesEventsSource.php`

## Stage 7 - Pipeline, digest, summary (US1, US2, FR-010, FR-012, FR-013, FR-017)
- [x] T020 Test (red) end to end: `tests/EndToEnd/KubernetesEventsTest.php` (quickstart 1-10, 19, 21) with a harness helper that builds the source over a `MockHandler`
- [x] T021 Test (red) `DigestRendererTest` pod-event fragment, notices and coverage line; `JsonSummary` events block only when configured
- [x] T022 Implement `src/Events/EventsRun.php`; `Watcher.php` four hunks; `DigestData`/`DigestRenderer`; `RunStats`/`JsonSummary`; heartbeat status

## Stage 8 - Factory and fleet (FR-014)
- [x] T023 Test (red) `Factory::events()`/`source()` branch, shared `KubeApi` per credentials, no credential sharing across entries; `FleetEventsTest` (quickstart 11)
- [x] T024 Implement `Factory.php` hunks, `FleetRunner.php` hunk

## Stage 9 - `watcher check` (US3, FR-016)
- [x] T025 Test (red) `DoctorEventsTest` (quickstart 13) and CLI wiring
- [x] T026 Implement `Doctor.php` hunk, `Factory::doctor()` hunk, Laravel `CheckCommand` if it lists names

## Stage 10 - Privacy, docs, release notes
- [x] T027 Extend end-to-end privacy corpus (`KubernetesPrivacyTest`, quickstart 14 and 20), including state writes and `--json`
- [x] T028 Docs: `docs/kubernetes.md` (setup, RBAC, exporter vs native, hourly trigger requirement, limits), `docs/configuration.md`, `docs/privacy.md`, `docs/operations.md`, `docs/README.md`, `docs/roadmap.md`, `docs/examples/kubernetes.config.php`, `CHANGELOG.md` (Unreleased)
- [x] T029 Final gates, `/review-diff` style self-review against the constitution, report
