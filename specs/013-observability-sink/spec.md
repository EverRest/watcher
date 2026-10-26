# Feature Specification: Observability Sink

**Feature Branch**: `013-observability-sink`

**Created**: 2026-10-24

**Status**: Implemented (T023, the live Loki check, is open)

**Input**: Owner request: a Grafana-style dashboard to see everything watcher finds while the chat notifications (Slack, Telegram, Discord) can be switched off; and it must be flexible: vendor-neutral, usable with Grafana, Kibana/OpenSearch, Datadog, Metabase, Superset or a custom tool, not Grafana-only.

## Overview

Today the only way to see what watcher found is a chat message. A reader cannot filter by service, see trends across weeks,
alert on "no successful run", or look at findings without a chat channel. This feature makes every run emit a small,
versioned, vendor-neutral **event stream** (`watcher.event.v1`) to operator-chosen **sinks**: a JSON-lines log
(for a log shipper such as Promtail, Alloy, Filebeat or Vector), a JSONL file, a generic HTTP endpoint, and a Prometheus
textfile. Dashboards are built on the stream by whatever tool the operator already has; the package ships two Grafana
dashboards, alert-rule examples and a field table for mapping to Kibana/OpenSearch and Datadog.

A sink can be the **only** channel (`notify.digest.driver: none`): analysis runs exactly as today, chat is silent, the
dashboard shows everything.

The package never talks to a dashboard product: it writes events and metrics; collecting, storing, retaining and drawing
them is the operator's stack.

**Actors**: Operator (configures sinks, owns the dashboard stack), Reader (opens the dashboard instead of the chat),
Host monitoring (Prometheus/Grafana alert rules on the metrics or the event stream), Reviewer (data-protection officer
who must approve what the new outputs contain).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Dashboard-only mode: see everything with chat switched off (Priority: P1)

The Operator sets `notify.digest.driver` to `none` and enables the `log` sink. A shipper already running on the host
collects the lines into Loki; the shipped Grafana dashboard shows runs, findings by severity, the findings' text, the
top groups and the budget. No chat message is posted.

**Why this priority**: it is the owner's first requirement and the smallest end-to-end slice (one sink, one driver).

**Independent Test**: run the end-to-end pipeline with fakes, driver `none`, log sink on: no notifier call, the captured
lines contain one `run`, the `finding` and `group` events, all valid against the JSON Schema, exit code and JSON summary as
without sinks.

**Acceptance Scenarios**:

1. **Given** driver `none` and the `log` sink enabled, **When** a run reports 3 findings over N groups, **Then** the sink
   receives exactly 1 `run`, 3 `finding`, up to `top_groups` `group`, 1 `budget` and 1 `heartbeat` event, and no chat
   notifier is called.
2. **Given** driver `none` and no sink enabled, **When** the configuration loads, **Then** it fails with
   `notify.digest.driver none needs at least one enabled observe sink` before any external call (a silent watcher is the
   failure Principle VII forbids).
3. **Given** driver `none`, **Then** the analysis, budgets, triage and escalation behave exactly as with `slack`
   (same model calls, same state writes); only the delivery differs.
4. **Given** `notify.digest.enabled` is false, **Then** analysis is skipped as today (spec 012) and only `run`, `group`,
   `budget` and `heartbeat` events are emitted; no `finding` events exist because none were produced. Dashboard-only mode
   needs `enabled: true` on the digest.
5. **Given** a failure notice would have been posted (source failed, analysis failed, budget exhausted), **Then** the same
   fact is in an event: `run` with `outcome: failed` and a short `reason` code, `budget` with `state: exhausted`.

---

### User Story 2 - One event format any tool can read (Priority: P1)

The Operator or a data team uses Kibana, Datadog, Metabase, Superset or a custom service. They get the same events over
a generic HTTP POST of NDJSON, or from a JSONL file, with a published JSON Schema and a field table.

**Independent Test**: validate every emitted event of a corpus run against `contracts/watcher.event.v1.schema.json`;
POST a batch to a faked endpoint and check the body, headers and retry behaviour.

**Acceptance Scenarios**:

1. **Given** the `http` sink, **When** a run ends, **Then** one POST (or more, `batch_size` events each) carries
   `application/x-ndjson`, one event per line, with the configured auth header; nothing else is sent.
2. **Given** the `file` sink, **When** a run ends, **Then** the events are appended to the JSONL file created with mode
   0600, which rotates by size or day and is purged after `retention_days`.
3. **Given** two sinks are enabled, **Then** both receive identical events (same `event_id`s), except for the
   `include_text` choice made per sink.
4. **Given** a consumer reads `schema`, **Then** `watcher.event.v1` is the contract: fields are never renamed or removed
   or change type inside v1; new optional fields may appear (consumers ignore unknown fields).
5. **Given** the endpoint answers 429/502/503/504 or the network fails, **Then** the sink retries within the bounded
   `Retry` helper and the run still ends with the same outcome and exit code.

---

### User Story 3 - Metrics and alerts for "it did not run" (Priority: P1)

Host monitoring uses Prometheus. The `prometheus` sink writes an exposition file that node_exporter's textfile
collector (or Alloy's unix exporter) serves. Alert rules fire when no run succeeded in 36 hours, when a run failed, when
the budget passes 90 %, on a new critical finding and on delivery failures.

**Independent Test**: parse the written file with the exposition parser of the test suite; assert names, types, labels,
the bound on series count and atomic replacement.

**Acceptance Scenarios**:

1. **Given** the sink is enabled, **When** a run ends, **Then** the file is replaced atomically (temporary file in the
   same directory, then rename); a reader never sees a half-written file.
2. **Given** any number of findings and groups, **Then** the number of series is bounded by services x outcomes x
   severities (plus the fixed small label sets in contracts/dashboards.md); no fingerprint, title, template, message or
   release is ever a label.
3. **Given** `watcher_last_success_timestamp_seconds`, **Then** the alert expression
   `time() - watcher_last_success_timestamp_seconds > 129600` is true after 36 hours without a successful run.

---

### User Story 4 - A broken sink never breaks the run (Priority: P2)

A full disk, an unreachable endpoint or a revoked token must not turn a good run into a failed one, and must not go
unnoticed.

**Independent Test**: fake sinks that throw, time out and return 500; assert exit code, outcome, state writes and chat
delivery are identical to a run without sinks, and the failure is counted and shown.

**Acceptance Scenarios**:

1. **Given** a sink throws on write, **Then** the other sinks still receive the events, the outcome and exit code are
   unchanged, and the host log gets `observe sink failed: <sink> (<exception class>)` (no message, no URL, no token).
2. **Given** a sink failed, **Then** the JSON summary carries `sink_failures: {"<sink>": n}` and the persisted failure
   counter increases.
3. **Given** a sink failed in an earlier run, **Then** the next `heartbeat` event carries the warning
   `sink_failed:<sink>` and `watcher status` prints it without changing its exit code.
4. **Given** a sink hangs, **Then** the run waits at most the sink's `timeout` plus the bounded retry budget (15 s total
   per flush), never longer.
5. **Given** the chat notifier fails (`DeliveryFailed`), **Then** a `delivery_failure` event records the channel and a
   status class, never the exception message.

---

### User Story 5 - What the events contain is as safe as the chat message (Priority: P1)

The Reviewer must be able to approve the sinks on the same grounds as the chat channel.

**Independent Test**: the privacy corpus run with all sinks enabled and `include_text` on and off; no seeded personal or
secret value appears in any sink output, and a schema/allowlist drift test fails if a field is added without being
declared.

**Acceptance Scenarios**:

1. **Given** `include_text` is false for a sink, **Then** its events carry no title, evidence, hypothesis, next step,
   check, related entries, error type or other text from the model or the logs; only enumerations, numbers, ids,
   timestamps and configured names.
2. **Given** `include_text` is true, **Then** the text fields are exactly those the digest shows (title, evidence,
   hypothesis, next step, check, related), after the same output scrub, and group `error_type` after the redactor's second
   scan; raw log lines, message templates, frames, field values and labels are never in an event.
3. **Given** an unknown field, a value that fails the scan or an unparseable value reaches the event builder, **Then** it
   is dropped, never passed through (Principle I).
4. **Given** `include_text` is true on an `http` sink, **Then** a `config_warning` event with code
   `include_text_external` is emitted so the dashboard shows that text leaves the host; it is allowed, not silent.

---

### User Story 6 - Shipped dashboards and mappings (Priority: P3)

The Operator imports `docs/dashboards/grafana-loki.json` or `grafana-prometheus.json` and the alert examples, or follows
the field table to build the same in Kibana/OpenSearch or Datadog.

**Acceptance Scenarios**:

1. **Given** the dashboards and rules in `docs/dashboards/`, **Then** every metric name and every event field they use
   exists in the catalog of contracts/dashboards.md (a test fails on drift).
2. **Given** a Kibana or Datadog user, **Then** docs/dashboards/mapping.md gives the field table, a pipeline example and
   example queries; no vendor dashboard is shipped in v1.

---

### User Story 7 - OpenTelemetry later (Priority: P3, not in v1)

An `otlp` sink (logs and metrics over HTTP, JSON encoding) is reserved. In v1 the key is rejected with
`observe.otlp is not available yet`; the events and metric names are designed so that mapping to OTLP needs no change
to the schema.

## Edge Cases

- No sink configured and driver `slack`: nothing changes (SC-001); no sink code runs.
- `enabled` false (spec 012): no sink is touched, no event is emitted, not even a heartbeat event.
- `skipped_not_due`: no events (an hourly trigger would drown the stream). `skipped_done` and `skipped_locked` emit one
  `run` event; `disabled` and `invalid_config` emit none (no sink is built from an invalid config).
- `--dry-run`: no sink writes anything and no counter is stored; the events are printed to stdout after
  `== DRY RUN events ==`, as the digest is.
- `--json` summary on stdout plus a `log` sink with `stream: stdout`: the sink writes to stderr for that invocation so the
  summary stays one parseable line; documented.
- Fleet: each service emits its own events with its `service` and `environment`; Prometheus writes one file per
  `service@environment`; fleet-level events (correlation) are not in v1.
- A run that fails before a window exists: `run` event with null window and counters.
- Two processes writing the same file sink: appends are `flock`ed; rotation takes the same lock.
- Clock skew: `ts` is the host clock at flush; the dashboard uses `window_end` for run time and `ts` only for ordering.
- Very many groups: only the top `top_groups` (default 20, max 100) by count are emitted as `group` events;
  `run.groups` carries the total, so the dashboard shows what was left out.
- A finding that spans several fingerprints: `fingerprint` is the first, `fingerprints` lists up to 5 (12 characters each).
- The same run retried in the same window gets a new `run_id`; consumers dedupe by `event_id`, not by window.

## Requirements *(mandatory)*

### Functional Requirements

**Event stream**

- **FR-001**: Every emitted event MUST be a single JSON object with `schema: "watcher.event.v1"`, an `event_type` from
  `run | finding | group | budget | heartbeat | delivery_failure | config_warning`, `event_id`, `ts`, `service`,
  `environment`, `run_id`, `window_start`, `window_end` (see contracts/event-schema.v1.md).
- **FR-002**: Field names are stable `snake_case`; timestamps are ISO-8601 UTC with a `Z` suffix and second precision
  (`2026-10-24T06:05:00Z`); numbers are JSON numbers; absent values are `null`, not omitted, for the envelope.
- **FR-003**: `run_id` is 16 random hex characters generated once per run (not derived from any log content, window or
  fingerprint); `event_id` is `<run_id>-<n>` with `n` a 1-based counter per run.
- **FR-004**: A run emits exactly one `run` event; one `finding` event per finding; up to `observe.top_groups` `group`
  events; one `budget` event when spend data exists; one `heartbeat` event when `heartbeat.enabled`; `delivery_failure`
  and `config_warning` events as they occur (contracts/event-schema.v1.md lists the triggers).
- **FR-005**: The JSON Schema `contracts/watcher.event.v1.schema.json` is shipped in the package (as
  `docs/schema/watcher.event.v1.schema.json`) and every event the code builds MUST validate against it (test).
- **FR-006**: The code allowlist of fields per event type and the schema MUST agree; a drift test fails when either gains
  a field alone.

**Sinks**

- **FR-010**: Sinks are `log`, `file`, `http`, `prometheus`; `otlp` is reserved and rejected. Each is configured under
  `observe.<sink>` and is **off** unless `observe.<sink>.enabled` is true. With none enabled and driver not `none` the
  package behaves as before this feature.
- **FR-011**: Events of a run are buffered in memory and flushed once at the end of the run, to each enabled sink,
  in sink order `log, file, http, prometheus`. A sink receives its own copy (text fields removed where `include_text` is
  false).
- **FR-012**: A sink failure of any kind (exception, write error, non-2xx after retries, timeout) is caught, counted per
  sink, logged to the host log by class name only, and MUST NOT change the outcome, the exit code, the digest or any state
  other than the failure counter.
- **FR-013**: Sink failures are visible: `sink_failures` in the JSON summary, `warnings` of the next `heartbeat` event,
  the `watcher_sink_failures_total` metric and `watcher status`.
- **FR-014**: The `log` sink writes one event per line, UTF-8, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no
  other text, to `stderr` (default) or `stdout`. The package itself never writes to Loki, Elasticsearch or any other
  store through the `log` sink.
- **FR-015**: The `file` sink appends JSON lines under `flock`, creates the file with mode 0600 and its directory with
  0700 when missing, refuses a symlink target, rotates by `size` or `daily`, keeps at most `max_files` and deletes files
  older than `retention_days` (default 30; Principle I: every persisted item expires).
- **FR-016**: The `http` sink POSTs NDJSON with `Content-Type: application/x-ndjson` in batches of at most `batch_size`
  events, with an optional auth header, a `timeout` (default 5 s, max 30 s) and no redirects followed. It retries
  429/502/503/504 and network errors through the existing bounded `Retry` helper with a total wait budget of 15 s per
  flush. The token and the URL never appear in an error, a log line, `check` output or a `config_warning`.
- **FR-017**: The `http` URL MUST be `https` with a host and no userinfo; `http` only with
  `allow_insecure: true` (and then a `config_warning` `http_insecure_allowed`); loopback, private and link-local
  addresses only with `allow_private_network: true`; the cloud metadata addresses (169.254.169.254, fd00:ec2::254) are
  never allowed; when `allowed_hosts` is set the host MUST be in it; the host MUST NOT equal the configured log-source
  host (watcher never writes to the system it monitors).
- **FR-018**: The `prometheus` sink writes the classic text exposition format (0.0.4) to `<dir>/<name>.prom`, atomically
  (temporary file in the same directory, `rename`), mode 0644 by default (numbers only), with HELP and TYPE lines.
  Metric names and labels are those of contracts/dashboards.md; the label set is closed; a fingerprint, title, template,
  message, release or run id is never a label.
- **FR-019**: Counters (`watcher_runs_total`, `watcher_sink_failures_total`, `watcher_delivery_failures_total`) are kept
  in the state store with an expiry equal to `state.retention`, so they survive between runs; a reset after expiry is a
  normal Prometheus counter reset.
- **FR-020**: Every sink option is settable from the config array and from `WATCHER_OBSERVE_*` variables
  (contracts/config.md); invalid values fail at load naming the key path, never the value; secrets (`auth_token`) come
  from the environment and are masked everywhere.

**Dashboard-only mode**

- **FR-030**: `notify.digest.driver` accepts `none` (and `notify.notice` inherits it): a notifier that accepts and drops
  every message. Everything else about the run is unchanged.
- **FR-031**: Driver `none` requires at least one enabled sink (FR-010); `watcher check` reports the notifier as
  `none (dashboard-only)` and checks the sinks syntactically.
- **FR-032**: Each fact a chat message carries has an event counterpart (table in contracts/event-schema.v1.md), so a
  reader of the dashboard misses nothing a reader of the chat would see.

**Privacy and safety**

- **FR-040**: `include_text` is a per-sink flag, default **false** for every sink (prometheus has no text). *Owner decision (review of Lane G, 2026-10-24): `log` was
  first specified as true (research.md R4); privacy first, it is now false and text is always an explicit opt-in.*
- **FR-041**: With `include_text` true the text fields are the digest's own (title, evidence, hypothesis, next step,
  check, related) taken after the output scrub, plus group `error_type` after the redactor's second scan. No template,
  frame, field, label or raw line is ever included, whatever the flag.
- **FR-042**: Events are built from the typed objects the digest already uses (`Finding`, `RedactedGroup`, `RunStats`,
  budget values), never from raw `LogEvent`s; an `EventSanitizer` drops fields outside the per-type allowlist and
  strings that fail the redactor scan.
- **FR-043**: The privacy corpus (Principle I) is extended in the same change to run the whole pipeline with all four
  sinks enabled and assert that no seeded value appears in any sink output or any failure message.
- **FR-044**: `--dry-run` writes no file, sends no request, stores no counter (FR-019) and prints the events to stdout.
- **FR-045**: No config key disables redaction or the sanitizer (architecture test, as in spec 012).

**Docs and artifacts**

- **FR-050**: `docs/dashboards/` ships `grafana-loki.json`, `grafana-prometheus.json`, `grafana-alerts.yaml`
  (provisioning), `prometheus-rules.yaml` and `mapping.md` (Kibana/OpenSearch and Datadog field table and example
  queries), as described in contracts/dashboards.md.
- **FR-051**: docs/privacy.md, docs/operations.md and docs/configuration.md document the sinks, the retention being the
  operator's (Loki, Elasticsearch, Prometheus), and the flag defaults.

### Key Entities

- **Event**: an immutable JSON object of one of seven types (data-model.md).
- **Sink**: a destination for a run's event batch (`log`, `file`, `http`, `prometheus`); contract `EventSink`.
- **SinkDispatcher**: fans a batch out to the enabled sinks, isolates and counts failures.
- **RunObservation**: the per-run collector of findings, groups and budget values the events are built from.
- **Metric catalog**: the closed list of metric names and label sets (contracts/dashboards.md).

## Success Criteria *(mandatory)*

- **SC-001**: With no sink enabled and driver unchanged, outputs, state and the JSON summary are byte-identical to the
  previous release (test: golden comparison; the only permitted difference is the additive `run_id` key, absent when no
  sink is enabled).
- **SC-002**: A run with 3 findings over N groups emits exactly 1 `run`, 3 `finding`, `min(N, top_groups)` `group`,
  1 `budget`, 1 `heartbeat`; every event validates against the JSON Schema; `event_id`s are unique and sequential.
- **SC-003**: With the seeded privacy corpus (emails, tokens, UUIDs, IPs, a person's name, a URL with credentials) as log
  content and `include_text` on and off, none of the seeded values appears in any sink output, `check` output, host log or
  failure message (zero occurrences).
- **SC-004**: With `include_text` false, every string value in every event is an enumeration member, a hex id, a
  timestamp, a configured name (service, environment, watch-rule name) or a short code matching a pattern of the schema;
  the test walks all events of the corpus run and fails on any other string.
- **SC-005**: A sink that throws, times out or returns 500 leaves the exit code, outcome, digest and non-counter state
  unchanged (test per sink), and the failure is visible in the JSON summary and the next heartbeat event.
- **SC-006**: The textfile holds at most 48 series per service (S x 48; the closed list is in contracts/dashboards.md),
  whatever the number of findings and groups; a corpus run with 5 000 groups and 500 findings stays within the bound, and
  no label value is a fingerprint, title, template, release or run id. The bound is services x (outcomes x 2 + severities
  x 2 + trends + token directions + sinks + channels + 14 fixed).
- **SC-007**: The textfile is replaced atomically: a concurrent reader loop during 1 000 rewrites never observes a
  truncated file or a mix of two runs.
- **SC-008**: The `http` sink never leaks the token: across every failure path (DNS, TLS, 401, 500, timeout) the
  token and URL appear in no exception message, log line, summary or event (test per path).
- **SC-009**: With driver `none`, a corpus run makes the same analyzer calls and state writes as with `slack` and zero
  notifier calls.
- **SC-010**: Every metric name and event field referenced in `docs/dashboards/*` exists in the catalogs (test); the two
  Grafana dashboards parse as JSON and carry `schemaVersion`.
- **SC-011**: Adding a sink adds no more than 15 s to a run in the worst case (all retries exhausted) and under 50 ms
  with the `log` sink for a 100-event batch (test with a fake clock for the former, a micro-measure for the latter).

## Assumptions

- The Operator already runs a collector for the chosen path (a log shipper for `log`/`file`, node_exporter or Alloy for
  `prometheus`, an HTTP receiver for `http`). The package ships none.
- Loki, Elasticsearch, Prometheus and Datadog retention is the Operator's; the package only bounds its own files.
- An event sink counts as a notification channel under the constitution (plan.md, Constitution Check); no amendment is
  needed. The owner confirms (open decision O1).
- Text in events is the same content the chat channel shows, so a sink with `include_text` carries the same disclosure
  as the chat; an `http` endpoint outside the Operator's boundary is the Operator's decision, flagged by
  `include_text_external`.
- OTLP is a later increment; the schema does not depend on it.
- Dashboards are provided for Grafana only because the owner asked for a Grafana-style view; the stream is the
  product, the dashboards are examples.

## Owner decisions (2026-10-24)

O1 an event sink is a notification channel (no constitution amendment; same allowlist, destination validation, `http` never targets the log-source host); the optional T022 wording change is dropped. O2 `include_text` defaults to false for every sink. O3 group events omit the masked template. O4 a sink failure is not written to the same run's heartbeat. O5 resolved-IP pinning for `http` is out of v1 (documented limit). O6 `opis/json-schema` is allowed as a dev dependency for the schema contract test. O7 the dashboard-only driver is named `none`. O8 Prometheus text format 0.0.4, not OpenMetrics.
