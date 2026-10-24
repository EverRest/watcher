# Feature Specification: Metrics, Sentry Issues and Trace Links

**Feature Branch**: `009-metrics-and-sentry`

**Created**: 2026-10-24

**Status**: Draft

**Input**: User description: "Metrics and traces for everrest/watcher: (a) read-only Prometheus (or Grafana datasource proxy) instant and range queries for error-rate and p95 latency deltas against the baseline window, shown next to log findings and as stand-alone 'metric anomaly' candidates when no log finding explains them; queries declared per service with named placeholders, numbers only, no label values unless allowlisted; (b) Sentry issues as an already-grouped source: read-only issue list per project and window (new, regressed, frequency), keyed by Sentry's own fingerprint, titles redacted by the Normalizer rules, no event payloads, user data or breadcrumbs; (c) trace ids turned into Tempo or Datadog APM links like the existing log links, links only. Source: docs/audit-2026-10-23.md, feature 8, and docs/roadmap.md item 009."

## Overview

Logs say what a service wrote; they do not say how much slower it got, and they miss failures that the service never logs
(a timeout at the gateway, a dependency answering slowly). Teams that run Prometheus already know the error rate and the
p95 latency; teams that run Sentry already have errors grouped, with their own fingerprints. Today the digest sees neither:
a latency regression with no error lines is invisible, and the Reader must open three tools to line up one incident.
This feature adds three small, read-only additions: numbers from the metrics backend next to the findings, Sentry issues as
a second, already-grouped source of candidates, and links from trace ids to the tracing UI. All three are off by default,
and none of them sends anything new to the model that is not a number, a count or a masked template.

**Optional sibling interfaces, not new core contracts.** The core contracts (`LogSource`, `StateStore`, notifier,
analyzer) are **unchanged**. The two new readers are separate, optional interfaces next to `LogSource`: a `MetricSource`
(instant and range scalar queries) and an `IssueSource` (read-only issue list). Neither is required by the core; a run
without them behaves exactly as today (Principle VI, VIII). A trace link builder is a concrete class beside
`LogLinkBuilder`, not an interface.

**Actors**

- **Operator** — declares the queries, the Sentry project, the allowlists and the budgets.
- **Reader** — reads the digest and wants "errors up, p95 up, same hour" in one place.
- **Service owner** — wants a latency or error-rate regression flagged even when the service logged nothing new.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Metric deltas next to a log finding (Priority: P1)

For each candidate group that maps to a declared service scope, the digest shows one context line with the error-rate and
p95 latency for the window against the baseline window (for example "error rate 0.4% (was 0.1%), p95 820 ms (was 310 ms)"),
read from the metrics backend by queries the Operator declared. The model receives the same numbers as part of its compact
input.

**Why this priority**: it is the cheapest way to tell "log noise" from "users are affected".

**Independent Test**: fake `MetricSource` returning known numbers for the window and baseline; the digest line and the
model input contain the expected values, and a fake returning labelled series leaks no label value.

**Acceptance Scenarios**:

1. **Given** a service with `metrics` queries declared, **When** a run has at least one log candidate, **Then** the digest
   shows one metrics line per service (not per group) with value, baseline value and relative change for each declared
   metric, and the analyzer input carries the same numbers.
2. **Given** a query template with named placeholders (`{service}`, `{window}`, `{start}`, `{end}`, `{baseline_start}`,
   `{baseline_end}`), **When** it is executed, **Then** placeholders are replaced from configuration and the window only;
   no log content, template text or event field is ever substituted into a query.
3. **Given** the backend returns a vector or matrix with labels, **When** the result is read, **Then** only the numeric
   sample values are kept; label names and values are discarded unless the Operator listed that label name in
   `metrics.label_allowlist`, in which case at most 5 distinct values per metric are kept, each passed through the
   Normalizer.
4. **Given** the metrics backend is unreachable, rejects the credential or times out, **When** the run continues, **Then**
   the digest says "metrics unavailable" in one visible line, log findings are unaffected, and the exit status follows the
   existing failure rules for optional context.
5. **Given** the baseline window has no data (new service, retention gap), **When** the delta is built, **Then** the line
   shows the current value and "no baseline" and no percentage.
6. **Given** metrics are not configured, **When** a run happens, **Then** no metrics request is made and no metrics line
   or token appears.

---

### User Story 2 - Metric anomalies that no log finding explains (Priority: P1)

When a declared metric moved beyond its threshold versus the baseline and no log candidate of the same service in the same
window is at level Error or above, the run produces a stand-alone "metric anomaly" candidate, so a silent latency
regression is reported instead of dropped.

**Why this priority**: it covers the case the logs cannot, which is the reason to read metrics at all.

**Independent Test**: fake metrics with p95 tripled and an empty log window produce exactly one metric-anomaly entry;
the same metrics with an explaining error group produce none.

**Acceptance Scenarios**:

1. **Given** a metric with a declared `threshold` (relative change and an absolute floor), **When** the current value
   exceeds baseline by the relative change and is above the floor, **Then** a candidate with reason `metric_anomaly` is
   created, its identity is the service plus the metric name (stable fingerprint), and it is selected by code, not by
   the model.
2. **Given** a log candidate at Error or above exists for the same service and window, **When** selection runs, **Then**
   no stand-alone anomaly is created; the metric appears only as the context line (Story 1).
3. **Given** an anomaly was reported yesterday and persists, **When** it recurs, **Then** the existing verdict-cache and
   grown/new rules apply to its fingerprint, so it is not re-analysed unless its numbers grew by the configured factor.
4. **Given** a metric anomaly candidate, **When** it is sent for triage, **Then** its input is the metric name, the two
   numbers and the window only; the model output uses the existing schema and its causes stay hypotheses.
5. **Given** anomalies exceed `metrics.max_anomalies` (default 3), **When** selection runs, **Then** the largest relative
   changes are kept and the truncation is stated in the digest.

---

### User Story 3 - Sentry issues as an already-grouped source (Priority: P2)

For a service that uses Sentry, the Operator configures the organisation, project and a read-only token. The run reads the
issue list for the window and maps each issue that is new, regressed or has grown in frequency to a problem group keyed by
Sentry's own fingerprint, with a title that passed the same masking as any log message.

**Why this priority**: some stacks (front ends, workers, third-party SDKs) report to Sentry and not to the logs the package
reads; Sentry already did the grouping.

**Independent Test**: recorded Sentry issue-list fixtures containing titles with e-mails, ids and tokens plus user, tag and
breadcrumb fields; the resulting groups carry only masked titles, counts and times, and no seeded value reaches any outbound
payload.

**Acceptance Scenarios**:

1. **Given** an issue list for the project and window, **When** it is read, **Then** per issue only these fields are
   kept: Sentry issue id (for a link), the group fingerprint or hash, the title, culprit (function or module, masked), level,
   event count in the window, first seen, last seen, status, and an `is_regression` flag; every other field is dropped at
   the boundary.
2. **Given** a title, **When** it is converted to a group, **Then** it goes through the Normalizer and Redactor exactly as a
   log message does, including the same per-cluster withholding rule, and the Sentry fingerprint becomes the group
   fingerprint prefixed `sentry:` so it never collides with a log fingerprint.
3. **Given** the selection rules, **When** Sentry groups are selected, **Then** the same code decides (new = first seen in
   window, grown = count against the previous window's snapshot, level), reasons are the existing `CandidateReason`
   values, and Sentry and log groups share the top-N and the budget.
4. **Given** a Sentry issue and a log group describe the same problem, **When** both are selected, **Then** they are
   **not** merged by guesswork; they appear as two entries, and a link to the Sentry issue is shown on the Sentry entry.
5. **Given** the issue API is unavailable or the token is rejected, **When** the run continues, **Then** a visible notice
   states it and the log path is unaffected.
6. **Given** the request, **When** it is made, **Then** it is an HTTP `GET` of the issue list only; the event, user, tag,
   breadcrumb and attachment endpoints are never called, and no request carries a body.

---

### User Story 4 - From a trace id to the tracing UI (Priority: P3)

When a log finding is shown, the digest includes a link that opens the tracing UI (Tempo through Grafana, or Datadog APM)
filtered by service and window, and, when the Operator allows it, a link per trace id for the first trace id seen in the
group. Only links are produced; no trace content is read or sent to the model.

**Why this priority**: it saves a few clicks at no model cost and no new data egress.

**Independent Test**: golden-string tests for the Grafana Tempo and Datadog APM URL shapes from fixed inputs; a corpus test
proves a trace id never reaches the model input.

**Acceptance Scenarios**:

1. **Given** `links.tempo` or `links.datadog_apm` configured, **When** a group has a trace id (from a configured context
   key or field of its events), **Then** the digest shows a link built from configuration, stream tags, the window and the
   trace id; the id is validated against `^[0-9a-f]{16,32}$` and dropped otherwise.
2. **Given** a group, **When** its model input is built, **Then** the trace id is absent; it is held only in the in-memory
   group for link building and never persisted beyond the existing short-lived state, and never sent to the model or any
   notifier except inside the link URL.
3. **Given** no trace id in a group, **When** the link is built, **Then** a service-and-window search link is shown, or
   nothing when no trace link configuration exists.
4. **Given** a trace id that appears in many events, **When** the group is built, **Then** at most one id (the earliest) is
   kept per group.

### Edge Cases

- A query returns NaN, Inf, or an empty vector: treated as "no data", never as zero.
- A query returns more than one series: the declared `aggregate` (default `sum` for rates, `max` for latency) is applied
  in code; if undeclared and more than one series returns, the metric is reported as "ambiguous" and skipped.
- A range query would return more than `metrics.max_points` samples (default 500): the call is refused client-side by step
  selection; the response is never larger than that.
- The query text itself contains a secret or customer label: query text is configuration; it is validated at start but is
  never echoed into the digest; the digest shows only the metric's declared name.
- Prometheus is reached through the Grafana datasource proxy: the same queries run through the proxy path with the
  Grafana token; nothing else about behaviour changes.
- Sentry returns a title longer than the line cap: it is cut to the cap before masking (same rule as log lines).
- Sentry marks an issue ignored or resolved: resolved and ignored issues are not candidates.
- Two metrics fire for one service in the same run: they form two candidates (distinct fingerprints), subject to
  `max_anomalies`.
- Time zones and DST: windows are the existing `Window` objects (UTC instants); no new time logic.

## Requirements *(mandatory)*

### Functional Requirements

**Metrics (read-only)**

- **FR-001**: The package MUST be able to run read-only instant and range queries against a Prometheus-compatible HTTP API,
  directly or through the Grafana datasource proxy, and MUST use only `GET` (or `POST` of a query form where the API
  requires it for length) to query endpoints; no write, admin or rule endpoint is ever called.
- **FR-002**: Queries MUST be declared per service in configuration as named metrics, each with a `query` text containing
  only the named placeholders `{service}`, `{start}`, `{end}`, `{window}`, `{baseline_start}`, `{baseline_end}` and the
  Operator's own `vars` entries; an unknown placeholder fails configuration validation before any external call, naming
  the setting.
- **FR-003**: Placeholder values MUST come only from configuration and the `Window`; no event, template, field or model
  output MAY be substituted into a query.
- **FR-004**: A metric result MUST be reduced to a single number per window by the declared `aggregate` (`sum`, `max`,
  `avg`, `last`); label names and values MUST be discarded, except labels named in `metrics.label_allowlist` (at most 5
  distinct values per metric, each Normalizer-masked).
- **FR-005**: For each declared metric the package MUST compute current value, baseline value, absolute and relative
  change; a missing baseline yields "no baseline" and no relative change.
- **FR-006**: The digest MUST show at most one metrics line per service, and the analyzer input MUST carry the same numbers
  in the compact form (a single array of numbers per metric, names sent once).
- **FR-007**: A metric whose relative change and absolute floor are both exceeded MUST become a `metric_anomaly` candidate
  when no log candidate of the same service at Error or above exists in the window; selection MUST be by code only.
- **FR-008**: A `metric_anomaly` candidate MUST have a stable fingerprint derived from the service and metric name, use the
  existing verdict cache, and be capped by `metrics.max_anomalies` (default 3) with the truncation stated.
- **FR-009**: Metrics failure (unreachable, auth, timeout, malformed response) MUST produce a visible line in the digest and
  MUST NOT fail the log path (Principle VII); the failure text MUST contain no URL credentials and no query text.

**Sentry issues**

- **FR-010**: The package MUST be able to read the issue list of one Sentry project for a window using a read-only
  organisation or project token, with `GET` only, calling no endpoint other than the issue list (and, if the Operator
  enables it, the project's own web URL for links, which is constructed and not called).
- **FR-011**: The reader MUST keep only the fields listed in User Story 3 / Scenario 1 and MUST drop all others at the
  boundary, including event payloads, user, tags, contexts, request data, extra, breadcrumbs, stack variables and
  assignee.
- **FR-012**: A Sentry title and culprit MUST pass through the Normalizer and the Redactor with the same rules and the same
  per-cluster withholding as log messages; any error or unparseable value MUST be dropped (Principle I).
- **FR-013**: The group fingerprint MUST be `sentry:` plus Sentry's own group hash or id, and MUST NOT be recomputed from
  the title; the package MUST NOT merge Sentry groups with log groups.
- **FR-014**: Sentry groups MUST enter the existing selector, so new, grown and level rules, top-N, mute list and budgets
  apply unchanged; resolved and ignored issues MUST be excluded.
- **FR-015**: Sentry unavailability MUST produce a visible notice and MUST NOT fail the log path.

**Trace links**

- **FR-016**: The package MUST build links to Tempo (through Grafana Explore) and to Datadog APM from configuration, stream
  tags, the window and, when present, a trace id; it MUST NOT call the tracing backend.
- **FR-017**: A trace id MUST be accepted only if it matches `^[0-9a-f]{16,32}$` (case-insensitive) and MUST be kept at most
  once per group, in memory, for link building only.
- **FR-018**: A trace id MUST NOT appear in any model input, in any persisted state, or in any notifier payload except as a
  part of the link URL.

**Cross-cutting**

- **FR-019**: Everything in this feature MUST be off by default and enabled by its own configuration section; invalid
  sections MUST fail before any external call, naming the setting.
- **FR-020**: Secrets (metrics token, Sentry token) MUST come from the host environment or configuration and MUST never be
  logged, echoed in notices, included in the digest or in any model input.
- **FR-021**: The `watcher check` doctor MUST be extended with one check each for metrics (a trivial `vector(1)` instant
  query) and Sentry (a one-item issue list), neither posting nor spending model tokens.
- **FR-022**: The extended features MUST add to the token budget accounting: metric lines and anomaly candidates count
  against the existing per-step budgets, and the run summary (`--json`) MUST report the number of metric queries, Sentry
  requests, and the tokens attributable to each.

### Key Entities

- **Metric Definition** — name, query text, aggregate, threshold (relative change and floor), optional unit.
- **Metric Reading** — current, baseline, change, availability; numbers only.
- **Metric Anomaly** — a candidate with reason `metric_anomaly`, service, metric name, the two numbers.
- **Sentry Issue Record** — the allowlisted fields above; converted to a Problem Group with a `sentry:` fingerprint.
- **Trace Link** — a URL built from configuration, tags, window and optionally a validated trace id.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the privacy corpus extended with metric label values and Sentry fixtures (titles with e-mails, ids, tokens;
  user, tag and breadcrumb fields; trace ids), 0 seeded values appear in any model input, notifier payload, persisted state
  or `--json` output, other than inside a trace link URL for a valid trace id.
- **SC-002**: With metrics configured, a run adds at most 2 queries per declared metric (current and baseline, or one range
  query), and the metric context adds at most 60 input tokens per service and 0 output tokens when no anomaly exists.
- **SC-003**: A synthetic latency regression with an empty error log window produces exactly one metric-anomaly entry; the
  same data with an explaining error group produces none (selection table test).
- **SC-004**: With Sentry enabled, a run adds at most 1 issue-list request per page and at most `sentry.max_issues` (default
  50) issues are read; Sentry groups add tokens only for the groups selected by code, at the same per-group cost as a log
  group (within 5% in the token-budget fixture).
- **SC-005**: With none of the three parts configured, token use, requests and digest text are byte-identical to the previous
  release on the existing end-to-end fixture (0 difference).
- **SC-006**: Failure of the metrics backend or Sentry never changes the log findings of the same run (end-to-end test with
  a failing fake: identical findings, one visible unavailable line).
- **SC-007**: Trace link golden tests pass for both backends; a trace id never appears in any model input in 100% of corpus
  cases.
- **SC-008**: A `watcher check` run reports each new check as pass or fail with a reason, without posting and without
  tokens, in under 10 seconds against fakes.

## Token Budget Impact

- Metrics context: about 20 input tokens per declared metric when present, capped at 60 per service by SC-002, sent as
  numbers in the compact payload; no extra model calls.
- Metric anomaly: one extra triage candidate per anomaly (at most 3 per run), costing the same as any other candidate;
  zero when none fires.
- Sentry: no extra calls; selected Sentry groups are ordinary candidates and share the existing top-N and budget, so the
  expected total change is bounded by the existing caps. The change is stated in the token-budget fixtures.
- Trace links: zero tokens.
- Default (nothing configured): zero change.

## Assumptions

- The metrics backend is Prometheus-compatible (Prometheus, Mimir, VictoriaMetrics) or reachable through the Grafana
  datasource proxy; other backends are out of scope.
- The Operator writes the queries; the package does not discover metrics and ships no default query, because metric names
  differ per stack. The documentation gives two examples (request error ratio, p95 from a histogram).
- Percentile latency is computed by the backend (for example `histogram_quantile`); the package never computes a
  percentile from samples.
- Baseline is the existing previous window (yesterday), consistent with the current baseline; spec 004 changes may later
  provide a better baseline and this feature will use whatever `Window` and snapshot the core provides.
- A metric reading is not persisted, except as part of the existing per-run snapshot numbers (numbers only, with the
  standard expiry).
- The Sentry host defaults to Sentry's SaaS URL and is configurable for self-hosted installs; the token needs only
  read scopes for issues.
- Trace ids come from a configured key or field of the log context; if the service does not log one, only the service-level
  search link is available. Trace content is never read.
- Datadog APM links reuse the `links.datadog` application URL already used for logs.
- Merging Sentry and log groups by similarity is explicitly deferred; it needs its own decision and test corpus.
- No writes to Sentry, Prometheus or Grafana (no annotations, no resolve, no assignment) in line with Principle II.


## Owner decisions (2026-10-24)

The new field classes (metric numbers, masked Sentry titles) are additions to the allowlist, not an amendment: each passes the Normalizer and the caps and gets a privacy-corpus test. Defaults confirmed: Sentry issues and log groups stay separate entries; no shipped metric queries; baseline is the previous window until spec 004 modes apply; a metric anomaly only when no Error-or-above log candidate exists, at most 3 per run.
