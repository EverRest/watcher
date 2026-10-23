# Implementation Plan: Fleet Mode and Cross-Service Correlation

**Branch**: `003-fleet-and-correlation` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: `specs/003-fleet-and-correlation/spec.md`, `docs/audit-2026-10-23.md` (features 1 and 2, rollout survey)

## Summary

One thin runner over the existing single-service `Watcher`, plus one pure correlation step. **No new core contract**
(`LogSource`, `StateStore`, `Notifier`, `Analyzer` unchanged). The unit of work stays one service per `Watcher` run.

1. **Fleet runner** (US1, US4): `FleetConfig` validates a whole fleet (array of service configs, or a directory of
   config files) before any call. `FleetRunner` orders the entries, builds one `Watcher` per entry with the **same**
   `StateStore` and `Analyzer`, runs them one after another, catches every throwable per service, and returns a
   `FleetResult` (per-service `RunResult`, exit code = worst).
2. **Shared budget and destinations** (US2): `FleetBudget` is a second `Spend`/`BudgetAlerts` pair keyed by the id
   `fleet/{name}`. Each `Watcher` books to its own counters (unchanged) and, through a small `FleetSeam`, also to the
   fleet counters; it goes triage-only when either is exhausted and the notice names the cap. Per-service digests are
   the default (the entry's own `notify`, or the fleet default merged in at load); combined mode wraps the notifier in
   a `CollectingNotifier` and `CombinedDigest` renders one message at the end.
3. **Correlation** (US3): `Correlator` is a pure function of already-redacted groups. After each service's candidates
   are reduced and redacted, `Watcher` hands them to the seam (`FleetSeam::reduced()`); the seam stores them in a
   per-run registry and returns an annotation per consumer group that the registry's origins explain; the consumer's
   own finding stays in its digest with that annotation. The runner also posts one additive `CorrelatedFinding` after
   the last service. Decided by counts, hosts, statuses and times in code; the
   model is not called (see R4).

Decisions: [research.md](research.md). Records and shapes: [data-model.md](data-model.md). Config, CLI, finding:
[contracts/](contracts/). Scenarios: [quickstart.md](quickstart.md). Tasks: [tasks.md](tasks.md).

## Technical Context

**Language/Version**: PHP ^8.1. **New dependencies**: none.

**Storage**: the existing `StateStore` only. No new key kind: fleet counters reuse `spend:`/`alert:`/`hb:` with the
service component `fleet/{name}` (URL-encoded by `Keys`, so it cannot collide with a service name; see R2).

**Testing**: PHPUnit `Unit`, `EndToEnd`; no network. Faked sources per service (`RoutedLogSource`, `FakeLogSource`),
`FakeNotifier`, shared `InMemoryStateStore`. The privacy corpus gains the correlated finding and the combined digest.

**Constraints**:
- One failing service never changes another's result (SC-002); the fleet needs no global lock.
- Zero extra model calls for correlation (SC-006).
- A fleet of one equals a single run (FR-006); `watcher run` and `Watcher::fromConfig` are untouched.
- `Watcher.php` gets exactly two seams (budget, annotations) and no new behaviour (R6).

## Constitution Check

| Principle | Gate | Result |
|---|---|---|
| I. Privacy fails closed | `Correlator` accepts only `RedactedGroup` (type-enforced); the correlated finding carries service names, fingerprints, counts, levels, times, the origin's already-redacted template. Privacy corpus extended in T016 before the renderer exists. Combined digest only concatenates messages the single-service renderer produced. | Pass |
| II. Read-only | Fleet reads the same sources, writes only own state and posts to configured notifiers. The fleet writes no markers for correlated groups. | Pass |
| III. Test-first | Each implementation task is preceded by a red test task; all fakes, no network. | Pass |
| IV. Code decides | Correlation is decided from host name, window overlap, status/count in code; link is worded as a hypothesis. No model input is chosen by a model. | Pass |
| V. Token discipline | Correlation adds 0 tokens. Annotations are code-built strings; no group is dropped, so token use equals N single runs. Fleet cap is checked before each call (same place as the service cap). | Pass |
| VI. Config over code | New keys validated at load: `fleet`, `services`, `defaults`, `budget`, `digest`, `time_budget`, `correlation`. Nothing consumer-specific in `src/Fleet`. | Pass |
| VII. Failures loud | Failed service: its own notice, `failed` in the result, non-zero exit. Services not started for time are `skipped_time` in the output and make the exit code 1 (R7). Correlated post failing: host log + exit 1. | Pass |
| VIII. Simplicity | No interface for the seam (one class, one caller). `FleetRunner` is concrete. | Pass |

**Four-contract principle**: kept. The only coupling is a concrete `FleetSeam` class passed to `Watcher`'s constructor
as an optional last argument (R6).

**Spec drift to record**
- FR-005 orders services by priority then name. Correlation needs an origin processed before its consumers, so the
  order is priority-then-name *refined to dependencies first* (R3). Accepted by the owner; amend FR-005 in spec.md.
- FR-012 says the origin's finding "refers back" to its consumers. The origin's digest is already delivered when
  consumers run; the correlated message names the origin finding and lists consumers instead (R5). Accepted by the owner.

## Project Structure

### Documentation

```text
specs/003-fleet-and-correlation/
├── plan.md  research.md  data-model.md  quickstart.md  tasks.md
├── contracts/{config.md, cli.md, correlated-finding.md}
└── checklists/
```

### Source Code (changes only)

```text
src/
├── Fleet/                          # all new behaviour
│   ├── FleetConfig.php             # validate + merge defaults + load from array/dir; holds list<ServiceEntry>
│   ├── ServiceEntry.php            # WatchConfig + priority + provides
│   ├── FleetOrder.php              # priority, name, dependencies first
│   ├── FleetRunner.php             # loop, isolation, time budget, heartbeat, correlated post
│   ├── FleetResult.php             # per-service results, exit code, JSON
│   ├── FleetBudget.php             # fleet Spend + BudgetAlerts
│   ├── FleetSeam.php               # what Watcher sees: budget + reduced() hook + registry
│   ├── CollectingNotifier.php      # holds digest messages for combined mode
│   ├── CombinedDigest.php          # one message from per-service messages
│   ├── Correlator.php  CorrelationLink.php  CorrelatedFinding.php  CorrelationRenderer.php
├── Watcher.php                     # [hot, 2 seams] ctor ?FleetSeam; redaction moved up + seam call; fleet cap in triageOnly/metered
├── Notify/NoticeRenderer.php       # budgetExhausted(..., ?string $cap)
├── Cli/Application.php             # [hot] fleet:run, fleet:status, fleet:check
└── Laravel/FleetCommand.php, WatcherServiceProvider.php
bin/watcher
docs/examples/watcher-fleet.php
tests/{Unit/Fleet, EndToEnd/FleetTest.php, FleetBudgetTest.php, FleetCorrelationTest.php, FleetHostingTest.php, FleetHarness.php}
```

`src/Config/WatchConfig.php` and `src/Config/Factory.php` are **not** changed: `FleetConfig` strips `priority` and
`provides` and passes the rest to `WatchConfig::fromArray`; shared collaborators are built by calling the existing
`Factory::state/analyzer/source/notifier/http` from `FleetRunner::fromConfig`.

**Structure Decision**: same single library; one new namespace `Watcher\Fleet`. `Watcher.php` is 1,000 lines and a
decomposition is planned: this feature adds only two seams, so it neither blocks nor is blocked by that work (R6).

## Complexity Tracking

| Ceiling | Why | Upgrade path |
|---|---|---|
| Combined digest and correlated finding are posted by the fleet *after* each service wrote its run record | `Watcher` sends and records in one pass (001). A failed fleet post is loud (exit 1, host log) but that window is not re-sent | Add a `Watcher` callback "digest ready" that runs before the run record is written (after the decomposition) |
| Services run strictly one after another | Simplest isolation; per-service timeouts already bound each | A process pool, if wall time of dozens of services matters |
| Correlation window = group `firstSeen..lastSeen` overlap within a fleet run | Needs no extra reads (FR-013) | A configurable tolerance is already `correlation.tolerance`; cross-run correlation would need persisted groups |
| Wording is a code template, no model | SC-006 allows zero; one fewer failure mode | Optional model wording behind `correlation.word_with_model` |

## Owner decisions (2026-10-24)

1. Service order is priority, then name, refined to dependencies-in-the-fleet first (FR-005 to be amended).
2. The origin's digest is not edited; the correlated message is its back-reference (FR-012 reading accepted).
3. **Consumer findings are never withheld.** Each stays in its digest with the annotation "likely caused by the `<origin>`
   finding `<short fingerprint>`"; the correlated message is additive (N+1 messages). A failed correlated post is loud
   (host log, exit 1) and removes nothing.
4. Combined digest is posted after the services' run records are written (ceiling accepted, R8).
5. Correlated wording is a code template; no model call in v1.
6. Entry key `provides` (default `[service]`) maps dependency names to fleet services.
7. Service names containing `/`, differing `state` and differing `analysis.api_key` are rejected at load.
8. Exit code 1 also when a service ends `skipped_time` or a fleet post fails.
9. CLI `fleet:run|check|status`; Laravel `watcher:fleet`.
