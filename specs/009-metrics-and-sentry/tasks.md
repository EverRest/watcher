---

description: "Task list for Metrics, Sentry Issues and Trace Links (everrest/watcher)"
---

# Tasks: Metrics, Sentry Issues and Trace Links

**Input**: Design documents from `specs/009-metrics-and-sentry/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (Constitution Principle III). Every test task comes before its implementation task and must fail first
(red). No test touches the network. Gates after each story: `composer test`, `composer stan`, `composer style`, each exit 0
(read `$?`).

**Organization**: by user story. US1 and US2 are P1 (US2 needs US1's reader), US3 is P2 (independent of US1/US2), US4 is P3
(independent). Foundational first.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallel with other [P] tasks in the same phase (different files, no unfinished dependency)
- **[W]** edits `src/Watcher.php` (and `src/Fleet/FleetRunner.php`); **[F]** edits `src/Config/Factory.php`; **[C]** edits `src/Config/WatchConfig.php`;
  **[D]** edits `src/Notify/DigestData.php` / `DigestRenderer.php`; **[K]** edits `src/Operate/Doctor.php` (and `src/Fleet/FleetCheck.php`); **[R]** edits `src/Operate/RunStats.php` / `JsonSummary.php` / `src/Analyze/PayloadExtras.php`;
  **[B]** edits `src/Analyze/CompactPayload.php` / `Budget.php` / `PayloadExtras.php` / the request classes / `ModelRouter.php` / `src/Analyze/prompts/*`.
  Two tasks with the same letter never run in parallel, **across this spec and spec 010** (see Dependencies).
- Paths are relative to the repository root

---

## Phase 1: Foundational (shared by all stories)

- [x] T001 [P] Test then add `CandidateReason::MetricAnomaly = 'metric_anomaly'` with weight 5 (same as `Level`); the `weight()` table test lists every case, priority of a Warning-level anomaly is `5*10+3` in `tests/Unit/Select/SelectorTest.php`, `src/Select/CandidateReason.php`
- [ ] T002 [P] Test then add the optional per-event observer to `Reducer::__construct(..., ?\Closure $onEvent = null)`: called once per event with `(LogEvent, string $fingerprint)` right after the fingerprint is computed (`src/Reduce/Reducer.php:75`); default null leaves output identical (existing `ReducerTest` untouched and green); a throwing observer is not caught (it is our code) in `tests/Unit/Reduce/ReducerTest.php`, `src/Reduce/Reducer.php`
- [x] T003 [P] Test then add `Notify\DigestExtras` (immutable: `metricsLine`, `metricsNote`, `unavailable` map tag => token, `sentryDropped` int (rendered `N Sentry issues dropped`), `endpointLines` map, `endpointsSkipped`; `isEmpty()`; every string passes through nothing here, callers pass masked text) in `tests/Unit/Notify/DigestExtrasTest.php`, `src/Notify/DigestExtras.php` [shared with spec 010: whichever spec lands first creates it; the other adds only the fields it needs]
- [x] T004 [D] Test then add the trailing `?DigestExtras $extras = null` argument to `DigestData`; `DigestRenderer` prints nothing for null/empty extras (the existing `DigestTest`, `MultiChannelTest`, `TemplateReadabilityTest` outputs stay byte-identical) and, for non-empty extras, prints `metricsLine` under the header, `metricsNote`, the `N Sentry issues dropped` note, and one `X unavailable (<token>)` note per entry in the footer notes next to `failedDependencies`; also prints `$links[$fp]['trace']` and `['sentry']` where `['log']`/`['code']` print, null-safe in `tests/Unit/Notify/DigestRendererTest.php`, `src/Notify/DigestData.php`, `src/Notify/DigestRenderer.php`
- [ ] T005 [R] Test then add optional counters to `RunStats` (`metricQueries`, `sentryRequests`, `sentryIssues`, `sentryDropped`, `metricAnomalies`, `metricTokens`, `sentryTokens`) and render `metrics` / `sentry` objects in `JsonSummary` **only when the part is configured** (default output byte-identical) in `tests/Unit/Operate/JsonSummaryTest.php`, `src/Operate/RunStats.php`, `src/Operate/JsonSummary.php`
- [ ] T006 [P] Extend the privacy corpus first (red until stories land): add seeded values for metric label values (`customer-4711`, an e-mail), Sentry titles (e-mail, long id, token), Sentry user/tag/breadcrumb/permalink fields, a seeded metrics token and Sentry token (FR-020: in no digest, log, notice, `--json` or model input), and trace ids to `tests/Fixtures/privacy/seeded-values.php`; add `tests/Fixtures/privacy/metrics-labels.json`, `tests/Fixtures/privacy/sentry-issues.json`; extend `tests/EndToEnd/PrivacyTest.php` and `tests/EndToEnd/JsonSummaryPrivacyTest.php` to assert 0 seeded values in any model input, notifier payload, persisted state or `--json` line, except a valid trace id inside a link URL in `tests/EndToEnd/PrivacyTest.php`, `tests/EndToEnd/JsonSummaryPrivacyTest.php`, `tests/Fixtures/privacy/*`

**Checkpoint**: unit suite green except the new red corpus cases (they name the story that turns them green).

---

## Phase 2: User Story 1 - Metric deltas next to a log finding (P1)

**Goal**: one metrics line per service, the same numbers in the model input, `unavailable` on failure, nothing when off.

**Independent Test**: quickstart 1-6.

### Tests first

- [x] T007 [P] [US1] Test `QueryTemplate` (red): each built-in placeholder replaced (`{window}` -> `86400s`, times -> Unix seconds); `vars` replaced; unknown placeholder rejected naming it; PromQL selectors `{job="x"}`, `{}`, `{__name__=~"a"}` untouched; one-pass (a var value `{start}` is rejected by the value regex, and a value is never re-scanned); value outside `^[A-Za-z0-9_.:/\-]{1,64}$` rejected; vars shadowing a built-in rejected; query > 2,000 chars rejected; exception text never holds the query or value in `tests/Unit/Metrics/QueryTemplateTest.php`
- [x] T008 [P] [US1] Test `PrometheusSource` with a fake Guzzle handler and fixtures `tests/Fixtures/prometheus/{vector_one,vector_labelled,vector_nan,vector_empty,matrix_one,error_401,error_bad_json}.json` (red): instant/range request lines and query params; GET below 2,000 chars, form POST above; Grafana type uses the proxy prefix and Bearer; labelled vector -> only floats kept, labels gone, `series` counted; NaN/Inf/`+Inf` filtered, empty -> `values=[]`; allowlisted label values: <= 5 distinct, raw; range refused client-side with `SourceUnavailable('too_large')` when `(end-start)/step > max_points`; 401/403 -> `SourceUnavailable('unauthorized')`, timeout -> `timeout`, non-JSON/`status:error` -> `bad_response`, 429/5xx retried per `Retry` then `rate_limited`/`unreachable`; no URL, token or query in any exception message; extend `ArchitectureTest` with the endpoint allowlist (contracts/interfaces.md) and a `MetricSource` contract suite run against the fake and this adapter in `tests/Unit/Metrics/PrometheusSourceTest.php`, `tests/Unit/ArchitectureTest.php`, `tests/Contract/MetricSourceContract.php`, `tests/Fakes/FakeMetricSource.php`
- [x] T009 [P] [US1] Test `MetricsReader`/`MetricReading` (red): metric label values are allowlisted names only, max 5, Normalizer-masked and dropped if the Normalizer changed them, then `Redactor::redactText` (null drops); an allowlisted label holding an e-mail and one holding `customer-4711` are both dropped; aggregate `sum|max|avg|last`; one series without `aggregate` is fine; > 1 series without `aggregate` -> `ambiguous` and skipped; empty/NaN -> `no_data` (never 0); baseline null or <= 0 -> `no_baseline` (current shown, no percent); relative and absolute change; two `instant()` calls per metric (current at end, baseline at baseline_end), asserted by the fake's call log (SC-002); `range` mode: one call, step from `max_points`, partition by timestamp, single series only; a range over `max_points` -> `SourceUnavailable('too_large')`; a throwing source -> `MetricsContext::unavailable` with the fixed token, no exception escapes in `tests/Unit/Metrics/MetricsReaderTest.php`
- [x] T010 [P] [US1] Test the `metrics` config section (red): defaults; `enabled: false` still validates; unknown key, bad `type`, missing `base_url`/`datasource_uid`, bad URL scheme, > 6 queries, empty `queries`, bad name/aggregate/mode/unit/threshold, unknown placeholder, `max_anomalies` > 10, `max_points` out of range each throw `InvalidConfig` naming the key path and no value; token never in a message; section absent -> `$c->metrics === null` in `tests/Unit/Config/WatchConfigTest.php`
- [ ] T011 [P] [US1] Test `PayloadExtras`, `CompactPayload::encode(..., ?PayloadExtras)` and `Budget::fitMetrics()` (red): `m` present with names once and `[current, baseline]` pairs, `null` for no data; absent when null/empty (existing payload golden unchanged); `fitMetrics` keeps at most 3 metrics (60 tokens / 20), drops the whole `m` first when the step's `max_input_tokens` would be exceeded and reports it; the system prompt contains the `m = measured metric values` sentence only when `m` is present (prefix byte-stable otherwise); triage gets `m` only when the batch holds an anomaly group, analysis always; an anomaly's own entry survives `fitMetrics`; the six `encode` call sites pass the same extras (a spy asserts `AnthropicAnalyzer`, `ModelRouter`, `EstimatingAnalyzer` payloads are equal for equal input) in `tests/Unit/Analyze/CompactPayloadTest.php`, `tests/Unit/Analyze/BudgetTest.php`, `tests/Unit/Analyze/ModelRouterTest.php`
- [ ] T012 [US1] End-to-end (red until T021): fake metrics + one log candidate -> one `Metrics:` line, payload `m` equal to the digest numbers, no label anywhere; failing fake -> identical findings plus one `Metrics unavailable (timeout)` note and unchanged exit; no section -> zero source calls and byte-identical digest/payload; baseline run (first run) makes no metrics call in `tests/EndToEnd/MetricsTest.php` (PARTLY done in `tests/EndToEnd/MetricsSentryTest.php`: no `m` payload section, no token-budget case; see Owner decisions 2026-10-25)

### Implementation

- [x] T013 [P] [US1] Implement `MetricSource`, `MetricResult`, `MetricDefinition`, `QueryTemplate` in `src/Metrics/MetricSource.php`, `src/Metrics/MetricResult.php`, `src/Metrics/MetricDefinition.php`, `src/Metrics/QueryTemplate.php`
- [x] T014 [US1] Implement `PrometheusSource` (direct + Grafana proxy, label drop at the boundary, allowlisted label values, step guard, fixed failure vocabulary) in `src/Metrics/PrometheusSource.php`
- [x] T015 [US1] Implement `MetricReading`, `MetricsContext`, `MetricsReader` (baseline window = previous window of equal length, R4; never throws) in `src/Metrics/MetricReading.php`, `src/Metrics/MetricsContext.php`, `src/Metrics/MetricsReader.php`
- [x] T016 [C] [US1] Implement the `metrics` section in `WatchConfig` (public `?array $metrics`, validated even when `enabled: false`; NOT added to `FLAGS`, R10) in `src/Config/WatchConfig.php`
- [x] T017 [F] [US1] Add `Factory::metricSource(WatchConfig, Client): ?MetricSource` (null when absent/disabled; token from config only) in `src/Config/Factory.php`
- [ ] T018 [B] [R] [US1] Add `Analyze\PayloadExtras`; carry it in `TriageRequest`/`AnalysisRequest` (new optional last argument); pass it at the six `CompactPayload::encode` call sites (Watcher:626, AnthropicAnalyzer:131, ModelRouter:177, EstimatingAnalyzer:31 and :48, Budget:69); add the `m` section to `CompactPayload::encode` (third optional parameter), `Budget::fitMetrics()`, and the conditional system-prompt sentence in `src/Analyze/PayloadExtras.php`, `src/Analyze/CompactPayload.php`, `src/Analyze/Budget.php`, `src/Analyze/TriageRequest.php`, `src/Analyze/AnalysisRequest.php`, `src/Analyze/AnthropicAnalyzer.php`, `src/Analyze/ModelRouter.php`, `src/Operate/EstimatingAnalyzer.php`, `src/Analyze/prompts/`
- [ ] T019 [P] [US1] Docs: `docs/examples/metrics-queries.md` with the two examples (request error ratio with `aggregate: sum`; p95 from a histogram with `aggregate: max`), the placeholder table and the label rule; `config/watcher.example.php` `metrics` block (commented out) in `docs/examples/metrics-queries.md`, `config/watcher.example.php`
- [x] T020 [W] [US1] Wire into `Watcher`: constructor takes `?MetricSource`; after `$selection` (`Watcher.php:595`) read `MetricsReader` (skipped on the baseline run), pass `MetricsContext` to `CompactPayload`/`Budget`, build `DigestExtras` (metrics line, `unavailable`), bump `RunStats`, log failures at warning level with the fixed token; `Factory`-built source passed from `Watcher::fromConfig` in `src/Watcher.php`
- [ ] T021 [US1] Verify T012 and T006's metric cases go green; run the gates (PARTLY done in `tests/EndToEnd/MetricsSentryTest.php`: no `m` payload section, no token-budget case; see Owner decisions 2026-10-25)

**Checkpoint**: quickstart 1-6 green; US1 shippable alone.

---

## Phase 3: User Story 2 - Metric anomalies no log finding explains (P1)

**Goal**: stand-alone `metric_anomaly` candidates, selected by code, deduplicated like any group.

**Independent Test**: quickstart 7-10.

### Tests first

- [x] T022 [P] [US2] Selection table test for `MetricAnomalies` (red; SC-003): rows `{relative, floor, current, baseline, explainingCandidateLevel, expected}`: tripled p95 + empty log window -> 1; same + Error group -> 0; same + Warning group only -> 1; relative met but below floor -> 0; floor met but relative not -> 0; no baseline -> 0; metric without `threshold` -> 0; two metrics both over -> 2 distinct fingerprints; 5 over with `max_anomalies` 3 -> 3 largest relative changes, ties by name, truncation count returned; fingerprint stable across runs and prefixed `metric:`; count is `max(1, round(rel*100))`; group fields as data-model.md; the template contains the declared name only (masked) and no digit from the numbers in `tests/Unit/Metrics/MetricAnomaliesTest.php`
- [x] T023 [US2] End-to-end (red until T025): silent latency regression -> exactly one anomaly in the digest, triage input = group row with `mi` + `m` pairs, no log text; day 2 same numbers -> not re-analysed (reported key + verdict cache); day 2 count grown by `growth_factor` -> analysed again; a Sentry error group in the same service suppresses it; anomaly appears in the snapshot `groups` map; digest says `N metric anomalies not shown` when truncated; baseline run creates none in `tests/EndToEnd/MetricsTest.php`

### Implementation

- [x] T024 [US2] Implement `MetricAnomalies` (rule, synthetic `ProblemGroup`, status from `$prev['groups']`, cap) in `src/Metrics/MetricAnomalies.php`
- [x] T025 [W] [US2] Wire into `Watcher`: after the metrics read, `MetricAnomalies` adds `Candidate`s to the pre-filter selection list **before** the `Keys::reported` loop, appends anomaly groups to `$allGroups`, makes `$redactedBy` rows carry `mi`, and counts `metricAnomalies`; "no candidates" quiet path now needs `candidates === [] && anomalies === []` in `src/Watcher.php`
- [x] T026 [US2] Verify T023 green; run the gates

**Checkpoint**: quickstart 7-10 green.

---

## Phase 4: User Story 3 - Sentry issues as a source (P2)

**Goal**: masked, allowlisted Sentry groups in the normal pipeline; no merge with log groups.

**Independent Test**: quickstart 11-14.

### Tests first

- [x] T027 [P] [US3] Test `SentryIssueSource` with fixtures `tests/Fixtures/sentry/{issues_page,issues_page2,issues_with_pii,error_401,error_429,bad_json}.json` (red): request is `GET` to the issues path only with `is:unresolved` query, `limit`, `Authorization: Bearer`; no body; `Link rel=next results=true` paging stops at `max_issues`, on a repeated cursor, on an empty page, and at the request cap `ceil(max_issues/100)+2`; only the allowlisted keys reach `IssueRecord` (a fixture with `user`, `tags`, `breadcrumbs`, `permalink`, `shortId`, `metadata`, `assignedTo`, `annotations`: none survive; `IssueRecord` has no property for them); title cut to 300 / culprit to 120 before masking; windowed count = sum of `stats` buckets that overlap the window (`ts+bucketLen > start && ts < end`; a sub-hour window still counts its hour bucket); `window.end` outside the stats period (24h hourly, 14d daily) -> `SourceUnavailable('window_too_old')`; missing/malformed `stats`, non-string title, invalid UTF-8, non-numeric id, `status != unresolved` -> issue dropped and counted (surfaced as `sentryDropped`), not guessed; 401/403 -> `unauthorized`, 429 -> `rate_limited`, non-JSON -> `bad_response`; GET only, no other endpoint, no body (US3 AC6); no token/URL in messages; `ArchitectureTest` pins path + GET and bans events/users/tags/attachments/hashes/breadcrumbs; `IssueSource` contract suite on fake + adapter in `tests/Unit/Issues/SentryIssueSourceTest.php`, `tests/Unit/ArchitectureTest.php`, `tests/Contract/IssueSourceContract.php`, `tests/Fakes/FakeIssueSource.php`
- [x] T028 [P] [US3] Test `IssueGroupBuilder` (red): fingerprint `sentry:{id}` (never recomputed from the title); template = `Normalizer`-masked title; `null` when `count < min_group_events_for_text`; e-mail/id/token in titles masked (seeded corpus); culprit -> `frames[0]` masked; level map (fatal->Critical ... debug->Debug); `firstSeen/lastSeen` UTC; `isRegression` carried to the digest label only; two issues with equal titles stay two groups; an exception in the Normalizer drops the issue (counted) in `tests/Unit/Issues/IssueGroupBuilderTest.php`
- [x] T029 [P] [US3] Test the `sentry` config section (red): defaults (`base_url` `https://sentry.io`, `max_issues` 50); slug patterns; `max_issues` 1..100; missing `organization`/`project`/`auth_token`; non-http(s) `base_url`; unknown key; `enabled: false` still validates; token never in a message in `tests/Unit/Config/WatchConfigTest.php`
- [ ] T030 [US3] End-to-end (red until T035): Sentry fixture + log fixture on the same problem -> two entries, Sentry one has the constructed issue link, no merge; Sentry group goes through mute list, baseline comparison, `top_n`, budget; resolved/ignored absent; 401 -> `Sentry unavailable (unauthorized)` and log findings identical to a run without Sentry (SC-006); seeded corpus values absent from every payload (SC-001); Sentry group token cost within 5% of a log group in the token-budget fixture (SC-004); `window_too_old` -> `Sentry unavailable (window_too_old)`; dropped issues -> `N Sentry issues dropped` note; no section -> byte-identical (SC-005) in `tests/EndToEnd/SentryTest.php` (PARTLY done in `tests/EndToEnd/MetricsSentryTest.php`: no `m` payload section, no token-budget case; see Owner decisions 2026-10-25)

### Implementation

- [x] T031 [P] [US3] Implement `IssueSource`, `IssueRecord`, `SentryIssueSource` (explicit key pick, type checks, paging, fixed failure vocabulary) in `src/Issues/IssueSource.php`, `src/Issues/IssueRecord.php`, `src/Issues/SentryIssueSource.php`
- [x] T032 [US3] Implement `IssueGroupBuilder` in `src/Issues/IssueGroupBuilder.php`
- [x] T033 [C] [US3] Implement the `sentry` config section in `src/Config/WatchConfig.php`
- [x] T034 [F] [US3] Add `Factory::issueSource(WatchConfig, Client): ?IssueSource` in `src/Config/Factory.php`
- [x] T035 [W] [US3] Wire into `Watcher`: constructor takes `?IssueSource` and `Watcher::fromConfig` passes the `Factory`-built `IssueSource` (and `MetricSource`); read issues before the `Reducer` result is copied to `$allGroups` (`Watcher.php:548`), merge groups, re-sort; on `SourceUnavailable` log the token and add `sentry` to `DigestExtras::unavailable`, set `DigestExtras::sentryDropped`; add `'sentry'` link to the per-fingerprint `$links` (constructed `{base_url}/organizations/{org}/issues/{id}/`, never called); bump `RunStats` in `src/Watcher.php`
- [ ] T036 [US3] Verify T030 and T006's Sentry cases green; run the gates (PARTLY done in `tests/EndToEnd/MetricsSentryTest.php`: no `m` payload section, no token-budget case; see Owner decisions 2026-10-25)

**Checkpoint**: quickstart 11-14 green.

---

## Phase 5: User Story 4 - Trace links (P3)

**Goal**: links only; the trace id exists in memory and in a URL, nowhere else.

**Independent Test**: quickstart 15-16.

### Tests first

- [x] T037 [P] [US4] Test `TraceIdCollector` + the `Reducer` hook (red): accepts `^[0-9a-f]{16,32}$` case-insensitively and stores lower-case; rejects 15/33 chars, non-hex, arrays, ints; keeps the earliest event's id per fingerprint; groups with no id yield null; reading `fields[trace_field]` with a non-default field name in `tests/Unit/Context/TraceIdCollectorTest.php`
- [x] T038 [P] [US4] Golden-string tests for `TraceLinkBuilder` (red): Tempo with id, Tempo service search (no id), Datadog with id, Datadog service search with `env:` from stream tags; exact URL strings from fixed config/window; Tempo preferred when both set; null when no config; quotes/backslashes in service name escaped; the URL contains the id only when valid in `tests/Unit/Context/TraceLinkBuilderTest.php`
- [ ] T039 [P] [US4] Test `links.tempo` / `links.datadog_apm` config (red): whitelist accepts both, `trace_field` pattern, `datadog_apm` without `links.datadog.app_url` rejected naming the key, unknown nested key rejected in `tests/Unit/Config/WatchConfigTest.php`
- [ ] T040 [US4] End-to-end + corpus (red until T044): a group with trace ids in its events gets a link containing the earliest id; the id appears in no model input (`CompactPayload`, system prompt), no snapshot/verdict/reported/run key (scan every `RecordingStateStore` write), no `--json`, no notice; present only inside the link URL of the digest; group without id gets the service search link; no config -> no `Traces:` line (SC-007) in `tests/EndToEnd/TraceLinksTest.php`
- [ ] T041 [P] [US4] Architecture test: no `trace` identifier in `src/Privacy/RedactedGroup.php`, `src/Reduce/ProblemGroup.php`, `src/Analyze/CompactPayload.php`, `src/State/*` (the id cannot be carried by construction) in `tests/Unit/ArchitectureTest.php`

### Implementation

- [x] T042 [P] [US4] Implement `TraceIdCollector` and `TraceLinkBuilder` in `src/Context/TraceIdCollector.php`, `src/Context/TraceLinkBuilder.php`
- [ ] T043 [C] [US4] Add `links.tempo`, `links.datadog_apm` to the `links` whitelist and validation in `src/Config/WatchConfig.php`
- [ ] T044 [W] [US4] Wire into `Watcher`: build the collector when a tracing link is configured, pass its closure to `new Reducer(...)`, then `TraceLinkBuilder::forGroup($redactedGroup, $collector->idFor($fp), $window)` into `$links[$fp]['trace']` (`Watcher.php:719`) in `src/Watcher.php`
- [ ] T045 [US4] Verify T040, T041 and T006's trace cases green; run the gates

**Checkpoint**: quickstart 15-16 green.

---

## Phase 6: Cross-cutting

- [ ] T046 [P] Test `Doctor` (red): `metrics` check passes on `vector(1)` -> `1`, fails with each fixed token; `sentry` check passes on a one-item list; both absent when the section is absent; failure tokens include `rate_limited`, `too_large`, `window_too_old`; `Doctor::NAMES` lists the new checks and every `CheckReport` consumer (text and `--json`) renders them; no `Notifier::send`, no `Analyzer` call; reasons hold no URL/token/query; complete under 10 s with fakes (SC-008) in `tests/Unit/Operate/DoctorTest.php`
- [ ] T047 [K] Implement the two checks in `Doctor` (reusing `Factory::metricSource/issueSource`), extend `Doctor::NAMES` and update the `CheckReport` consumers in `src/Operate/Doctor.php`
- [ ] T048 [P] Token-budget fixtures (SC-002/004/005): metrics add <= 60 input tokens per service and 0 output tokens without anomaly; one anomaly costs the same as one other candidate; Sentry group within 5% of a log group; nothing configured = previous release's token counts on the existing end-to-end fixture (0 difference) in `tests/Unit/Analyze/BudgetTest.php`, `tests/EndToEnd/CostTest.php`
- [ ] T049 [P] End-to-end `--json` and heartbeat/dry-run interplay (red then green): counts present only when configured; `--dry-run` makes the same metric/Sentry reads (read-only) and writes no state; `--estimate` counts the extra input tokens in `tests/EndToEnd/RunSummaryTest.php`, `tests/EndToEnd/DryRunTest.php`
- [ ] T050 [P] Docs: `docs/configuration.md` (three sections, tables), `docs/privacy.md` (new allowlisted field classes: metric numbers, masked Sentry title/culprit; owner decision D5: a validated trace id, taken from raw `LogEvent::$fields`, may leave the service only inside the link URL of the digest and appears nowhere else), `docs/operations.md` (unavailable lines, `check` additions, read-only credentials, Sentry token scope), `CHANGELOG.md` entry (MINOR: new optional config sections), `docs/roadmap.md` row 009 -> "Planned" in `docs/*`, `CHANGELOG.md`
- [ ] T051 Run (after T053-T055) `composer test`, `composer stan`, `composer style` (each `echo $?`), the full quickstart table, and the architecture/feature-flag tests; record the three counts in the PR description
- [ ] T053 [P] Fleet end-to-end (red): two services with metrics, Sentry and trace links under `FleetRunner` -> each gets its metrics line, Sentry entries and trace links; fleet `check` rows include `metrics`/`sentry` per service; nothing configured -> byte-identical in `tests/EndToEnd/FleetTest.php`
- [ ] T054 [W] `src/Fleet/FleetRunner.php:72` builds `new Watcher(...)` positionally: pass the `MetricSource`/`IssueSource` (via `Factory`) so fleet runs get metrics, Sentry and trace links; same hunk discipline as the other [W] tasks in `src/Fleet/FleetRunner.php`
- [ ] T055 [K] `src/Fleet/FleetCheck.php:37` builds `new Doctor(...)`: pass the sources so fleet `check` rows include the `metrics` and `sentry` checks in `src/Fleet/FleetCheck.php`
- [ ] T052 Operator verification against live systems (not automatable, owner-run): Grafana proxy path and Prometheus query; Sentry issue-list parameters, `Link` paging and `stats` bucket shape; Tempo Explore URL; Datadog APM search URL. Update the UNVERIFIED notes in research.md and the fixtures if any shape differs in `specs/009-metrics-and-sentry/research.md`, `tests/Fixtures/{prometheus,sentry}/`

---

## Dependencies & Execution Order

- Phase 1 first. T001-T003, T005, T006 parallel; T004 [D] after T003.
- US1 -> US2 (US2 reuses `MetricsReader` and `DigestExtras`). US3 and US4 are independent of US1/US2 **in code**, but their [C]/[F]/[W]/[D]/[R] tasks are serialised on the hot files below, so they are not parallel in practice: the order below is the order of landing.
- **Sequential hot-file order** (one at a time, in this order; each is a small hunk): `[C]` T016, T033, T043; `[F]` T017, T034; `[W]` T020, T025, T035, T044, T054; `[D]` T004; `[B]` T018; `[R]` T005, T018 (`RunStats`/`JsonSummary`/`PayloadExtras`; spec 010 also creates or extends `PayloadExtras`, whichever lands first creates it); `[K]` T047, T055.
- **Across specs**: 010 also edits `Watcher.php`, `Factory.php`, `WatchConfig.php`, `DigestExtras`, `DigestData/Renderer`, `CompactPayload/Budget`, `Doctor`, `RunStats/JsonSummary`, `PayloadExtras`, `Fleet/FleetRunner`, `Fleet/FleetCheck`. Land in one order (recommended: 009 first, because it creates `DigestExtras` and the `?Extras` argument; 010 then extends it). Merge conflicts in these files are resolved **by hunk**, never by `--ours/--theirs` of the whole file.
- Tests marked [P] in one phase touch different files; `WatchConfigTest.php`, `ArchitectureTest.php` and `PrivacyTest.php` are touched by several tasks: add test methods only, in separate `describe`-sized blocks, and run the file once after each.
- Hand-off: no commit, push or tag unless the owner asks; no `--no-verify`; no AI co-author trailer.
