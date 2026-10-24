# Implementation Plan: Observability Sink

**Branch**: `013-observability-sink` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: [spec.md](spec.md). Decisions: [research.md](research.md). Objects and state: [data-model.md](data-model.md).
Contracts: [contracts/event-schema.v1.md](contracts/event-schema.v1.md),
[contracts/watcher.event.v1.schema.json](contracts/watcher.event.v1.schema.json), [contracts/config.md](contracts/config.md),
[contracts/dashboards.md](contracts/dashboards.md). Scenarios: [quickstart.md](quickstart.md). Order: [tasks.md](tasks.md).

## Summary

Add a `Watcher\Observe` namespace that turns the typed results of a run (findings, kept groups, stats, budget, heartbeat
state, delivery failures) into versioned `watcher.event.v1` events and hands them, once per run, to enabled sinks:
`log`, `file`, `http`, `prometheus`. Add the notifier driver `none` for dashboard-only mode. Ship two Grafana
dashboards, alert rules and a mapping guide. The four core contracts (`LogSource`, `StateStore`, `Notifier`, `Analyzer`)
are unchanged; `EventSink` is a fifth contract justified by four implementations. The analysis path, the digest and
every existing default are untouched.

## Technical Context

**Language/Version**: PHP ^8.1. **Dependencies**: runtime none added (Guzzle already present); dev: `opis/json-schema`
(R11). **Storage**: existing state store, four new counter kinds with expiry (data-model.md); local files written by the
`file` and `prometheus` sinks. **Testing**: PHPUnit `Unit` / `Contract` / `EndToEnd`; Guzzle `MockHandler` for the
`http` sink; temp directories for files; no network. Gates: `composer test`, `composer stan`, `composer style`; read `$?`.
**Performance**: one flush per sink per run; worst case 15 s of retry wait; under 50 ms for the `log` sink with 100
events. **Egress added**: only the operator's own `observe.http.url`; documented in docs/operations.md.

## Constitution Check

| Principle | Result |
|---|---|
| I Privacy fails closed | Pass, with work. What can leave is a subset of the digest's allowlist; text is per-sink and off for external sinks (R4); no template, frames, fields, raw lines (FR-041); unknown or failing values are dropped (FR-042); files carry an expiry (FR-015). The privacy corpus is extended in the same change (T015, FR-043). The `http` URL and token are new secrets (SC-008). |
| II Read-only | Pass, by interpretation R1: an event sink is a configured notification channel plus the package's own output files. Never the monitored system (FR-017), never the log backend's credentials. Architecture test pins the allowed IO (T019). **No amendment**; optional PATCH wording T022, owner decides (O1). |
| III Test-first | Pass: every task is red then green; HTTP faked; a JSON Schema validation test, a privacy corpus test and a docs-drift test are mandatory. |
| IV Code decides | Pass: no model involvement; events built deterministically from typed objects; top-N by count then fingerprint; same input gives the same events except `ts`, `run_id`, `event_id`. |
| V Token/cost | Pass: no change in tokens or calls (SC-009). |
| VI Config/contracts | Pass: sinks, paths, URLs, thresholds, groups count all from config; secrets from env; validated once at start naming the key; framework-free; `EventSink` is the only new contract. |
| VII Failures loud | Pass: a failed sink is counted, summarised, logged and shown in the next heartbeat and `status` (R9); `none` without a sink is rejected; failed deliveries are events. |
| VIII Simplicity | Pass with notes. Four sinks and a dispatcher are what the owner's two requirements need. Rejected for size: OTLP (later), Pushgateway, a direct Loki writer, per-event streaming, configurable event types, a schema registry. New dev dependency has a written reason (R11). |

No unjustified violation; the complexity table is empty.

## Design in one page

1. **Collect** (`src/Watcher.php`): a `RunObservation` is created with the `RunStats`; `attempt()` hands it the findings, the
   kept groups, the router counters, budget values and cap/gap warnings at the points where the digest data is already
   assembled (no new reads, no new state reads except `Heartbeat::status()` after `recordRun`).
2. **Build** (`EventFactory`): `run`, `finding`, `group` (top N), `budget`, `heartbeat`, plus `delivery_failure`
   (caught `DeliveryFailed` at the three notifier call sites; only its code is used) and `config_warning`. Returns
   `list<Event>` with `run_id`, `event_id`, `ts`.
3. **Sanitize** (`EventSanitizer`): per sink, `Event::toArray($includeText)` then allowlist/pattern/scan; result is the array
   encoded by the sink.
4. **Flush** (`SinkDispatcher`, called in `Watcher::conclude` after `record()`): sinks in order `log, file, http,
   prometheus`; each in try/catch; failures counted into the persisted counters and returned as `sink_failures` for the
   JSON summary. The dispatcher never throws. In `--dry-run` it prints instead and persists nothing.
5. **Sinks**: `LogSink` (fwrite to `php://stderr|stdout`), `FileSink` (flock append, rotate, purge), `HttpSink` (Guzzle +
   `Retry`, URL guard), `PrometheusSink` (`MetricCatalog`, temp file + rename; reads counters through
   `ObserveCounters`).
6. **Driver `none`**: `Destination::none()`, `NoneNotifier`, `RoutingNotifier` dispatch; `WatchConfig` rejects extra keys
   and a missing sink.
7. **Config** (`WatchConfig`): `observe` section -> `ObserveSettings`; secrets list gains the URL and token; `Factory` builds
   the dispatcher with its own `Retry` (15 s budget) and a Guzzle client with `allow_redirects => false`.
8. **Fleet**: shared `observe` in defaults, per-service replacement, per-service Prometheus file.
9. **Operate**: `RunSummaryData`/`JsonSummary` gain `run_id` and `sink_failures` (additive keys); `HeartbeatStatus` and
   `watcher status` print sink warnings; `Doctor` gets the `observe` check and `--post-test` event.
10. **Docs and dashboards** as in contracts/dashboards.md.

## Files

| New | Modified |
|---|---|
| `src/Observe/{EventSink,Event,EventSchema,EventFactory,EventSanitizer,RunObservation,SinkDispatcher,SinkFailed,ObserveSettings,ObserveCounters,MetricCatalog,HttpTarget}.php`, `src/Observe/{LogSink,FileSink,HttpSink,PrometheusSink}.php`, **`src/Notify/NoneNotifier.php`** | **`src/Watcher.php`**, **`src/Config/WatchConfig.php`**, **`src/Config/Factory.php`**, **`src/Notify/Destination.php`**, **`src/Notify/RoutingNotifier.php`**, `src/State/Keys.php`, `src/Fleet/FleetConfig.php`, `src/Fleet/FleetRunner.php`, `src/Operate/{Doctor,RunSummaryData,JsonSummary,HeartbeatStatus}.php`, `src/Cli/*` (status line, dry-run print), `tests/Unit/ArchitectureTest.php`, `tests/Unit/PrivacyCorpusTest.php`, `composer.json`/`composer.lock` (dev), `config/watcher.php`, `config/watcher.example.php`, `docs/examples/*.php`, docs (configuration, privacy, operations, roadmap), `CHANGELOG.md` |
| tests under `tests/Unit/Observe/`, `tests/Unit/Config/ObserveConfigTest.php`, `tests/EndToEnd/ObserveTest.php`, `tests/EndToEnd/ObservePrivacyTest.php`, `tests/Unit/Docs/DashboardsDocsTest.php`, fixtures `tests/Fixtures/observe/` | |
| `docs/schema/watcher.event.v1.schema.json`, `docs/dashboards/*` | |

Bold = shared files that other specs and agents also edit: tasks that touch them are sequential and rebased on the then-
current file (see tasks.md header).

## Release

Additive configuration, every default off: **MINOR** release. CHANGELOG entry with an upgrade note ("none required").
The event schema is `v1` from this release; the file `docs/schema/watcher.event.v1.schema.json` is the published contract.

## Risks

| Risk | Handling |
|---|---|
| Text in container logs surprises the Reviewer (R4) | Documented beside the chat section; one env var; open decision O2 |
| A new field added to an event without schema/allowlist update | `EventSchemaTest` drift test; producer-strict schema in validation |
| Cardinality blow-up via a future label | `MetricCatalog` is closed; test fails on a label outside the catalog and on series > 48/service |
| `Watcher.php`, `WatchConfig.php`, `Factory.php` edited by other work in flight | sequential tasks, rebased on the current file; T001 re-reads them |
| Sink hangs a scheduled job | timeouts + 15 s retry budget; SC-011 test with fake clock |
| Counter state adds state-store round trips | at most 3 increments + 1 read per sink per run; `heartbeat` already does the same; skipped in dry run |
| Dashboard JSON drifts from the catalogs | `DashboardsDocsTest` (SC-010) |
