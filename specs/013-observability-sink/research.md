# Research: Observability Sink

Only decisions where more than one design was reasonable. Facts are from the code as of 2026-10-24. Decisions marked
**(lead)** were taken before this pack was written; they are recorded, not re-opened, except where a concrete reason
is given.

## R1. Is an event sink a "notification channel" under the constitution?

- **Decision**: yes. Principle II allows "posting a message to its configured notifier and writing its own state". A sink
  is an operator-configured destination to which the package posts a message-equivalent (a run summary, findings in
  the digest's own words) and nothing else: it reads nothing back, changes nothing at the destination, and does not touch
  the monitored system. No amendment is needed. The same two conditions as for Telegram/Discord (spec 011) apply:
  (a) the content is the allowlisted content of the digest (Principle I), (b) the destination is validated before
  use (FR-017, spec 011's `Destination::invalidKey()` analogue).
- **Edge cases checked**: the file and Prometheus sinks write to the local filesystem. That is not "a write on a system
  other than the notifier or own state": the package writes only its own output, under a path the operator configured,
  with an expiry (FR-015, FR-018). The `http` sink must never be the monitored system: validation rejects a URL on the
  log source's host (FR-017). The package never writes to Loki, Elasticsearch or Datadog through its source
  credentials; those stay read-only (Principle II, second bullet).
- **Alternative**: ratify an amendment adding "event sinks" to Principle II. Rejected as unnecessary; a PATCH clarification
  (wording only: "notifier" includes event sinks) is offered as optional task T022 and open decision O1.

## R2. Contract and delivery shape

- **Decision**: one contract `EventSink::emit(list<array>): void` with four implementations (Principle VIII: the spec names
  more than one implementation). Events are built once per run, buffered, and flushed once at the end of the run
  (`Watcher::conclude`), in sink order, each sink isolated by `SinkDispatcher`.
- **Why**: a daily run emits 10 to 130 events; streaming each would multiply HTTP calls and make "failure of the sink
  after half the run" a state to reason about. One flush per sink gives one failure unit per sink.
- **Alternative**: reuse `Notifier` with a `Message` of kind `event`. Rejected: `Message` is text-for-humans with sections;
  events are structured, and forcing them through it would put JSON into renderers that escape for Slack.
- **Alternative**: Monolog handler. Rejected: the core is framework-agnostic and the host logger is PSR-3 only; a sink
  has to work with the plain CLI.

## R3. Events and metrics, not one or the other

- **Decision**: events are the source of truth; the Prometheus file is a derived, bounded summary of the same run.
- **Why**: Prometheus cannot carry finding text and must not carry fingerprints or titles as labels (cardinality); a log
  store carries text but is a poor alerting source for "no successful run" (absence of data). The owner needs both: a
  findings view (events) and reliable "it did not run" alerts (metrics, or `absent_over_time` on Loki).
- **Rejected: push to Loki / Elasticsearch directly.** (lead) It would need write credentials to the same stack the
  package reads from, and a second HTTP client per vendor. A log shipper already exists wherever a dashboard exists.
- **Rejected: Grafana annotations or dashboards API.** The package must not write into the dashboard product.

## R4. `include_text` defaults

- **Decision**: per-sink flag. Default **true** for `log`, **false** for `file`, `http` (and not applicable to
  `prometheus`).
- **Why true for `log`**: the lead's proposal, and the owner's first requirement is a dashboard "to view everything": with
  driver `none` and no text the dashboard would show counts but not what is wrong. The `log` sink writes to the host's
  own stdout/stderr, i.e. the same pipeline that already carries the application's logs, under the host's retention and
  access control; it adds no new recipient. The text is exactly what the digest would have shown in chat, after the
  same scrub.
- **Why false for `http` and `file`**: `http` sends to a recipient the Reviewer has not approved (a SaaS, another team),
  and `file` persists text on disk for up to `retention_days`; both are opt-in disclosures, surfaced by
  `config_warning include_text_external` for `http`.
- **Concrete reason to question the lead's default**: in dashboard-only mode with `log` default true the Reviewer can be
  surprised that container logs now hold finding text. Mitigations: documented in docs/privacy.md next to the chat
  section; the flag is one env var; the Reviewer's question is answered by "same words as the Slack message". Left as
  open decision O2.
- **What `include_text` never covers**: template, frames, fields, labels, raw lines (FR-041).

## R5. Dashboard-only mode: driver `none`, not `log`

- **Decision**: `notify.digest.driver: none`. The lead wrote "`log`/sink" as a placeholder.
- **Why**: `log` is already the name of a sink; a driver named `log` would suggest the sink is selected by the driver and
  that chat and sink are alternatives of one setting. They are independent: chat and sinks can both be on (events plus
  Slack), or sinks alone (`none`). `none` states what the driver does.
- **Why not `notify.digest.enabled=false`**: it skips the analysis (spec 012) and would leave only counters.
- **Rule**: `none` requires an enabled sink (silent watcher = Principle VII).

## R6. Events are built from typed objects, never from raw events

- **Decision**: `RunObservation` is filled from `Finding`, `RedactedGroup`, `RunStats`, `RouterResult`, spend values; the
  factory has no access to `LogEvent`. `EventSanitizer` still runs: allowlist per type, pattern per string, `Redactor`
  second scan for `error_type`, the output-scrub for finding text (already applied before the digest; re-applied because
  the cost is nil and the fail-closed rule is cheap).
- **Why**: Principle I's layer 1 is "copy exactly the allowlisted fields"; the same layer protects the sinks.

## R7. Prometheus: textfile, text format 0.0.4, state-backed counters

- **Decision**: write `<dir>/<name>.prom` for node_exporter's textfile collector (also read by Alloy's unix exporter and
  VictoriaMetrics `vmagent` scraping node_exporter). Classic text format 0.0.4, not OpenMetrics (the lead said "textfile/
  OpenMetrics"): the textfile collector does not accept OpenMetrics (`# EOF`, `_created`), and every Prometheus-compatible
  scraper reads 0.0.4. OpenMetrics stays an option for an HTTP endpoint, which this package cannot serve.
- **Why not Pushgateway**: it is an HTTP write to a service (a push destination the operator must run), keeps stale series
  forever, and gauges set once never expire; the textfile path is the standard for batch jobs on a host with node_exporter.
  An operator who has only a Pushgateway can post-process the file; not shipped.
- **Why not an exporter endpoint**: the package runs once and exits.
- **Counters**: `*_total` metrics need to survive process exit, so three are kept in the state store with `state.retention`
  expiry (Principle I) via `StateStore::increment`, already used for the weekly counters. All other metrics are gauges
  "of the last run"; Prometheus keeps the history.
- **Multi-host**: a CronJob pod has no node_exporter; the file must land on a volume read by one. Documented in
  docs/operations.md; the Loki path is the answer for pods.
- **Bounded cardinality**: closed label enumerations only; 48 series per service (contracts/dashboards.md). A test pins
  the catalog and fails on a new label.

## R8. `log` sink writes to stderr by default

- **Decision**: default `stderr`; `stdout` allowed; `--json` forces stderr for that call.
- **Why**: `watcher run --json` prints one parseable line on stdout and hosts read it (spec 002); interleaved event
  lines would break that contract. Kubernetes and Docker collect both streams, so a shipper loses nothing. (The lead said
  "stdout or stderr"; the default is the only thing decided here.)

## R9. When are sink failures surfaced?

- **Decision**: a failure in run N is visible immediately in the JSON summary (`sink_failures`) and the host log, and
  in the next run's `heartbeat` event warnings, `watcher status` and `watcher_sink_failures_total`.
- **Why not in run N's own heartbeat**: the batch is built before the flush, and a second flush would double the HTTP
  calls and still not cover the failing sink. For a daily run the delay is one day, which the immediate channels (summary,
  exit-visible stderr line, host log) cover. If the owner wants earlier, a second small flush to the *other* sinks is a
  compatible later change (open decision O4).
- **Not a failed run**: Principle VII requires a visible fallback, not a non-zero exit; the lead decided "a failing sink never
  fails the run".

## R10. HTTP sink protocol and address rules

- **Decision**: POST NDJSON (`application/x-ndjson`), optional auth header, at-least-once with `event_id` for dedupe.
  Retries: the existing `Retry::send($send, 'observe http', false)` (429, 502, 503, 504, network, 3 attempts, waits 1 s
  and 3 s), constructed with a 15 s total budget. `Retry::sendRateLimited` is for non-idempotent posts and would not
  retry 5xx; here a duplicate is harmless because events carry ids.
- **Webhook rules today** (`Destination::httpsWithHost`, notifiers): https with a non-empty host, no redirects
  (`allow_redirects => false`). There is no host allowlist or IP check for Slack webhooks; Discord is pinned by regex.
  The sink keeps those two and adds, because the URL is arbitrary rather than a vendor's: no userinfo, optional
  `allowed_hosts`, metadata addresses always refused, loopback/private/link-local only with `allow_private_network`,
  `http` only with `allow_insecure`, never the log source's host.
- **Residual risk, stated**: a hostname that resolves to a private address is not caught (no DNS resolution at validation
  time in v1); the URL is operator configuration, not attacker input, so this is defence in depth, not a boundary.
  Pinning the resolved IP in the Guzzle `curl` options is a possible hardening (open decision O5).
- **Token**: `auth_token` and `url` are registered secrets: masked by the same reason scrub as webhooks; exceptions are
  re-thrown as `SinkFailed('http: ' . class)` without the message, so a Guzzle exception (which contains the URL) never
  surfaces.

## R11. JSON Schema test dependency

- **Decision**: add `opis/json-schema` to `require-dev` only, to validate every emitted event against the draft 2020-12
  schema (FR-005). Reason in writing (Principle VIII): the schema is a published contract; a hand-written validator
  would test itself. Runtime dependencies are unchanged.
- **Alternative**: `justinrainbow/json-schema` (draft 4/6/7 only, no `unevaluatedProperties`). Rejected for that gap. If
  neither is acceptable, fall back to a strict array-shape check in `EventSchemaTest` and keep the file as documentation:
  weaker, and the owner should say so (O6).

## R12. Identifiers

- **Decision**: `run_id = bin2hex(random_bytes(8))`; `event_id = run_id-n`.
- **Why random**: it must not be derivable from log content, a window or a fingerprint (privacy) and must differ for a
  retry of the same window (a retry is a different attempt). The window is already in the envelope.

## R13. Which outcomes emit

- **Decision**: all but `disabled`, `invalid_config` and `skipped_not_due`. `skipped_done`/`skipped_locked` happen at
  most once or twice per window and tell the dashboard that triggers fire; `skipped_not_due` happens every hour.
  A heartbeat event is emitted whenever `Heartbeat::recordRun` ran (reported, quiet, baselined, failed).
- **Why heartbeat is separate from run**: `run` is what the run did; `heartbeat` is the liveness state the alert reads
  (age, staleness, sink warnings) and it follows the heartbeat flag of spec 012.

## R14. Group events and what they omit

- **Decision**: top N groups by count with fingerprint, level, trend, counts, times, watch-rule names, and (with
  `include_text`) the error class. No template, frames, or `err`+`frames` pair.
- **Why**: the digest does not print templates or frames (the renderer shows 12-character fingerprints, counts and
  trends); the lead's rule is "no free text beyond what chat gets". A template is the most useful field for a dashboard
  ("what is group 3?") and it is already sent to the model, so it is the likely next request: open decision O3, with the
  cost: templates in Loki/Elasticsearch for as long as the operator retains them.

## R15. Field naming

- **Decision**: `trend` instead of `status`; `environment` stays. See the compatibility section of
  contracts/event-schema.v1.md. Concrete reason: Datadog reserves `status` as log severity, Elasticsearch ECS owns
  `service` as an object.

## R16. OpenTelemetry

- **Decision**: later increment (lead). Reserved key rejected in v1. The envelope maps cleanly: `ts` -> time,
  `event_type` -> event name, `service` -> `service.name`, `environment` -> `deployment.environment.name`, `run_id` and
  `event_id` -> attributes, severity of findings -> `SeverityNumber`.

## R17. Dry run and `check`

- **Decision**: `--dry-run` prints events to stdout (as it prints the digest) and writes nothing, including counters.
  `check` is syntactic and local; `--post-test` sends one `config_warning check_test` event, consistent with spec 002's
  explicit-opt-in posting.
