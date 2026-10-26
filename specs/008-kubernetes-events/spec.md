# Feature Specification: Kubernetes Events Source

**Feature Branch**: `008-kubernetes-events`

**Created**: 2026-10-24

**Status**: Implemented

**Input**: User description: "Kubernetes events as a second log source kind for everrest/watcher, for pod-level problems invisible in application logs: CrashLoopBackOff, OOMKilled, ImagePullBackOff, failed probes, restarts, HPA errors, evictions. Read-only via the Kubernetes API (list/get events and pods) with a read-only service account or kubeconfig; namespace and label selectors; dedupe repeated events by count and last timestamp; works alongside Loki or Datadog in a fleet; pod and node names, image tags, registries and annotations masked or allowlisted. Source: docs/audit-2026-10-23.md feature 3, docs/roadmap.md item 008, the hand-made backlog.md backlog (half pod-level)."

## Overview

Half of the problems found by hand in the first rollout survey were never written to an application log: a container
killed for memory (`OOMKilled`), a pod restarting every few minutes (`CrashLoopBackOff`), an image that cannot be pulled,
a readiness probe that fails for ten minutes, an autoscaler that cannot read metrics, a pod evicted from a full node. The
application is dead or absent at that moment, so its logs say nothing. The cluster does say it, in **Kubernetes events**.
This feature reads those events, read-only, and turns them into the same kind of grouped, masked findings the digest
already carries, so a pod-level problem is reported next to the log findings of the same service.

**Actors**

- **Operator** — configures the namespace and selectors and the read-only access for one service.
- **Reader** — reads the digest; wants "pods of `api` were OOM-killed 14 times, 3 pods, since 09:10".
- **Cluster administrator** — grants the read-only role; must be sure nothing but event and pod reads is possible.

## Decision: source contract and retention

**Contract (decision).** The events source implements the existing `LogSource` contract. The four methods map without
loss: `lines()` yields one `LogEvent` per Kubernetes event (level from event type and reason, message from the masked
template, `errorType` from the reason, `time` from the last occurrence), `totals()` counts by level, `lastReadTruncated()`
reports the cap, and `versions()` returns `[]` (an events source has no per-line version label). Because it fits, **no
sibling interface is introduced** (Principle VI and VIII: a contract exists only where more than one implementation
exists; here the existing one already has Loki and Datadog, and a third implementation uses it unchanged). If planning
finds a method that cannot be implemented truthfully, the plan must record it as a complexity item and propose a narrower
optional interface; this spec does not assume one.

**Retention (decision and assumption).** Kubernetes keeps events for about **one hour** by default (the API server
`--event-ttl`, default 1h). The package triggers at least hourly and runs daily by schedule, so reading the cluster once
per day would miss most events. Decision:

1. The events source is read at **every hourly trigger**, not only at the due hour: each trigger lists the events still in
   the cluster, collapses them, and merges the result into a short-lived per-service accumulator in the state store (with
   an expiry, as Principle I requires), keyed by the stable event fingerprint; the daily run reads the accumulator for its
   window. Triggers are therefore the ingestion granularity, and a trigger missed for more than the cluster retention
   loses events (stated in the digest as a coverage note, never silently).
2. Where the Operator already ships events to Loki or Datadog with an **event exporter**, the preferred setup is to read
   them through the existing Loki or Datadog source with a stream selector (no new source needed, full retention). The
   native source is for clusters without an exporter. The documentation states both and when to choose each.

Assumption: the cluster's event TTL is the default one hour or longer; a shorter TTL is reported by `check` as a warning.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Pod-level failures appear in the digest (Priority: P1)

The Reader sees, in the service's digest, findings for crash loops, OOM kills, image pull failures, probe failures,
restarts, evictions and autoscaler errors, each with the reason, a masked template, an affected-pod count, a total count
and first/last seen, and no pod-unique names.

**Why this priority**: it is the capability the feature exists for and closes SC-001 of the audit's manual backlog.

**Independent Test**: replay a recorded events list (fixture) containing eight pods of one deployment in `CrashLoopBackOff`,
two `OOMKilled`, one `FailedPullImage`, and a `FailedGetResourceMetric` from an HPA; the run yields four groups, with
pod counts 8, 2, 1 and the HPA group, and no pod name, node name or image tag in any payload.

**Acceptance Scenarios**:

1. **Given** events in the selected namespace and labels, **When** the window is read, **Then** each event becomes a
   `LogEvent` whose message is the event reason and message with pod suffixes, node names and image references replaced by
   placeholders, so eight pods of one deployment produce one fingerprint.
2. **Given** an event of type `Warning`, **When** it is mapped, **Then** its level is Warning, raised to Error for the
   configured critical reasons (default per Owner decision 3 and amendment A4: `CrashLoopBackOff`, `BackOff`, `OOMKilled`,
   `ImagePullBackOff`, `ErrImagePull`, `Failed`, `Evicted`, `FailedMount`, `FailedScheduling`, and `Unhealthy` when repeated
   beyond a threshold or the pod has restarted; `FailedGetResourceMetric` stays Warning unless configured); an event of type
   `Normal` is Info and is dropped by the existing minimum-level filter unless a watch rule keeps it (for example
   `Killing`, `Pulled`).
3. **Given** the same fingerprint seen in several hourly triggers, **When** the daily window is built, **Then** it is one
   group whose count is the sum of deduplicated occurrences (SC and FR-007), not the number of reads.
4. **Given** a Normal-only window, **When** it is processed, **Then** the run is quiet and costs zero model tokens.
5. **Given** the digest, **When** an event group is shown, **Then** it is marked as a pod event (source kind) and shows the
   reason, the count, the number of distinct pods as a number, and the workload kind (Deployment, StatefulSet, Job) only.

---

### User Story 2 - Works alongside Loki or Datadog, per service and in a fleet (Priority: P1)

A service entry declares its main log source and, optionally, an extra `events` source. The service is analysed once,
with application groups and pod-event groups in one digest section set, under one budget and one schedule.

**Why this priority**: operators want one report per service, not a second job for the cluster.

**Independent Test**: fleet of two services, one with Loki only and one with Loki plus events; the second digest contains
both kinds of groups, the first is byte-identical to today; a failing events source degrades only the second service's
events part.

**Acceptance Scenarios**:

1. **Given** a service with `source` and `events`, **When** it runs, **Then** both sources are read for the same window,
   merged before grouping, tagged by source kind, and selected, analysed and reported by the existing rules.
2. **Given** a service without `events`, **When** it runs, **Then** nothing changes (no cluster call, identical output).
3. **Given** the events source is unavailable (auth, network, API error), **When** the run executes, **Then** the log part
   is reported normally and the digest carries a visible notice that pod events were not read, with the reason class; the
   exit code follows the existing partial-failure rule.
4. **Given** several services in one namespace, **When** each entry has its own label selector, **Then** an event is
   attributed to the service whose selector matches the involved object's labels, and an event matching none is not
   reported by any service (and counted in the coverage note).
5. **Given** a fleet, **When** two entries read the same cluster, **Then** the events list is fetched once per trigger and
   shared (one request per namespace per trigger), without sharing credentials across services that use different ones.

---

### User Story 3 - Read-only, minimal access, easy to verify (Priority: P2)

The Cluster administrator grants a role that can only list and get events and pods in the configured namespaces, and the
Operator verifies it with the existing `check` command.

**Why this priority**: Principle II; a watcher that can act in a cluster is an incident generator.

**Independent Test**: with a fake Kubernetes API recording every request, run the full matrix; assert only GET requests on
`events`, `pods` and (for ownership) `replicasets`/`deployments` metadata; `check` reports pass for a read-only account and
fail with the missing verb named for an account lacking list on events.

**Acceptance Scenarios**:

1. **Given** any run, **When** the cluster client is observed, **Then** it issues only GET (list/get) requests against
   events and pods (and owner metadata for workload names); no watch stream, exec, log, patch, delete, create or proxy.
2. **Given** an in-cluster service account or a kubeconfig/token with a CA bundle, **When** configured, **Then** either is
   accepted; the token and CA come only from the host's environment or configuration, are never logged, stored or sent.
3. **Given** `check` is run, **When** the events source is configured, **Then** it verifies API reachability, the TLS
   chain, list permission on events in each configured namespace, and that the account has no write verbs when the
   cluster exposes that (self-subject rules review), as pass/fail lines with reasons and without posting or spending.
4. **Given** documentation, **When** the role is described, **Then** it gives the minimal RBAC (`get`, `list` on `events`
   and `pods`, per namespace, `Role` not `ClusterRole` by default) and states the cluster-wide variant and its cost.

---

### User Story 4 - Namespace and label selection, bounded volume (Priority: P2)

The Operator selects namespaces and labels and caps the volume so a noisy cluster cannot flood a run.

**Why this priority**: clusters are shared; one service must read only its own part, with bounded work.

**Independent Test**: fixture with events in three namespaces and mixed labels; only matching ones are returned; cap and
truncation notice behave as for Loki.

**Acceptance Scenarios**:

1. **Given** `namespaces` and `label_selector` (equality and set-based selectors) in the entry, **When** events are listed,
   **Then** only events whose involved object (pod, or workload through its owner) is in a configured namespace and
   matches the selector are kept; selection happens in code even where the API filter is coarse.
2. **Given** an `involved_kinds` list (default: Pod, Deployment, ReplicaSet, StatefulSet, DaemonSet, Job,
   HorizontalPodAutoscaler), **When** events are listed, **Then** other kinds (Node, PersistentVolume, Service) are dropped
   unless listed; node events are off by default.
3. **Given** more events than the event cap, **When** the read stops, **Then** `lastReadTruncated()` is true and the digest
   says so, as for other sources.
4. **Given** an empty `namespaces`, **When** configuration is validated, **Then** it fails before any call naming the
   setting; there is no "all namespaces" default.

### Edge Cases

- Event TTL shorter than the trigger interval: `check` warns; the digest coverage note states possible loss.
- Events with `count` and `lastTimestamp` (core/v1) versus `series` (events.k8s.io/v1): both forms are read; `series.count`
  and `series.lastObservedTime` are used when present; the preferred API is `events.k8s.io/v1`, with `core/v1` fallback.
- A pod that no longer exists when its owner is resolved: the workload name falls back to the stripped pod name; a failed
  pod lookup never fails the run.
- Reason strings or messages unknown to the mapping: level Warning if event type is Warning, template masked as usual.
- Messages containing image references, registry hosts, node names, container IDs, secret or configmap names, user names
  in probe URLs, IPs: masked (see Privacy requirements).
- Clock skew between cluster and runner: the window is applied to `lastTimestamp` with a tolerance (default 2 minutes).
- Cluster API rate limiting (429 / priority and fairness): retried by the existing retry policy, then reported.
- A restart storm of thousands of events for one fingerprint: collapsed by dedupe, bounded by the cap.
- Multi-cluster: one entry targets one cluster; several clusters are several entries.
- Event messages written by third-party controllers with free text (admission webhooks, operators): treated as untrusted
  text and masked like log text; unknown fields are dropped.

## Requirements *(mandatory)*

### Functional Requirements

**Source**

- **FR-001**: The package MUST provide a Kubernetes events source implementing the existing `LogSource` contract, selectable
  by source driver, usable as a service's main source or as an extra `events` source beside another driver.
- **FR-002**: The source MUST read events through the Kubernetes API using a read-only in-cluster service account or an
  explicit API server URL, bearer token and CA bundle (kubeconfig-style settings), from configuration/environment only.
- **FR-003**: The source MUST issue only list/get requests on events and pods (and owner metadata needed to name the
  workload); it MUST NOT use watch, exec, logs, proxy, or any write verb, and an architecture test MUST fail if another
  verb or resource is added (Principle II).
- **FR-004**: Configuration MUST include non-empty `namespaces`, an optional `label_selector`, `involved_kinds`, a
  `critical_reasons` list, a `min_repeat_for_probe_failures` threshold, an event cap and a tolerance; invalid or missing
  settings MUST fail before any external call, naming the setting (Principle VI).

**Mapping**

- **FR-005**: Each event MUST map to one `LogEvent`: `time` = last occurrence; `level` per FR-006; `message` = reason plus
  masked message text; `errorType` = reason; `labels` = source kind `k8s-events`, workload kind and a masked workload name
  (the deployment-level name without pod suffix), namespace excluded unless allowlisted; `fields` = empty.
- **FR-006**: Level MUST be decided in code from event type and reason: Normal -> Info; Warning -> Warning; Warning with a
  reason in `critical_reasons` -> Error; probe failures (`Unhealthy`) escalate only when repeated at least the configured
  threshold within the window. The mapping MUST be a documented table, overridable by configuration.
- **FR-007**: Repeated events MUST be deduplicated across reads: the dedupe key is (involved object identity, reason,
  normalised message); the count added is the increase of `count` (or `series.count`) since the last read, using
  `lastTimestamp`/`lastObservedTime` to detect a new occurrence; reading the same unchanged event twice MUST add nothing.
- **FR-008**: `totals()` MUST return per-level counts of deduplicated occurrences for the window; `lines()` MUST honour the
  read filter (minimum level, watch patterns) and event cap; `lastReadTruncated()` MUST reflect the cap; `versions()`
  MUST return an empty list.

**Retention and ingestion**

- **FR-009**: When the events source is configured, each hourly trigger MUST ingest the events still present in the cluster
  into a per-service accumulator in the state store; every accumulator item MUST carry an expiry (default 30 days maximum,
  window-sized by default); raw event objects MUST NOT be persisted, only masked fingerprints, levels, the fully masked
  message text (at most 300 characters), counts, timestamps and one-way pod hashes (amendment A1).
- **FR-010**: The due-hour run MUST build its events from the accumulator plus a fresh read; the digest MUST carry a
  coverage note when triggers were missed for longer than the observed event retention or when the earliest event in the
  window is later than the window start by more than the tolerance.
- **FR-011**: The documentation MUST state that, where an event exporter already writes events to Loki or Datadog, that
  path is preferred and the native source is optional.

**Fleet**

- **FR-012**: A service entry MAY declare `events` beside `source`; both MUST be read for the same window and merged before
  grouping; a service without `events` MUST behave and produce output exactly as before.
- **FR-013**: A failure of the events source MUST NOT fail the log part; it MUST be reported as a visible notice (Principle
  VII) and follow the existing partial-failure exit rule.
- **FR-014**: In a fleet, one list per namespace per trigger MUST be shared by entries that use the same cluster
  credentials; attribution to a service MUST be by that entry's selector.

**Privacy (see section)**

- **FR-015**: Every event text MUST pass the Normalizer plus the Kubernetes rules in "Privacy requirements" before
  grouping; any parse error or unknown field MUST be dropped (Principle I).

**Operability**

- **FR-016**: `check` MUST verify reachability, TLS and list permission per namespace with GET requests only, and warn when an event
  exporter is also configured (amendment A3: write permission is not probed); `--dry-run` MUST read the cluster (reads are real) and write no state, including
  no accumulator update.
- **FR-017**: The `--json` summary and heartbeat MUST include the events source status (read, unavailable, not
  configured), event counts read and deduplicated, and truncation.

### Privacy requirements

**Allowed to leave the service** (the existing allowlist, applied to events): level, masked template, reason as error
type, workload kind, masked workload name, counts (occurrences, distinct pods as a number), timestamps. No new field class
is introduced, so no constitution amendment is needed.

**Masked or dropped before grouping** (deterministic rules, tested):

- Pod names: the generated suffix (replicaset hash and pod hash, or StatefulSet ordinal) is replaced by `<POD>`;
  `api-7d9f8c6b5-x2k4q` -> `api-<POD>`; bare pod names not matching a known workload are `<POD>`.
- Node names and node IPs: replaced by `<NODE>` / `<IP>`.
- Image references: registry host replaced by `<REGISTRY>` unless on an allowlist; tag and digest replaced by `<TAG>`;
  repository path kept only if allowlisted, otherwise `<IMAGE>`.
- Container IDs, UIDs, resource versions, secret / configmap / PVC / volume names and any name inside quotes: `<ID>` /
  `<STR>`; probe URLs and ports: masked whole by the existing URL rule, which removes host, port, path and query
  (amendment A2).
- Annotations, labels other than the selector keys, environment variables, command lines and arguments: never read into
  the event model; dropped at parse time.
- Namespaces: masked unless on an allowlist (default allowlist: the configured namespaces, shown as given by the Operator).

**Never sent**: raw events, event `metadata`, `managedFields`, annotations, pod specs, container commands or environment,
node names, registry credentials, service account tokens, or the kubeconfig content.

**Privacy corpus (mandatory, extended in the same change)**: events whose messages carry image references with private
registries and tags, node names and IPs, pod names with hashes, secret and configmap names, probe URLs with query tokens,
annotations with e-mails, and third-party controller free text; assertions that no seeded value reaches any analyzer or
notifier payload, summary, accumulator, state write or log, and that eight pod names collapse to one fingerprint.

### Key Entities

- **Cluster Event** — a Kubernetes event reduced to reason, type, masked message, involved kind, masked workload, count and
  last timestamp (never persisted raw).
- **Event Accumulator** — per-service, expiring store of deduplicated fingerprints with counts across hourly triggers.
- **Events Source Settings** — namespaces, selectors, involved kinds, critical reasons, caps, credentials reference.
- **Coverage Note** — the digest line stating possible event loss.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the recorded fixture covering CrashLoopBackOff, OOMKilled, ImagePullBackOff, failed probes, restarts, HPA
  metric errors and evictions, 100% of the seven problem classes yield a group with the right reason and level, and the
  audit's pod-level manual backlog items (the hand-made `backlog.md` list) are each reproduced from a fixture or a live run.
- **SC-002**: N pods of one workload (tested with N = 1, 8, 200) produce exactly 1 fingerprint per reason; 0 pod names, node
  names, image tags or registries (outside the allowlist) appear in any payload, summary, state or log on the privacy
  corpus.
- **SC-003**: Reading the same cluster state in 24 consecutive hourly triggers adds each occurrence once: the daily count
  equals the true count of occurrences within 1% on the replay fixture, and equals 0 added when nothing changed.
- **SC-004**: With `events` absent, a service's output, payloads and tokens are byte-identical to the previous release; 0
  cluster requests are made.
- **SC-005**: The request log of the fake API contains only GET on `events`, `pods` (and owner metadata) in 100% of the test
  matrix; 0 watch, write or exec requests (amendment A3: `check` does not probe the account's rights).
- **SC-006**: An events source failure leaves the log part of the digest identical to a run without the failure, plus one
  visible notice; exit code per the partial-failure rule (fault matrix: 401, 403, 404, 429, timeout, invalid JSON).
- **SC-007**: A quiet cluster (Normal events only) costs 0 model tokens; a window with only events costs no more than the
  existing per-group token budgets for the groups it produces.
- **SC-008**: A missed-trigger scenario (no trigger for longer than the event TTL) always produces a coverage note (100% of
  test cases), never a silent gap.

## Assumptions

- Default Kubernetes event TTL is one hour (API server `--event-ttl`); stated in documentation and checked by `check` where
  discoverable.
- Hourly triggering already exists (operations doc); the ingestion granularity is the trigger, not the daily run.
- Event exporter to Loki/Datadog is the preferred path when it exists; both coexist, and a team must not enable both for the
  same events (duplicate counting). Documentation warns; `check` warns when the same reason appears in both (best effort).
- The Kubernetes client is a small HTTP client over the cluster REST API using the package's existing HTTP and retry
  conventions; no cluster SDK dependency (decision for the plan).
- The `events.k8s.io/v1` API is available (Kubernetes 1.19+); `core/v1` events are the fallback.
- One service entry targets one cluster and a fixed set of namespaces; node-level and cluster-scoped events are out of scope
  by default.
- Reason classification lists are defaults and configurable; they are not a claim of completeness.
- Event messages are untrusted text and may be written by third parties; the masking rules apply as for logs.
- Hourly ingestion writes only masked fingerprints and counts with an expiry; this is within Principle I (no raw lines
  persisted).
- Remediation (restart, scale, delete a pod) is out of scope under Principle II.


## Owner decisions (2026-10-24)

1. Hourly ingestion into an expiring accumulator is accepted for clusters without an event exporter; an exporter into Loki or Datadog stays the preferred path and the native source optional.
2. The native source and an exporter must not both be enabled for the same events. The package cannot detect it; the docs say so and `watcher check` warns when both are configured for one service.
3. The default list of reasons that raise an event to Error is accepted: CrashLoopBackOff, OOMKilled, ImagePullBackOff and ErrImagePull, Evicted, FailedMount, FailedScheduling, and a failing probe with a restart count. The list is extendable in configuration.

## Amendments (2026-10-24, from planning and implementation; confirmed by the owner's "fix everything")

- **A1 (FR-009).** The accumulator also stores the fully masked message text and one-way pod hashes: the daily run must report
  text for an event the cluster forgot an hour after it happened, and distinct pods need counting. Same privacy class as a
  template; never raw.
- **A2 (privacy, probe URLs).** The Normalizer's URL rule is stricter than "path template kept" and fails closed; it wins. A
  probe's status code is masked by the number rule.
- **A3 (FR-016, SC-005).** The only way to learn an account's rights is a non-GET request (`SelfSubjectRulesReview`) or a GET of
  a forbidden resource, which FR-003 and Principle II forbid. `check` verifies read access only; the documentation gives the
  minimal RBAC. Allowing the review POST would need a constitution amendment, not assumed.
- **A4 (acceptance 2 vs Owner decision 3).** The Owner decisions list wins; `BackOff` and `Failed` are included because they
  are the real event reasons behind crash loops and pull errors.
- **A5 (Principle III, shared contract suite).** The shared `LogSource` suite assumes a per-line version label and a message
  equal to the log text; an events source has neither (`versions()` is `[]`, message is `Reason: text`). Its contract is run by
  `tests/Contract/KubernetesEventsSourceTest.php`, which keeps every other behaviour of the shared suite (lazy generator, minimum
  level, cap and truncation, failures without leaking credentials).
