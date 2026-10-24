# Data Model: Observability Sink

Event shapes are in [contracts/event-schema.v1.md](contracts/event-schema.v1.md); config shapes in
[contracts/config.md](contracts/config.md). This page lists the objects behind them.

## Objects (new, namespace `Watcher\Observe`)

| Object | Kind | Role |
|---|---|---|
| `RunObservation` | mutable collector (like `RunStats`) | Filled by `Watcher` during the run: findings (`list<Finding>`), kept groups (`array<string, RedactedGroup>`), budget values (spent, caps, thresholds, triage-only), `RouterResult` counters, warnings (cap hits, gaps). Numbers and typed objects only; no raw event. |
| `EventFactory` | pure builder | `RunObservation` + `RunSummaryData` + `HeartbeatStatus` + delivery failures -> `list<Event>`. Allocates `run_id`, `event_id`, `ts`. |
| `Event` | immutable value | `type`, `fields` (assoc array), `text` (assoc array kept apart); `toArray(bool $includeText): array`. |
| `EventSchema` | constants | Per-type allowlists of field names, enumerations and patterns, the source of truth the sanitizer uses; compared with the JSON Schema file in a test. |
| `EventSanitizer` | pure | Drops unknown keys, replaces a string failing its pattern with null (or drops a text field), runs text through the `Redactor` second scan and the output-scrub rules; returns the array to encode. |
| `EventSink` | contract (4 implementations) | `emit(list<array> $events): void`; throws on failure. |
| `LogSink`, `FileSink`, `HttpSink`, `PrometheusSink` | sinks | See contracts/config.md. |
| `SinkDispatcher` | service | Holds `list<name => EventSink + includeText>`; `dispatch(list<Event>): array<string,int>` returns failures per sink; never throws. |
| `MetricCatalog` | constants | Closed list of metric names, types, help and label sets (contracts/dashboards.md). Used by `PrometheusSink` and by the docs drift test. |
| `ObserveSettings` | value | Parsed `observe` config (inside `WatchConfig`). |

`Watcher\Notify\NoneNotifier` (new): implements `Notifier`, `send()` returns; `Destination::none()` carries driver `none`.

## State (existing store, new keys, all with expiry)

| Key (`Keys::` kind) | Value | TTL | Used for |
|---|---|---|---|
| `obs:<service>:runs:<outcome>` (`Keys::OBSRUNS`) | `{total}` via `StateStore::increment` | `state.retention` | `watcher_runs_total` |
| `obs:<service>:sinkfail:<sink>` (`Keys::OBSFAIL`) | `{total}` | `state.retention` | `watcher_sink_failures_total`, heartbeat warning, `watcher status` |
| `obs:<service>:delivfail:<channel>` (`Keys::OBSDELIV`) | `{total}` | `state.retention` | `watcher_delivery_failures_total` |
| `obs:<service>:sinkseen:<sink>` (`Keys::OBSSEEN`) | `{total}` snapshot of fail total at the last clean run | `state.retention` | clears the heartbeat warning after a clean flush |

`<service>` is the existing state-key prefix (`service@environment`). No event, finding or group text is ever stored.
Dry run writes none of these.

## Files written by sinks

| Sink | File | Mode | Expiry |
|---|---|---|---|
| `file` | `observe.file.path` (+ rotated `.1`..`.N` or `-YYYY-MM-DD`) | 0600 (dir 0700) | `max_files`, `retention_days` (default 30) |
| `prometheus` | `<dir>/<name>.prom`, `<name>` = `watcher_<service>[_<environment>]` sanitised to `[a-z0-9_]` | 0644 default, configurable | overwritten every run; carries `watcher_metrics_written_timestamp_seconds` so a stale file is detectable |

## Derivations

- `severity` counts and `groups_by_trend` come from `RouterResult->findings` and the kept `RedactedGroup`s.
- `state` of `budget`: `max(usd_ratio, token_ratio) >= 1 or triage_only` -> exhausted; `>= 0.9` -> high; else ok.
- `age_seconds`, `last_ok_at`, `state` of `heartbeat` come from `Heartbeat::status()` after `recordRun`.
- `top_groups`: sort kept groups by `count` desc, then fingerprint asc (deterministic), take N; `rank` is the position.
