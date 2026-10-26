# Implementation Plan: Kubernetes Events Source

**Branch**: `feat/k8s-c-events` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: `specs/008-kubernetes-events/spec.md` (including "Owner decisions"), `docs/audit-2026-10-23.md` feature 3

## Summary

A third `LogSource` implementation, `KubernetesEventsSource`, over a small read-only HTTP client (`KubeApi`), plus a
per-service expiring ledger in the existing `StateStore`. **No new core contract**: the spec's contract decision holds
(`LogSource` fits; `versions()` returns `[]`). The pipeline after the source (Normalizer, Reducer, Redactor, Selector,
analyzer, renderer) is unchanged except for three optional, defaulted pass-through fields.

1. **Client** (US3): `KubeApi` issues GET only, against an allowlist of two resources (`events`, `pods`), per
   namespace, with the existing `Retry`. In-cluster service account or explicit server/token/CA. No watch, no write,
   no exec/logs/proxy; an architecture test fails if another verb or resource appears.
2. **Mapping** (US1, US4): `EventMapper` turns a Kubernetes event (and two pod-status signals, see R2) into an
   `Observation`; `EventMasker` applies the Kubernetes masking rules (R5) before the Normalizer; `ReasonLevels` decides
   the level in code from a documented, configurable table.
3. **Ledger** (US1, retention decision): `EventLedger` merges each read into a per-service, expiring record in the
   state store keyed by a hash of (object, reason, masked message); it stores the increase of `count` with the event's
   timestamp, so reading the same event twice adds nothing (FR-007) and 24 hourly reads equal one daily read (SC-003).
   `lines()` replays the ledger for the window as one `LogEvent` per ledger entry with `occurrences = n`.
4. **Pipeline** (US2): `Watcher` takes an optional `?KubernetesEventsSource $events`; when set, its events are read
   after the main source's and flow through the same Reducer. A failure is caught, becomes a digest notice, and never
   touches the log part. Every trigger, due or not, calls `ingest()` first (hourly ledger update) without the lock.
5. **Operability** (US3): `Doctor` gains an `events` check; `JsonSummary`/heartbeat gain events status and counts.

Decisions: [research.md](research.md). Shapes: [data-model.md](data-model.md). Config, API surface, digest, CLI:
[contracts/](contracts/). Scenarios: [quickstart.md](quickstart.md). Tasks: [tasks.md](tasks.md).

## Technical Context

**Language/Version**: PHP ^8.1. **New dependencies**: none (Guzzle is already required; no cluster SDK, R1).

**Storage**: the existing `StateStore`. One new key kind `evt:{service}` (`Keys::events`), TTL = ledger retention.

**Testing**: PHPUnit; Guzzle `MockHandler` driven by recorded JSON under `tests/Fixtures/kubernetes/`; no cluster, no
network. A recording handler asserts the request log (SC-005). The privacy corpus gains Kubernetes-shaped events.

**Constraints**:
- `events` absent (or `kubernetes.enabled` false): zero cluster requests, zero state keys, byte-identical output (SC-004).
- Model payload for a non-event group is byte-identical: new `RedactedGroup` keys are emitted only when set (R7).
- `Watcher.php` gets four small hunks (constructor argument and bind, trigger ingest, event merge, notice); the logic
  lives in `src/Events/` (R8), so it survives Lane A's decomposition.
- `Factory.php` and `WatchConfig.php`: hunks only (shared with Lane B).

## Constitution Check

| Principle | Gate | Result |
|---|---|---|
| I. Privacy fails closed | Event JSON is parsed into a closed `Observation` (allowlisted fields only; annotations, labels except the selector keys, env, commands, `managedFields` are never read). `EventMasker` then the Normalizer run before grouping; a masking error drops the event. The ledger persists only fully masked text, counts, timestamps and one-way pod hashes, all with an expiry. Corpus extended in the same change (T009 before any mapper). | Pass, with one spec drift (D1) |
| II. Read-only | GET on `events` and `pods` only; architecture test over `KubeApi`; `check` makes the same GETs only and says it cannot see extra rights (D3). | Pass, with D3 |
| III. Test-first | Every task is preceded by a red test task; fixtures only. | Pass |
| IV. Code decides | Levels, escalation, dedupe, attribution and coverage are code; the model sees ordinary groups. | Pass |
| V. Token discipline | Quiet cluster: 0 groups, 0 tokens. Event groups obey existing per-group budgets. Three optional payload keys appear only on event groups (about 12 tokens per such group); non-event groups unchanged, so SC-004 holds. Token-budget fixture added (T041). | Pass |
| VI. Config over code | Everything in `kubernetes.*`, validated at load, naming the key. Reason lists, thresholds, caps, allowlists are configuration with documented defaults. Nothing consumer-specific. | Pass |
| VII. Failures loud | Events source failure -> digest notice with a reason class; unattributed events and ledger gaps -> coverage note; hourly ingest failure -> host log line and a recorded gap. | Pass |
| VIII. Simplicity | No new interface. No extra resources (no replicasets/deployments calls, R3). No cluster SDK. | Pass |

**Spec drift (recorded as amendments A1-A5 in spec.md, confirmed by the owner 2026-10-24)**

- **D1 (FR-009).** The spec says only "masked fingerprints, levels, counts and timestamps" are persisted. The daily run
  must still print a message text for an event the cluster forgot an hour after it happened, so the ledger also keeps
  the **fully masked** message (EventMasker + Normalizer output, at most 300 characters). Same privacy class as a
  template; never raw. Spec FR-009 to be amended: "masked fingerprints, levels, masked text, counts and timestamps".
- **D2 (privacy, probe URLs).** The spec keeps "path template" of probe URLs. The existing Normalizer URL rule always
  removes path, query and port, and fails closed. We follow the Normalizer; the status code in the message survives.
- **D3 (FR-016 / SC-005, write-capable account).** The only way to learn an account's rights is a
  `SelfSubjectRulesReview`, a POST of a review object (it creates nothing, but it is not a GET), and probing a
  forbidden resource with a GET (for example `secrets`) would return secret data if the account could read it.
  FR-003 forbids both. Decision: `check` verifies **read** access by real GETs (reachability, TLS, list `events`
  and `pods` per namespace) and **cannot** detect extra rights; the documentation gives the minimal RBAC and says so.
  SC-005's "`check` flags a write-capable account" is therefore not met; the request log still proves the watcher never
  issues a write. The owner may allow the single review POST by a constitution-level amendment; not assumed here.
- **D4 (acceptance 2 vs Owner decision 3).** The spec's acceptance list and the Owner decisions differ; the Owner
  decisions win. Default critical reasons: `CrashLoopBackOff`, `BackOff`, `OOMKilled`, `ImagePullBackOff`, `ErrImagePull`,
  `Failed`, `Evicted`, `FailedMount`, `FailedScheduling`, plus `Unhealthy` when the pod has restarted or the probe failed
  at least `min_repeat_for_probe_failures` times. `FailedGetResourceMetric` stays Warning (visible, not escalated)
  unless the Operator adds it. `BackOff` and `Failed` are listed because they are the actual event reasons
  behind crash loops and pull errors (R2).

## Project Structure

```text
src/Source/KubernetesEventsSource.php      LogSource: lines/totals/lastReadTruncated/versions; ingest(); bindState()
src/Source/Kubernetes/KubeApi.php          GET-only client, allowlisted paths, in-cluster or explicit credentials, short memo
src/Source/Kubernetes/KubeSettings.php     validated settings (from WatchConfig 'kubernetes' section)
src/Source/Kubernetes/EventMapper.php      JSON -> Observation (core/v1 and events.k8s.io/v1), pod-status signals
src/Source/Kubernetes/EventMasker.php      pod, node, image, id, quoted-name masking
src/Source/Kubernetes/ReasonLevels.php     type/reason -> Level
src/Source/Kubernetes/LabelSelector.php    equality and set-based selectors, in code
src/Source/Kubernetes/Observation.php      one event occurrence set (closed value object)
src/Source/Kubernetes/EventLedger.php      expiring per-service store, deltas, window replay, coverage facts
src/Events/EventsRun.php                   Watcher-side glue: ingest on trigger, merged stream, notice, coverage line
src/Source/LogEvent.php                    + occurrences, podId (defaulted)
src/Reduce/Reducer.php, ProblemGroup.php   + occurrences weight, origin, pods, workload kind (defaulted)
src/Reduce/TemplateMiner.php               + weight on add()
src/Privacy/RedactedGroup.php              + src, pods, wk (emitted only when set)
src/Notify/DigestRenderer.php, DigestData  + pod-event marker on a finding, coverage/notice lines
src/Config/WatchConfig.php                 + `kubernetes`, `events`, `source.driver: kubernetes`, flag
src/Config/Factory.php                     + events(), source() branch, shared KubeApi per credentials
src/Fleet/FleetRunner.php                  + events sources per entry, shared KubeApi
src/Operate/Doctor.php                     + `events` check (+ exporter warning)
src/Operate/RunStats.php, JsonSummary.php  + events counters/status
src/State/Keys.php                         + EVENTS
tests/Fixtures/kubernetes/*.json           recorded lists; tests/Unit/Source/Kubernetes/*, tests/Contract/KubernetesEventsSourceTest.php,
tests/EndToEnd/KubernetesEventsTest.php, tests/Unit/Architecture/KubernetesReadOnlyTest.php
docs/kubernetes.md, docs/configuration.md, docs/privacy.md, docs/operations.md, docs/examples/kubernetes.config.php
```

## Complexity Tracking

| Item | Why needed | Simpler rejected because |
|---|---|---|
| Ledger in state (per hourly trigger) | Cluster keeps events about 1 h; owner accepted hourly ingestion (Owner decision 1) | Reading once a day loses 23/24 of events |
| Pod-status signals (`OOMKilled`, restarts) | Kubelet does not emit an event for an OOM kill of a container; it is only in `status.containerStatuses[].lastState` of the pod. SC-001 requires OOMKilled | Events alone cannot produce the OOMKilled group the spec demands |
| Three defaulted fields on `LogEvent`/`ProblemGroup`/`RedactedGroup` | Occurrence weight, distinct pod count and pod-event marker must cross the Reducer | Repeating a `LogEvent` N times breaks the event cap and cost; putting counts in the template mixes text and numbers |
