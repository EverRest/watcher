# Implementation Plan: Metrics, Sentry Issues and Trace Links

**Branch**: `009-metrics-and-sentry` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/009-metrics-and-sentry/spec.md` (with its "Owner decisions" block)

## Summary

Three independent, read-only, off-by-default additions. Core contracts (`LogSource`, `StateStore`, `Notifier`,
`Analyzer`) keep their signatures.

1. **Metrics** (US1, US2): optional `MetricSource` (Prometheus HTTP API, direct or through the Grafana datasource
   proxy). `MetricsReader` runs the declared queries for the window and the baseline window and reduces each to one
   number. The result feeds (a) one digest line per service, (b) a `m` section in the compact model input, (c) at most
   `metrics.max_anomalies` synthetic `metric_anomaly` candidates when no Error-or-above candidate explains the move.
2. **Sentry** (US3): optional `IssueSource` (`SentryIssueSource`, one `GET` of the project issue list per page).
   `IssueGroupBuilder` turns allowlisted `IssueRecord`s into ordinary `ProblemGroup`s with fingerprint `sentry:{id}`,
   which enter the pipeline right after the log `Reducer`, so mute, baseline comparison, selector, budget and
   redaction apply unchanged.
3. **Trace links** (US4): `TraceLinkBuilder` beside `LogLinkBuilder` (no interface). A `TraceIdCollector` hooked into the
   `Reducer` keeps the earliest valid trace id per group **in memory only**; the id never enters `ProblemGroup`,
   `RedactedGroup`, `CompactPayload`, state or any notifier text except inside the link URL.

Decisions: [research.md](research.md). Keys, value objects, state: [data-model.md](data-model.md). Config, interfaces,
HTTP surface, payload and JSON additions: [contracts/](contracts/). Scenarios: [quickstart.md](quickstart.md).

## Technical Context

**Language/Version**: PHP ^8.1 (unchanged). **New dependencies**: none (Guzzle already present; JSON only).

**Storage**: existing `StateStore`. No new key kind: metric readings are not persisted; metric-anomaly and Sentry
groups ride in the existing window snapshot (`groups` map: fingerprint => count) and the existing verdict/reported keys.

**Testing**: PHPUnit `Unit`, `Contract`, `EndToEnd`; no network (Principle III): `FakeMetricSource`, `FakeIssueSource`,
and recorded Guzzle fixtures under `tests/Fixtures/prometheus/`, `tests/Fixtures/sentry/`.

**Constraints**
- Off by default: no section, no request, byte-identical output (SC-005).
- At most 2 instant queries per declared metric per run (SC-002); at most `sentry.max_issues` issues read (SC-004).
- Metrics/Sentry failure never changes log findings or the exit code; one visible line each (FR-009, FR-015).
- Zero extra model calls; anomaly candidates ride the normal triage path.

## Constitution Check

| Principle | Gate | Result |
|---|---|---|
| I. Privacy fails closed | New field classes are allowlisted additions (owner decision 2026-10-24): metric numbers (floats), Sentry masked title/culprit/level/counts/times. Prometheus labels dropped at the adapter boundary unless allowlisted (max 5 values, Normalizer-masked). Sentry reader picks 9 named keys and discards the rest. Sentry text goes through `Normalizer` + `Redactor` (two layers, same withholding rule). Trace id kept in memory only, validated by regex. Privacy corpus extended **before** any renderer or payload change (T006; trace cases T040). Any parse/type error drops the value. | Pass |
| II. Read-only | Prometheus: `GET`/form-`POST` to `/api/v1/query` and `/api/v1/query_range` only. Sentry: `GET` of the project issue list only. Trace links never called. Architecture tests pin the endpoint allowlists (T008, T027, T041). | Pass |
| III. Test-first | Each implementation task is preceded by a red test task; HTTP faked. Selection table test (SC-003), privacy corpus (SC-001), token-budget fixture (SC-002/4/5), failure e2e (SC-006), golden URLs (SC-007). | Pass |
| IV. Code decides | Anomaly selection, thresholds, aggregation, Sentry new/grown/level and link building are code. Model sees numbers/masked text only; its causes stay hypotheses. Prompt addendum states metric numbers are measurements, not causes. | Pass |
| V. Token discipline | Metric context <= 60 input tokens per service (cut to fit), 0 output tokens without anomaly; <= 3 extra candidates; Sentry groups share top-N and budget; links 0 tokens. Increase stated in Token Budget Impact and pinned by fixtures (T048). Metric context is dropped first when the step budget would be exceeded. | Pass |
| VI. Config over code | Queries, labels, thresholds, hosts, field names all from config; no query, metric name, org or project shipped. Validation at start naming the setting (T010, T029, T039). | Pass |
| VII. Loud failures | `metrics unavailable` / `Sentry unavailable` lines (digest or notice path); `watcher check` gains two checks; ambiguous series and unknown placeholders are named. | Pass |
| VIII. Simplicity | Two optional interfaces each with a real second reason (Prometheus vs Grafana-proxied is one class with a path prefix; the interfaces exist because the spec names them as extension points and tests need fakes). `TraceLinkBuilder` is a concrete class. No new dependency. Range mode is the only speculative corner and is single-series only (R3). | Pass |

**Four-contract principle**: unchanged. `MetricSource` and `IssueSource` are optional siblings found through
`Factory` (null when the section is absent) and passed to `Watcher` as nullable constructor arguments, like `ModelCatalog`.

**Spec drift / ambiguity recorded**
1. Spec edge case says default aggregate `sum` for rates and `max` for latency, but the metric definition has no
   "kind". Resolved in R3: no implicit default; `aggregate` omitted + more than one series = `ambiguous` (the same outcome
   the edge case describes), examples in docs show `sum` and `max` explicitly.
2. Spec US3 calls for "the group fingerprint or hash"; the issue-list endpoint returns the group **id** and no hash.
   Fingerprint is `sentry:{id}` (FR-013 allows "hash or id"). See R7.
3. Spec asks for the event count "in the window"; the issue list's `count` is lifetime. See R7 and owner decision D1.

## Project Structure

### Documentation (this feature)

```text
specs/009-metrics-and-sentry/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/{interfaces.md,config.md}
├── checklists/
└── tasks.md
```

### Source Code (changes only)

```text
src/
├── Metrics/ (new)
│   ├── MetricSource.php, MetricResult.php, PrometheusSource.php     # adapter: query / query_range, label drop
│   ├── MetricDefinition.php, QueryTemplate.php                      # parsed config, placeholder substitution
│   ├── MetricReading.php, MetricsReader.php, MetricsContext.php     # numbers, delta, availability
│   └── MetricAnomalies.php                                          # threshold + "unexplained" rule -> Candidate
├── Issues/ (new)
│   ├── IssueSource.php, IssueRecord.php, SentryIssueSource.php      # allowlisting reader
│   └── IssueGroupBuilder.php                                        # IssueRecord -> ProblemGroup, sentry: prefix
├── Context/TraceLinkBuilder.php, TraceIdCollector.php (new)
├── Select/CandidateReason.php           # + MetricAnomaly (weight 5)
├── Reduce/Reducer.php                   # + optional per-event observer closure (trace ids)
├── Analyze/PayloadExtras.php (new; shared with 010), CompactPayload.php   # + `m` section
├── Analyze/{TriageRequest,AnalysisRequest,AnthropicAnalyzer,ModelRouter}.php, Operate/EstimatingAnalyzer.php   # carry/pass the extras
├── Analyze/Budget.php                   # fitMetrics (drop metric context first); prompts/ (1 sentence)
├── Notify/DigestData.php, DigestRenderer.php   # [shared hot] one trailing `?DigestExtras`; metrics line, Sentry/trace links
├── Notify/DigestExtras.php (new; shared with spec 010)
├── Operate/Doctor.php                   # [shared hot] + metrics, sentry checks
├── Operate/RunStats.php, JsonSummary.php        # + counts
├── State/Keys.php                       # unchanged (no new kind)
├── Config/WatchConfig.php               # [shared hot] metrics, sentry sections; links.tempo, links.datadog_apm
├── Config/Factory.php                   # [shared hot] metricSource(), issueSource()
└── Watcher.php                          # [shared hot] wiring (see tasks: sequential)
config/watcher.example.php, docs/{configuration,privacy,operations}.md, docs/examples/metrics-queries.md, docs/changelog (CHANGELOG.md)

tests/
├── Unit/{Metrics,Issues,Context,Select,Analyze,Notify,Operate,Config}/...
├── Fakes/{FakeMetricSource,FakeIssueSource}.php
├── Fixtures/{prometheus,sentry}/*.json, privacy/seeded-values.php (extended), privacy/metrics-labels.json, privacy/sentry-issues.json
└── EndToEnd/{MetricsTest,SentryTest,TraceLinksTest}.php, PrivacyTest.php + RunSummaryTest.php (extended)
```

**Structure Decision**: same single library. `src/Watcher.php`, `src/Config/Factory.php`, `src/Config/WatchConfig.php`,
`src/Notify/DigestData.php|DigestRenderer.php`, `src/Operate/Doctor.php`, `src/Analyze/CompactPayload.php` are hot files
shared by stories and by spec 010; tasks serialise them with the `[W] [F] [C] [D] [K] [P]` markers.

## Wiring in `Watcher::attempt()` (the three insertion points)

| Point | Existing line (baefdeb) | Change |
|---|---|---|
| after `$groups = $reducer->reduce($events)` | `Watcher.php:548` | append `IssueGroupBuilder` groups (when `IssueSource` set) before the `$allGroups = $groups` copy; read Sentry before this point, inside try/catch -> `unavailable` |
| after `$selection = (new Selector())->select(...)` | `Watcher.php:595` | read metrics; `MetricAnomalies` adds candidates to the list **before** the `reported` filter loop; add anomaly groups to `$allGroups` for the snapshot |
| `$links[$g->fingerprint] = [...]` loop | `Watcher.php:714` | add `'trace'` and `'sentry'` link entries; pass `DigestExtras` as the last `DigestData` argument |

First run (`$comparison->isBaseline()`): Sentry groups are in `$allGroups` (so they are in the snapshot); no metrics read,
no anomaly (nothing to compare with).

## Complexity Tracking

| Ceiling | Why | Upgrade path |
|---|---|---|
| Anomaly status uses only the previous window's snapshot count, not the spec-004 baseline modes | Owner decision 2026-10-24: previous window until 004 modes apply | Read `$comparison->previousFor()` once anomaly groups are registered with `BaselineResolver` |
| `mode: range` supports a single series only | Keeps the aggregate/partition logic small | Per-series partition when a real query needs it |
| Anomaly direction is "up" only | All declared use cases (error rate, latency) increase | Add `direction: down` when a throughput metric is requested |
| Sentry window counts are bucket sums (hour or day granularity) | Issue list has no exact windowed count (R7) | Events-stats endpoint is out of scope (FR-010) |
| No merging of Sentry and log groups | Owner decision | Separate spec with its own corpus |
