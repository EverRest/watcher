---

description: "Task list for Observability Sink (everrest/watcher)"
---

# Tasks: Observability Sink

**Input**: `specs/013-observability-sink/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Tests**: REQUIRED (Constitution III). In each task the test is written first and must fail first; the implementation
turns it green. No test touches the network (Guzzle `MockHandler`, temp directories, fake clock and sleeper).

**Format**: `[ID] [P?] [Story] Description`. Paths relative to the repository root. `[P]` = different files, no unfinished
dependency, safe to run in parallel with other `[P]` tasks of the same phase.

**SHARED FILES, SEQUENTIAL** (other specs and agents edit these; run these tasks one at a time, in this order, each
rebased on the then-current file; re-read the file before editing):

| Order | Task | Shared file(s) touched |
|---|---|---|
| 1 | T010 | `src/Notify/Destination.php`, `src/Notify/RoutingNotifier.php`, new `src/Notify/NoneNotifier.php` (**src/Notify/***) |
| 2 | T011 | **`src/Config/WatchConfig.php`** |
| 3 | T012 | **`src/Config/Factory.php`** |
| 4 | T014 | **`src/Watcher.php`**, `src/Operate/RunSummaryData.php`, `src/Operate/JsonSummary.php`, `src/State/Keys.php` (via T013) |
| 5 | T016 | `src/Fleet/FleetConfig.php`, `src/Fleet/FleetRunner.php` |
| 6 | T017 | `src/Operate/Doctor.php`, `src/Operate/HeartbeatStatus.php`, `src/Cli/*` |

Nothing else may edit `src/Watcher.php`, `src/Config/Factory.php`, `src/Config/WatchConfig.php` or `src/Notify/*` in this
spec. `src/Observe/*` is new and owned by this spec.

**Gate command**: `composer test; echo $?` then `composer stan; echo $?` then `composer style; echo $?`. Read the exit codes.

**No commits, pushes or tags** unless the owner asks. `composer.lock` changes only in T001.

---

## Phase 0: Gate

- [x] T001 Run the gate command on the current tree and record the exit codes. Re-read `src/Watcher.php`,
  `src/Config/WatchConfig.php`, `src/Config/Factory.php`, `src/Notify/Destination.php` (other work is in flight on them).
  Add `opis/json-schema` to `require-dev` (R11, if the owner approved O6) and commit nothing. Create the empty
  `tests/Fixtures/observe/` and `tests/Unit/Observe/` directories. Files: `composer.json`, `composer.lock`. (FR-005)

## Phase 1: Pure building blocks (all `[P]`, new files only, no shared file)

- [x] T002 [P] [US2] `tests/Unit/Observe/EventSchemaTest.php` (red): the schema file
  `docs/schema/watcher.event.v1.schema.json` parses; its property names per event type equal `EventSchema`'s allowlists
  (drift test, FR-006); a fixture event of each of the 7 types validates; an event with an unknown property, a bad
  timestamp (`+00:00`), a 13-character fingerprint or a `status` field fails. Then `src/Observe/EventSchema.php` (constants:
  types, per-type fields, enumerations, patterns) and copy `specs/013-observability-sink/contracts/watcher.event.v1.schema.json`
  to `docs/schema/watcher.event.v1.schema.json`. (FR-001, FR-002, FR-005, FR-006)
- [x] T003 [P] [US1] `tests/Unit/Observe/EventFactoryTest.php` (red): from a fixed `RunObservation` (3 findings, 40
  groups, budget, heartbeat status, one `DeliveryFailed` with a 429 and one with a network error) the factory returns
  1 run + 3 finding + `top_groups` group + 1 budget + 1 heartbeat + 2 delivery_failure events; counts by severity and
  trend; top-N ordering (count desc, fingerprint asc); `event_id` sequence; `run_id` is 16 hex and differs between two
  factories; `environment` is `unset` when null; no group beyond `top_groups`; budget `state` table (0.89 -> ok, 0.9 ->
  high, 1.0 -> exhausted, triage-only -> exhausted). Then `src/Observe/{Event,EventFactory,RunObservation}.php`. Reads
  `Finding`, `RedactedGroup`, `RunStats`, `RouterResult`, `HeartbeatStatus`; modifies none of them. (FR-001..FR-004, FR-032)
- [x] T004 [P] [US5] `tests/Unit/Observe/EventSanitizerTest.php` (red): unknown key dropped; string failing its pattern
  becomes null/dropped; `include_text=false` removes title/evidence/hypothesis/next_step/check/related/error_type;
  `include_text=true` keeps them after the `Redactor` scan (a seeded email in `error_type` is masked or the field
  dropped); length caps (80/240/240/200/200/3x80); an exception from the scanner drops the field, never passes it. Then
  `src/Observe/EventSanitizer.php` (uses `Watcher\Privacy\Redactor`). Depends on T002. (FR-040..FR-042)
- [x] T005 [P] [US1] `tests/Unit/Observe/LogSinkTest.php` (red): one JSON object per line, UTF-8, unescaped slashes and
  unicode, `schema` key first, no other output, stream selectable (injected stream resource), write error -> `SinkFailed`.
  Then `src/Observe/{EventSink,SinkFailed,LogSink}.php`. (FR-014)
- [x] T006 [P] [US2] `tests/Unit/Observe/FileSinkTest.php` (red): created with 0600 and directory 0700, appends under
  `flock`, size rotation at `max_bytes` keeps `max_files`, daily rotation by file name, files older than `retention_days`
  deleted, symlink target refused, relative or `..` path refused, unwritable directory -> `SinkFailed`, no leftover
  temp files. Then `src/Observe/FileSink.php` (injected clock). (FR-015, Principle I expiry)
- [x] T007 [P] [US2] `tests/Unit/Observe/HttpTargetTest.php` and `HttpSinkTest.php` (red). Target rules: https only,
  host required, no userinfo, `allowed_hosts`, metadata addresses always refused, loopback/private only with the flag,
  `http` only with `allow_insecure`, host equals source host refused. Sink: NDJSON body, `Content-Type`, auth header and
  scheme variants, batching at `batch_size`, retry of 429/502/503/504/network through `Retry::send` with a fake sleeper and
  a 15 s budget, no redirect followed, 4xx other than 429 not retried; the URL and token are absent from every
  exception message, log line and `SinkFailed` on DNS/TLS/401/500/timeout paths (SC-008). Then
  `src/Observe/{HttpTarget,HttpSink}.php`. (FR-016, FR-017)
- [x] T008 [P] [US3] `tests/Unit/Observe/PrometheusSinkTest.php` and `MetricCatalogTest.php` (red): exposition format
  0.0.4 (HELP, TYPE, escaping of label values), metric names/types/labels equal the catalog of
  contracts/dashboards.md, at most 48 series per service for a 5 000-group / 500-finding run, no label value outside the
  closed enumerations (fingerprint, title, release, run_id never), atomic replace (temporary file in the same directory,
  rename; a reader loop of 1 000 rewrites never sees a partial file), mode from config, unwritable directory ->
  `SinkFailed`, omitted metrics when values are unknown, `watcher_last_success_timestamp_seconds` kept from the previous
  file when the run failed (read back from the counters, not the file). Then `src/Observe/{MetricCatalog,PrometheusSink}.php`. (FR-018, SC-006, SC-007)
- [x] T009 [US4] `tests/Unit/Observe/SinkDispatcherTest.php` (red): sink order; one sink throws, the others still receive
  the batch; `dispatch()` never throws (including `Error`); per-sink `include_text` produces different bodies from one
  batch with identical `event_id`s; returns `array<sink,int>` failures; total wait bounded by the retry budget with a fake
  clock (SC-011); dry run prints and calls no sink. Then `src/Observe/SinkDispatcher.php`. Depends on T003-T008 interfaces. (FR-011, FR-012)

## Phase 2: Notifier driver `none` and configuration (SEQUENTIAL, shared files)

- [X] T010 [US1] **Touches `src/Notify/*`.** `tests/Unit/Notify/NoneNotifierTest.php` and additions to the routing and
  destination tests (red): `Destination::none()` has driver `none`, no accessor returns a value, `invalidKey()` is null,
  serialisation still refuses; `RoutingNotifier` sends nothing for `none` and does not call another notifier; `sameAs`.
  Then `src/Notify/NoneNotifier.php`, `src/Notify/Destination.php`, `src/Notify/RoutingNotifier.php`. (FR-030)
- [X] T011 [US1] **Touches `src/Config/WatchConfig.php`.** `tests/Unit/Config/ObserveConfigTest.php` (red): all sinks off by
  default; strict booleans naming the key path; every key and range of contracts/config.md; every `WATCHER_OBSERVE_*`
  variable via `Env`; `notify.digest.driver: none` accepted, extra keys rejected, a missing sink rejected with the exact
  message; `otlp` rejected; the http URL rules including "not the log source host"; secrets (`url`, `auth_token`) added to
  `notifySecrets()` and never in a message; `include_text` defaults (log true, others false); `top_groups` range. Then
  `WatchConfig` builds `ObserveSettings` (`src/Observe/ObserveSettings.php`). Rebase on the current file first. (FR-010, FR-020, FR-030, FR-031)
- [X] T012 [US2] **Touches `src/Config/Factory.php`.** Additions to `tests/Unit/WatcherFromConfigTest.php` (red): enabled sinks
  are built into a `SinkDispatcher` with a `Retry` of 15 s budget and a redirect-free Guzzle client; no sink enabled
  builds nothing; the `none` driver yields the `NoneNotifier` route. Then `Factory`. Rebase on the current file first. (FR-010, FR-016)

## Phase 3: State counters and the run (SEQUENTIAL from T014)

- [X] T013 [US4] `tests/Unit/Observe/ObserveCountersTest.php` (red; runs against the shared state-store contract suite
  like heartbeat counters): runs per outcome, sink failures per sink, delivery failures per channel; every key has the
  `state.retention` expiry; unreadable store -> counters read as unknown, never throw; dry run writes nothing. Then
  `src/Observe/ObserveCounters.php` and the four kinds in `src/State/Keys.php` (`OBSRUNS`, `OBSFAIL`, `OBSDELIV`, `OBSSEEN`;
  re-read `Keys.php` first). (FR-019, Principle I expiry)
- [X] T014 [US1] **Touches `src/Watcher.php`, `src/Operate/RunSummaryData.php`, `src/Operate/JsonSummary.php`.**
  `tests/EndToEnd/ObserveTest.php` (red, written before any edit to `Watcher`): `testEventCountsForThreeFindings` (26
  events, SC-002), `testDashboardOnlyModeEmitsEventsAndNoChat`, `testDriverNoneMakesSameAnalyzerCallsAsSlack` (SC-009),
  `testNoSinksIsByteIdentical` (SC-001), `testDisabledEmitsNothing`, `testSkippedNotDueEmitsNothing`,
  `testSkippedDoneEmitsRunOnly`, `testDryRunPrintsEventsWritesNothing`, `testSinkFailureDoesNotChangeOutcomeOrExitCode`
  (per sink; SC-005), `testSinkFailureAppearsInNextHeartbeat`, `testDeliveryFailureEvent`, `testConfigWarnings`
  (include_text_external, caps, gap), `testHeartbeatFlagOffEmitsNoHeartbeatEvent`, `testDigestDisabledEmitsNoFindings`.
  Then `Watcher`: create `RunObservation` next to `RunStats`; feed it where digest data is assembled; catch
  `DeliveryFailed` at the notifier call sites (code only); in `conclude()` after `record()` build events, dispatch, count
  failures; `RunSummaryData`/`JsonSummary` gain `run_id` and `sink_failures` (additive, only when a sink is enabled).
  Rebase on the current file first. (FR-004, FR-011..FR-013, FR-044, SC-001, SC-002, SC-005, SC-009)
- [X] T015 [US5] `tests/EndToEnd/ObservePrivacyTest.php` and the extension of `tests/Unit/PrivacyCorpusTest.php` (written
  red together with T014's tests, finished after T014): the privacy corpus run with the four sinks, `include_text` off and
  on; zero occurrences of any seeded value in log lines, the file, the HTTP body, the `.prom` file, the host log, the JSON
  summary, `check` output and every `SinkFailed` message (SC-003); with text off, every string is an enum, hex id,
  timestamp, configured name or schema-pattern code (SC-004); templates, frames and field values never appear;
  `http` + `include_text` emits `include_text_external`. Fixes land in `EventSanitizer`/`EventFactory`. (FR-040..FR-043)

## Phase 4: Fleet, operability, architecture (SEQUENTIAL where marked)

- [X] T016 [US2] **Touches `src/Fleet/FleetConfig.php`, `src/Fleet/FleetRunner.php`.** Tests in `tests/Unit/Fleet/` (red):
  shared `observe` in defaults, per-service replacement as a whole, `prometheus.name` rejected in a fleet, one file per
  service, error prefixes (`services[1] (bff-a): ...`), `digest: combined` works with driver `none` only when a sink is
  on, each service's events carry its `service`/`environment`. Then the two classes. (FR-020, edge case Fleet)
- [X] T017 [US4] **Touches `src/Operate/Doctor.php`, `src/Operate/HeartbeatStatus.php`, `src/Cli/*`.**
  `tests/Unit/Operate/DoctorObserveTest.php`, `tests/Unit/Cli/StatusSinkWarningTest.php`,
  CLI tests for `--json` forcing stderr (red): `observe` check per sink (local, no request), `notifier: none
  (dashboard-only)`, `--post-test` flushes one `check_test` event and stores nothing, failure details name the sink and
  key only; `watcher status` prints `warning: sink_failed:<sink>` and keeps its exit code; `--json` + log sink on stdout
  writes to stderr; `--dry-run` prints `== DRY RUN events ==`. Then the three areas. Do after T014. (FR-013, FR-031, FR-044)
- [X] T018 [P] [US4] `tests/Unit/ArchitectureTest.php` and `tests/Unit/FeatureFlagsArchitectureTest.php` additions (red
  where applicable): `src/Observe/*` imports nothing from `Watcher\Source\*` sources' write paths and no `LogEvent`;
  the only IO in `Observe` is files under the configured paths, `php://stderr|stdout` and POST to the configured URL; no
  config key disables the sanitizer; no `Observe` class reads `$_ENV`/`getenv`; no sink catches `Throwable` and hides it
  from the dispatcher's counters. Pure test additions, then fix what they find. (FR-017, FR-045, Principle II)

## Phase 5: Docs, dashboards, release (mostly `[P]`)

- [X] T019 [P] [US6] `tests/Unit/Docs/DashboardsDocsTest.php` (red): the JSON dashboards parse and have `schemaVersion`; every
  metric name in `docs/dashboards/*` is in `MetricCatalog`; every `| json` field used in LogQL is in `EventSchema`; the
  YAML rule files parse; `mapping.md` mentions every field. Then `docs/dashboards/grafana-loki.json`,
  `grafana-prometheus.json`, `prometheus-rules.yaml`, `grafana-alerts.yaml` per contracts/dashboards.md. (FR-050, SC-010)
- [X] T020 [P] [US6] `docs/dashboards/mapping.md`: field table (backend types), Alloy/Promtail, Filebeat, Vector and Datadog
  Agent snippets, KQL and Datadog example queries, Metabase/Superset notes; docs/dashboards/README.md (import steps). No
  vendor dashboards (v1). Checked by T019. (FR-050)
- [X] T021 [P] [US5] Docs: `docs/configuration.md` (observe table, env vars, driver `none`), `docs/privacy.md` (new "what is
  sent to your sinks" section, retention is the operator's, `include_text`), `docs/operations.md` (dashboard-only mode,
  textfile on pods, shipper egress, failure visibility), `docs/README.md` index, `README.md` feature list. (FR-051)
- [~] T022 DROPPED (owner decision O1, 2026-10-24: an event sink is a notification channel; no constitution amendment). Was: only if the owner confirms O1, a PATCH edit to `.specify/memory/constitution.md` (1.0.0 -> 1.0.1,
  wording: "notifier" in Principle II includes configured event sinks); otherwise record the interpretation in
  CHANGELOG and docs/privacy.md and skip. Needs the owner's yes; do not self-approve.
- [ ] T023 **HUMAN** (config files, the env-variable test and the `WATCHER_DIGEST_DRIVER` switch are DONE on branch feat/observe-g-rebased; still open: the live Loki/Alloy/Grafana walk-through in `docs/smoke-test.md`, which needs a real stack) Config files: `config/watcher.php`, `config/watcher.example.php`, `docs/examples/*.php` (all four `observe` blocks,
  every sink off), `docs/smoke-test.md` (Loki/Alloy/Grafana live walk-through of quickstart S1-S3). The live walk-through
  needs a real stack and an owner run, like T114 of spec 001; mark done only after it. (FR-020)
- [X] T024 Gates and release prep: run the gate command and read each exit code; walk SC-001..SC-011 with the test names in
  quickstart.md; `CHANGELOG.md` entry (MINOR, "none required" upgrade note, schema `watcher.event.v1` announced); update
  `docs/roadmap.md` status to Implemented. No commit, tag or push.

---

## Dependencies

- T001 first. T002..T008 are independent (`[P]`); T004 needs T002; T009 needs T003..T008.
- T010 -> T011 -> T012 -> T014 (shared files, in this order); T013 before T014; T015 after T014.
- T016 after T011/T012; T017 after T014; T018 can run any time after T009.
- T019..T021 after the catalog and schema exist (T002, T008); T023 after T011; T024 last.

## Coverage

| Requirement | Tasks |
|---|---|
| FR-001..FR-006 events and schema | T002, T003, T014 |
| FR-010..FR-013 sinks, flush, isolation | T005..T009, T012, T014 |
| FR-014..FR-019 per-sink behaviour | T005, T006, T007, T008, T013 |
| FR-020 config | T011, T016, T023 |
| FR-030..FR-032 dashboard-only | T010, T011, T017 |
| FR-040..FR-045 privacy and safety | T004, T015, T018 |
| FR-050, FR-051 docs and dashboards | T019..T021 |
