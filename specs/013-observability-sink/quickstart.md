# Quickstart: Observability Sink

Scenarios to run by hand against a real stack (the live check is task T023) and the test each one maps to. All tests use
fakes and temp directories; none touches the network.

## S1. Dashboard-only with Loki (US1, SC-002, SC-009)

1. Config: `WATCHER_ENABLED=1`, `WATCHER_DIGEST_DRIVER=none`, `WATCHER_OBSERVE_LOG_ENABLED=1`.
2. Run `php vendor/bin/watcher run --now=...` in a container whose stderr Alloy/Promtail ships to Loki (snippet in
   docs/dashboards/mapping.md; labels `service`, `environment`).
3. Open the imported `grafana-loki.json`: runs, findings by severity, findings table, top groups, cost, budget.
4. Check Slack/Telegram/Discord received nothing.

Tests: `tests/EndToEnd/ObserveTest::testDashboardOnlyModeEmitsEventsAndNoChat`,
`::testDriverNoneMakesSameAnalyzerCallsAsSlack` (SC-009), `tests/Unit/Config/ObserveConfigTest::testNoneNeedsASink`,
`tests/Unit/Observe/EventSchemaTest::testCorpusEventsValidateAgainstSchema`.

## S2. Prometheus alerts (US3, SC-006, SC-007)

1. `WATCHER_OBSERVE_PROMETHEUS_ENABLED=1`, `WATCHER_OBSERVE_PROMETHEUS_DIR=/var/lib/node_exporter/textfile`.
2. Run; `cat` the `.prom` file; `curl :9100/metrics | grep ^watcher_`.
3. Load `prometheus-rules.yaml`; stop the job for 37 h (or lower the rule's threshold) and see `WatcherNoSuccessfulRun36h`.

Tests: `tests/Unit/Observe/PrometheusSinkTest::{testFormat,testSeriesBoundForLargeRun,testLabelsAreClosed,testAtomicReplace,testNoFingerprintOrTitleLabel}`,
`tests/Unit/Docs/DashboardsDocsTest::testRulesUseCatalogMetrics`.

## S3. Generic HTTP receiver (US2, SC-008)

1. Run any receiver that accepts POST (a request bin, Vector `http_server` source, Logstash `http` input).
2. `WATCHER_OBSERVE_HTTP_ENABLED=1`, `..._URL=https://ingest.example.com/watcher`, `..._AUTH_TOKEN=...`.
3. Run; the receiver gets NDJSON, one event per line; kill the receiver and run again: exit code unchanged, JSON summary shows
   `sink_failures`, next run's heartbeat event carries `sink_failed:http`.

Tests: `tests/Unit/Observe/HttpSinkTest::{testPostsNdjsonBatches,testAuthHeader,testRetriesTransientThenGivesUp,testNoRedirect,testTokenNeverInErrors}`,
`tests/Unit/Observe/HttpTargetTest` (URL rules), `tests/EndToEnd/ObserveTest::testSinkFailureDoesNotChangeOutcomeOrExitCode`,
`::testSinkFailureAppearsInNextHeartbeat`.

## S4. File sink for Filebeat / data team (US2)

1. `WATCHER_OBSERVE_FILE_ENABLED=1`, `..._PATH=/var/log/watcher/events.jsonl`.
2. Run twice; `ls -l` shows mode `-rw-------`; set `..._MAX_BYTES=1048576` to see rotation; `retention_days` removes old files.

Tests: `tests/Unit/Observe/FileSinkTest::{testMode0600,testAppendsUnderLock,testRotatesBySize,testRotatesDaily,testPurgesOldFiles,testRefusesSymlink}`.

## S5. Privacy (US5, SC-003, SC-004)

1. Feed the privacy corpus (emails, tokens, UUIDs, IPs, a person's name in free text, a URL with credentials) as log lines.
2. Enable all four sinks; run once with `include_text` off and once on for each.
3. `grep` the outputs for every seeded value: zero hits; with text off every string is an enum, id, timestamp or short code.

Tests: `tests/EndToEnd/ObservePrivacyTest::{testNoSeededValueInAnySink,testNoTextWhenIncludeTextFalse,testExternalTextWarning}`,
`tests/Unit/PrivacyCorpusTest` (extended), `tests/Unit/Observe/EventSanitizerTest`.

## S6. Failure isolation (US4, SC-005, SC-011)

1. Point `observe.file.path` at a read-only directory and `observe.http.url` at a closed port; enable both plus `log`.
2. Run: outcome and exit code as without sinks, `log` still got its events, summary lists both failures.

Tests: `tests/Unit/Observe/SinkDispatcherTest::{testOneSinkFailsOthersContinue,testNeverThrows,testRetryBudgetBounded}`,
`tests/EndToEnd/ObserveTest::testSinkFailureDoesNotChangeOutcomeOrExitCode`.

## S7. Defaults and flags (SC-001, spec 012 pattern)

1. With no `observe` config: golden comparison of output, state and summary against the previous release.
2. With `WATCHER_ENABLED` unset: nothing is emitted, no file created.
3. `--dry-run` with sinks on: events printed to stdout, no file, no request, no counter.

Tests: `tests/EndToEnd/ObserveTest::{testNoSinksIsByteIdentical,testDisabledEmitsNothing,testDryRunPrintsEventsWritesNothing,testSkippedNotDueEmitsNothing}`,
`tests/Unit/Config/ObserveConfigTest::{testAllSinksOffByDefault,testStrictBooleans,testEnvVars}`,
`tests/Unit/FeatureFlagsArchitectureTest` (no key disables the sanitizer).

## S8. Event counts (SC-002)

A fake run with 3 findings and 40 groups, `top_groups = 20`: 1 run + 3 finding + 20 group + 1 budget + 1 heartbeat = 26
events, unique sequential `event_id`s, every event schema-valid.

Test: `tests/EndToEnd/ObserveTest::testEventCountsForThreeFindings`.

## S9. `check` (FR-031)

`watcher check` with driver `none`, log + prometheus on: `notifier: none (dashboard-only)`, `observe: log ok, prometheus ok`;
unwritable prometheus dir: `observe: prometheus dir invalid`; `--post-test` flushes one `check_test` event.

Tests: `tests/Unit/Operate/DoctorObserveTest`.

## S10. Dashboards (US6, SC-010)

`DashboardsDocsTest`: both JSON files parse, have `schemaVersion`, queries use only catalog metrics and schema fields;
`prometheus-rules.yaml` and `grafana-alerts.yaml` parse and reference catalog names; `mapping.md` lists every field.
