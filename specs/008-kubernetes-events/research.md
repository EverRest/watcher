# Research: Kubernetes Events Source

Decisions taken while planning. Each: decision, why, rejected alternative.

## R1. Client: Guzzle over the REST API, no SDK

**Decision.** `KubeApi` wraps `GuzzleHttp\ClientInterface` (already a dependency) and `Retry` (3 attempts, 429 honoured).
Paths are built from a closed list: `/apis/events.k8s.io/v1/namespaces/{ns}/events`, fallback
`/api/v1/namespaces/{ns}/events`, and `/api/v1/namespaces/{ns}/pods`. Query: `limit`, `continue` (the client can send `labelSelector`,
but the source does not: pods are listed per whole namespace and the selector is applied in code, so other services' pods are
known and a shared list is reusable across fleet entries). Credentials: in-cluster (`/var/run/secrets/kubernetes.io/serviceaccount/{token,ca.crt}`,
host and port from `KUBERNETES_SERVICE_HOST`/`_PORT`) or explicit `server`, `token`, `ca_file`; read from configuration/
environment only. TLS verify stays on (`verify` = CA path, never `false`; no `insecure` key exists).
**Why.** Constitution VIII and the spec's assumption: no new dependency; the surface is two list calls.
**Rejected.** `maclof/kubernetes-client` or `renoki-co/php-k8s`: large write-capable surfaces, a new dependency, and an
easy way to add a forbidden verb by accident.

## R2. Seven problem classes: events plus two pod-status signals

Kubelet emits events for most classes but **not** for an OOM kill of a container, and `CrashLoopBackOff` is a *state
reason* while the *event* reason is `BackOff`:

| Class | Source | Reason in the group |
|---|---|---|
| Crash loop | event `BackOff` ("Back-off restarting failed container") | `BackOff` |
| OOM kill | pod `status.containerStatuses[].lastState.terminated.reason == OOMKilled` | `OOMKilled` |
| Image pull | events `Failed` / `BackOff` with "pull" in the message, `ErrImagePull` | `Failed`, `ErrImagePull`, `ImagePullBackOff` |
| Probe failure | event `Unhealthy` | `Unhealthy` |
| Restarts | pod `containerStatuses[].restartCount` increase | `ContainerRestarted` (synthetic) |
| HPA error | event `FailedGetResourceMetric`, `FailedComputeMetricsReplicas` on a HorizontalPodAutoscaler | as is |
| Eviction | event `Evicted` / `Killing` with eviction message; pod `status.reason == Evicted` | `Evicted` |

Pod-status signals are mapped to the same `Observation`. `OOMKilled`: one occurrence per distinct `finishedAt`
(dedupe key includes it), so a window sees a lower bound when a container is killed several times between two reads;
`ContainerRestarted`: count = `restartCount`, delta by the ledger. State `waiting.reason` (`CrashLoopBackOff`,
`ImagePullBackOff`, `ErrImagePull`) is **not** turned into a signal: the matching events already exist and a state
has no occurrence count.
**Why.** SC-001 names OOMKilled and restarts; only the pod has them. Pods are already in the permitted read set.
**Rejected.** A `nodes` read (OOMKilling events live on Node objects): node scope is out of scope by default.

## R3. No owner lookups (no replicasets/deployments calls)

The workload name is derived from the pod's `ownerReferences` (kind + name) with the pod list we already read:
`ReplicaSet` `api-7d9f8c6b5` -> `Deployment api` (strip the trailing `-{hash}`), `StatefulSet`, `DaemonSet`, `Job` as is
(a `Job` named `x-28812345` of a CronJob loses the numeric tail). A pod missing from the list falls back to its name with
the generated suffix stripped (spec edge case). Two list calls per namespace per trigger, nothing else.
**Why.** Fewer verbs and resources to grant and to test (FR-003); the spec permits "owner metadata" but does not require it.
**Rejected.** `GET replicasets/{name}`: one request per pod owner, a third resource in the RBAC.

## R4. Attribution by selector

The `label_selector` selects **pods** (evaluated in code over the namespace's pod list, US4-1). The set of
workload names of selected pods is the entry's *selected workloads*. An event is attributed when its involved object is:
a selected pod; or a non-pod object (`Deployment`, `ReplicaSet`, `StatefulSet`, `DaemonSet`, `Job`,
`HorizontalPodAutoscaler`) whose name (ReplicaSet hash stripped) equals a selected workload; or a pod no longer listed
whose stripped name equals one. No selector: every event of `involved_kinds` in the configured namespaces. Events that
match nothing are counted as `unattributed` and the digest coverage note says so (US2-4). A deployment whose pods are all
gone has no selected workloads that run: its events are unattributed and counted, not hidden.

## R5. Masking order (EventMasker, then Normalizer)

1. Known identifiers from the same read, longest first: pod names -> `{workload}-<POD>` (or `<POD>`), node names -> `<NODE>`,
   container ids (`containerd://hex`, `docker://hex`) -> `<ID>`, UIDs -> `<ID>`.
2. Generated-name shapes without a known owner: `name-{5-10 alnum}-{5 alnum}` -> `name-<POD>`, `name-{digits}` after a
   `StatefulSet` -> `name-<POD>`.
3. Image references: the quoted string after `image`, or any token with `@sha256:` or `host/path:tag` -> `<REGISTRY>/<IMAGE>:<TAG>`;
   registry host kept only if in `allow.registries`, repository path only if in `allow.images`.
4. Every remaining quoted string -> `<STR>` (secret, configmap, volume, PVC, container names).
5. Configured namespaces stay as given (allowlist default); any other `ns/name` prefix -> `<NS>/name`.
Then the existing Normalizer (URLs -> `<URL>`, IPs, emails, tokens, digits) runs in the Reducer as for logs. Annotations,
labels, env, args, commands are never parsed (the mapper reads a closed field list). Order matters: step 1 uses names the
Normalizer would later mangle.

## R6. Dedupe and the ledger

Key: `sha256(namespace | kind | name | reason | message-with-digits-as-N)` truncated to 16 hex. Entry:
`{lvl, rsn, wk, wl, txt, pod, c, t, f, d:[[ts,n],...]}` where `c` is the highest count seen, `t` its last timestamp, `f`
the first, `d` the increments with their event timestamps. Rules:
- read `count`/`series.count` and `lastTimestamp`/`series.lastObservedTime`/`eventTime` (first non-null, else `firstTimestamp`);
- `c' > c` -> add `c' - c` at `t'`; `c' <= c` and `t' > t` (count reset by a recreated event object) -> add `c'`;
  otherwise nothing (unchanged re-read adds 0);
- first sight adds the whole current count (the cluster keeps at most the window it can; documented limit: a long-lived
  event object seen for the first time carries its older occurrences into that window).
Window replay: sum of `n` with `ts` in `[start - tolerance, end - tolerance)`. Shifting **both** ends by the tolerance keeps
consecutive windows a partition (no double count), and events written late by kubelet still land in the right window.
Entries are pruned when their newest `ts` is older than `window + tolerance + 1 h`; the record's TTL is that retention
(default 30 days is the cap, `kubernetes.ledger_ttl` overrides). `pod` is the first 8 hex of
`sha256(service | podName)`: one-way, only used to count distinct pods.
**Rejected.** Storing raw events (Principle I); one state key per entry (hundreds of writes per trigger).

## R7. Pass-through fields

`LogEvent` gets `occurrences = 1`, `podId = null`, `origin = null`, `workloadKind = null`. `origin` and `workloadKind` are
**dedicated, validated fields, never read from `labels`**: a Loki stream controls its own labels, so a label would let a log
line claim to be a pod event and carry free text into the model payload (found by the commit security review, fixed in the
same stage). `LogEvent` accepts `origin` only as `k8s-events` and `workloadKind` only from `LogEvent::WORKLOAD_KINDS` (a closed
list); `RedactedGroup` re-checks both on construction. `Reducer` adds `occurrences` instead of 1 and collects distinct `podId`s
(capped at 1000). `ProblemGroup`/`RedactedGroup` carry `origin`, `pods`, `workloadKind`; `RedactedGroup::toArray()` adds `src`,
`pods`, `wk` only when `origin !== null` (so every non-event payload stays byte-identical, SC-004). `TemplateMiner::add` weights a
message by `occurrences` for the "min events for text" rule (eight identical occurrences are as repeatable as eight identical
log lines). `kubernetes.involved_kinds` is limited to the same closed list.

## R8. Where it plugs into `Watcher`

Lane A is decomposing `Watcher.php`. All logic is in `Watcher\Events\EventsRun` (ingest at every trigger, merged stream,
notice text, coverage line, summary counters). `Watcher.php` keeps four small hunks: an optional constructor argument
`?KubernetesEventsSource $events = null` with `bindState()` after `$this->state` is final (dry run swallows writes because
the bound store is the `DryRunStateStore`); a call before the due gate; a `yield from` after the main source; and passing
the notice and coverage lines into `DigestData`. If Lane A moves the code, these four move with it.

## R9. Hourly ingest on a trigger that is not due

`RunGate::decide()` returns null off the due hour, before lock and state. `EventsRun::ingestOnTrigger()` runs just before
that return, only when events are configured and enabled, never in a dry run. It takes no lock (the ledger write is a
single `put` of a pruned record; two overlapping triggers merge the same deltas idempotently because deltas derive from
the highest count seen: the second writer re-reads and finds `c` already raised). A failure is logged to the host log
(class only), recorded as a gap fact in the ledger (`gaps: [[from,to]]`), and never changes the trigger result or exit code
(there is no digest to carry a notice; the next due digest shows the coverage note).

## R10. Coverage note triggers

(a) the last successful ingest is older than `event_ttl` (default 3600 s) at the next ingest -> a gap `[last_ok, now]`;
(b) the earliest ledger event in the window starts later than `window.start + tolerance` while the ledger has existed
shorter than the window (first days after enabling); (c) `unattributed > 0`; (d) any gap inside the window. The note is code-built
integers and times (`Pod events: possible gap 03:10-06:25 (no read within the cluster's event retention); 4 events matched no service`).

## R11. `check` for the native source and the exporter warning

`events` result: API reachable, TLS chain, `list events` and `list pods` per namespace (GET `limit=1`), event TTL hint
(`--event-ttl` is not exposed by the API; the hint is the age of the oldest listed event vs the ledger's observed gaps, and a
documented manual line, so `check` warns only on observation), and the exporter warning: `kubernetes.exporter: true`
or a main-source stream tag whose value matches `event[-_]?(exporter|router)|kube[-_]?events` while the events source is
enabled -> `warn: both native events and an exporter are configured for this service; they would count twice`.
No write detection (plan D3).

## R12. Fleet sharing

`Factory::kubeApi()` caches one `KubeApi` per `sha256(server|token|ca)`; `FleetRunner::fromConfig` passes one cache for all
entries, and `KubeApi` memoises successful list responses by path+query for 60 s on the injected clock, so two entries on
one cluster issue one list per namespace per trigger. Entries with different credentials get different clients and never
see each other's token.

## R13. Flag and master switch

`kubernetes.enabled` (default true). `enabled=false` at the top level still gates everything before any call. With
`kubernetes.enabled=false` the events source is not built: no request, no ledger key, digest unchanged; `check` prints
`events: skip (disabled)`. The flag joins `WatchConfig::FLAGS` as `kubernetes`; like all flags it can never touch redaction.
