# Kubernetes events (spec 008)

Pod-level problems never reach an application log: a container killed for memory, a crash loop, an image that cannot be
pulled, a failing probe, an autoscaler that cannot read metrics, an evicted pod. The cluster reports them as **events**.
watcher can read them, read-only, and put them in the same digest as the log findings of the same service.

## Native source or event exporter?

| | Event exporter -> Loki/Datadog (preferred) | Native source (this page) |
|---|---|---|
| Needs | an exporter running in the cluster | a read-only account, nothing installed |
| Retention | whatever your log backend keeps | the cluster keeps events about **one hour**; watcher keeps a masked ledger |
| Setup in watcher | none: point `source.stream_tags` at the exporter's stream | `kubernetes` + `events` |

**Never enable both for the same events**: they would be counted twice. watcher cannot detect that; `watcher check`
warns when `kubernetes.exporter` is `true` or the log stream tags look like an event exporter.

## Configuration

```php
'kubernetes' => [
    'api' => ['in_cluster' => true],        // or ['server' => 'https://...' (http only with 'allow_insecure' => true on a trusted network), 'token_file' => '/run/secrets/k8s-token' (or 'token' => env), 'ca_file' => '/path/ca.crt']
    'namespaces' => ['shop'],               // required, no "all namespaces"
    'label_selector' => 'app in (api,bff)', // optional: which pods (and so which workloads) belong to this service
],
'events' => ['driver' => 'kubernetes'],     // beside `source`: read these events too
```

All keys, defaults and errors: `specs/008-kubernetes-events/contracts/config.md`. Flag: `kubernetes.enabled`
(`WATCHER_KUBERNETES_ENABLED`, default on). `source.driver: kubernetes` makes pod events the only source.

## Minimal RBAC

```yaml
kind: Role            # one per namespace; a ClusterRole only for several namespaces and then reads cluster-wide
rules:
  - apiGroups: [""]
    resources: ["pods"]
    verbs: ["get", "list"]
  - apiGroups: ["", "events.k8s.io"]
    resources: ["events"]
    verbs: ["get", "list"]
```

watcher only ever sends `GET` to events and pods (a test fails if any other verb or resource appears). `watcher check`
reads one item of each per namespace; it **cannot** see extra rights the account may hold, so grant exactly this.
TLS is always verified; the token and CA come only from your environment or configuration and are never logged or stored.

## The hourly trigger is required

The cluster forgets an event about an hour after it last changed. Trigger watcher **at least hourly** (as the operations
guide already advises): every trigger, due or not, merges the events still in the cluster into a per-service ledger in your
state store, and the daily run reads the day from it. A gap longer than `kubernetes.event_ttl` (default 3600 s, the
cluster's `--event-ttl`, plus ten minutes of slack for cron jitter) is stated in the digest ("possible gap 03:10-06:25"), never silent. A cluster with a shorter
`--event-ttl` than your trigger interval will lose events: set `event_ttl` to the real value.

If the cluster cannot be read at the due hour, the digest is still built from what the hourly reads collected and says "the
latest read failed (<reason>)"; only a ledger that has never been filled gives "Pod events were not read". `ledger_ttl` must be
at least `event_ttl` + 3600 s (the record would expire between hourly triggers); the default keeps the window plus a day.

## What you get

- Level is decided in code: `Normal` is Info (dropped by `source.read_min_level`); `Warning` is Warning, raised to Error for
  `critical_reasons` (default `CrashLoopBackOff, BackOff, OOMKilled, ImagePullBackOff, ErrImagePull, Failed, Evicted,
  FailedMount, FailedScheduling`); `Unhealthy` (a failing probe) is raised only after `min_repeat_for_probe_failures`
  (3) occurrences or when the pod has restarted.
- `OOMKilled` and container restarts come from the pod status (the kubelet emits no event for an OOM kill); an OOM count is a
  lower bound when a container is killed several times between two reads.
- N pods of one workload are one group: `api-7d9f8c6b5-x2k4q` becomes `api-<POD>`; Secret, ConfigMap, key, volume and image names in kubelet text are masked.
- Pods of the namespace are listed whole and the selector is applied in code, so one list serves every service of a fleet.
- A finding shows `pod event: <reason> · <n> pods · <workload kind>`; pod, node and image names are never shown.
- Events from objects the label selector does not select belong to other services and are ignored; an event of a pod that no
  longer exists and matches no selected workload is counted in the coverage note.

## What is stored and sent

See [privacy.md](privacy.md#kubernetes-events-spec-008). In short: masked text, counts, times and one-way pod hashes, all with
an expiry; never a raw event, name, image, annotation or credential.

## Safety limits of the cluster client

Redirects are never followed (the token goes to the configured API server only); in-cluster mode needs a readable
`ca.crt` next to the token and otherwise reads nothing (no fallback to the public CA store). A response above 16 MiB is refused,
a list stops at 20 pages or 64 MiB (and the digest says the read was truncated), pods are listed 200 per page, and one ingest makes
at most 60 requests in two minutes with short retry waits, because every trigger reads the cluster before the run gate. Give the
process enough `memory_limit` for a whole namespace of pod objects. Overlapping triggers merge under a short lock; the one that
does not get it reads the same cluster and merges nothing.

## Limits

- An event object or pod that already existed before the ledger's previous read (for example everything on the first install) is credited with one occurrence and its count becomes the baseline; only increases are counted afterwards. `restartCount` is a lifetime total and is handled the same way.
- Pod events do not take part in `level_anomaly` or in the baseline totals; those are computed from the log source only.
- `OOMKilled` is a lower bound; restarts are the pod's `restartCount`.
- Third-party controllers write free text into events. Known verdict phrases (admission webhooks) are cut; other free text is
  treated like log text (masked by pattern, withheld from the model when it occurs fewer than `min_group_events_for_text` times).
- Node-level and cluster-scoped events are off unless `Node` is added to `involved_kinds`.
- One entry reads one cluster; several clusters are several entries.

## Limits that bound a trigger

Every trigger reads the cluster before the run gate, so that read is bounded by the run's own guard: it stops at `schedule.run_deadline`
(or the fleet's time share) and at the memory stop, as well as at its own request and two-minute budget. The response cap is the one
every backend has (`ResponseLimit`): a declared `Content-Length` over `source.max_response_mb` aborts at the headers, and the decoded
bytes are counted while they stream in, so a chunked reply or a gzip bomb is cut at the cap and never held in memory.
When Kubernetes is the main source, the coverage facts and a failed latest read appear in the digest's read-quality line.

## `token_file` and the Secret / projected-volume layout

`kubernetes.api.token_file` may be a symlink, as every Kubernetes Secret volume and projected service-account token is
(`token -> ..data/token`, `..data -> ..<timestamp>`). The rule is the same when the config loads and every time the token is read, so
rotation (the kubelet swaps `..data` atomically) works without a restart: the path is resolved with `realpath`, and the REAL target must
be a regular file inside the directory of the configured path (a link out of that directory, or through `..`, is refused), owned by the
user running watcher or by root, not group- or world-writable, and at most 16 KB. The opened handle is checked again (`fstat`: same file,
same rules), so a swap between the check and the read cannot slip another file in. A refusal is `credential unreadable`; the path is never
shown.

A reply over 1 MB is spooled by PHP to a private temporary file (mode 0600) while it is read and removed when the request ends; keep `sys_get_temp_dir()` on storage you trust. Connection refused or an unresolvable name is reported as `unreachable`, not `timeout`.

## Size of the stored record

The pod-event ledger is a compact record: every event keeps its hourly increments as one flat string (`ts:n;ts:n`), at most 48 of them, so an entry is one small object. Whatever the cluster size the encoded record is kept to half of the Redis store's budgets (2 MiB, 8 000 containers, 50 000 elements, counted on the JSON text); entries that do not fit are dropped, least recently seen first, and counted: the digest says "the event record was full: the oldest events were dropped (N entries, ledger_overflow)" and `ReadDiagnostics` carries `k8s_record_full`. A state store that refuses the value anyway makes the run `degraded` (`k8s_ingest_failed:unavailable`), never a quiet day. A record written by an earlier build is read as empty once.
