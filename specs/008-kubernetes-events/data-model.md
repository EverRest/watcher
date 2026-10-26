# Data Model: Kubernetes Events Source

## Observation (in memory, closed)

| Field | Type | Notes |
|---|---|---|
| key | string(16 hex) | ledger key, R6 |
| kind | string | involved kind, from `involved_kinds` |
| workloadKind | string | Deployment, StatefulSet, DaemonSet, Job, HorizontalPodAutoscaler, Pod |
| workload | string | masked workload name (no pod suffix) |
| reason | string | event reason or synthetic (`OOMKilled`, `ContainerRestarted`) |
| type | Normal\|Warning | event type; signals are Warning |
| text | string | EventMasker output, then digits -> `<N>`; at most 300 chars |
| count | int | `count` / `series.count` / signal count, at least 1 |
| first, last | DateTimeImmutable | `firstTimestamp`/`eventTime`, `lastTimestamp`/`series.lastObservedTime` |
| podHash | ?string(8 hex) | one-way pod id; null for non-pod objects |
| restarted | bool | the pod's `restartCount > 0` (for probe escalation) |

Never read: annotations, labels (except to evaluate the selector on the pod list), `managedFields`, env, args, commands.

## Ledger record (`evt:{service}`, TTL = retention)

```text
{ "v": 1,
  "last_ok": "2026-10-24T05:10:00Z",          // last successful ingest
  "since": "2026-10-23T05:10:00Z",            // first successful ingest still covered
  "gaps": [["2026-10-24T01:00:00Z","2026-10-24T03:10:00Z"]],   // capped at 20
  "unattributed": [[ts, n], ...],              // counts only, pruned like entries
  "e": { "<key16>": { "lvl":"error","rsn":"OOMKilled","wk":"Deployment","wl":"api","txt":"...masked...",
                       "pod":"a1b2c3d4","c":14,"t":"2026-10-24T05:09:11Z","f":"2026-10-24T03:00:02Z",
                       "d":[["2026-10-24T05:09:11Z",3]] } } }
```

Cap: 5000 entries (oldest by `t` dropped, counted into `gaps` as overflow) so a noisy cluster cannot grow the record without
bound. Contains no raw event, no pod name, node name, image, registry, annotation or credential.

## Window result (from the ledger)

`list<LogEvent>`: one per entry with in-window increments: `time` = newest in-window ts, `level`, `message` = `rsn . ': ' . txt`
(or `rsn` alone), `errorType` = reason when class-shaped else null, `frames` = [], `fields` = [], `labels` =
`{source: k8s-events, workload_kind, workload}` (informational), `origin`/`workloadKind` set explicitly, `occurrences` = in-window sum, `podId` = pod hash.

## LogEvent / ProblemGroup / RedactedGroup additions (defaulted)

`LogEvent`: `occurrences: int = 1`, `podId: ?string = null`, `origin: ?string = null` (only `k8s-events`), `workloadKind: ?string = null` (closed list `LogEvent::WORKLOAD_KINDS`); `labels` stay informational and are never trusted.
`ProblemGroup`: `origin: ?string = null`, `pods: ?int = null`, `workloadKind: ?string = null`.
`RedactedGroup`: same three; `toArray()` appends `src`, `pods`, `wk` only when `origin !== null`.

## Settings (`kubernetes.*`, validated at load)

`enabled` (flag), `api` {`in_cluster` bool | `server`, `token` or `token_file`, `ca_file`}, `namespaces` (non-empty list, DNS labels),
`label_selector` (string, optional), `involved_kinds` (default Pod, Deployment, ReplicaSet, StatefulSet, DaemonSet, Job,
HorizontalPodAutoscaler), `critical_reasons` (default list, D4), `min_repeat_for_probe_failures` (3), `event_cap` (5000),
`tolerance` (120 s), `event_ttl` (3600 s), `ledger_ttl` (seconds, default window + tolerance + 1 h, at most `state.retention`),
`allow` {`registries`, `images`}, `exporter` (bool, false).

## Digest and summary

Finding for a pod-event group: `reason`, `n×`, `k pods`, workload kind, marker `pod event`. Notices: events source unavailable
(reason class: `unauthorised`, `not found`, `rate limited`, `timeout`, `invalid response`, `unavailable`), coverage note.
`--json`: `events: {status: ok|unavailable|not_configured|disabled, read, deduplicated, unattributed, truncated}`; heartbeat gets
`events_status`.
