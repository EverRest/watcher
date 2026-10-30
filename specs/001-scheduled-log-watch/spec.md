# Feature Specification: Scheduled Log Watch

**Feature Branch**: `001-scheduled-log-watch`

**Created**: 2026-10-23

**Status**: Implemented (T114, the live run, is pending)

**Input**: User description: "everrest/watcher — scheduled, model-routed log analysis PHP package reporting to Slack. Full approved brief: docs/brief.md"

## Overview

A reusable package that a service team installs into their service. On a schedule it reads a time
window of that service's logs from whatever log backend the current environment uses, reduces them
to distinct problem groups, asks an AI model to judge only what is new or unusual, and posts one
digest to Slack. It never changes anything in the service and never lets personal data leave the
service's perimeter. First consumer: `orders-api`; nothing in the package may be specific to it.

### Actors

- **Integrator** — a developer on a service team who installs and configures the package.
- **Reader** — an engineer (on-call, team lead) who reads the Slack digest and decides what to act on.
- **Operator** — whoever owns the environment, its credentials and its AI spend.

## Clarifications

### Session 2026-10-23

- Q: Should spend be counted and announced as it approaches the limit? → A: Yes, mandatory. Every model call adds provider-reported tokens and estimated cost (configured per-model prices, required) to daily per-service counters; crossing 25%, 50%, 75% or 90% of the daily limit sends one notice per threshold per day (highest only when several are crossed in one run).
- Q: Do real logs match the one-line corpus? → A: Not necessarily: frameworks write exceptions over several lines and inside a context field. The package assembles continuation lines into one event and extracts exception class, top frames and file:line (FR-045), because for rare groups whose message text is withheld these are all the model sees.
- Q: How does a Reader silence known noise or an already-handled problem so it stops appearing? → A: A mute list in the service configuration: fingerprints or content rules, each with an optional expiry date; every finding in the digest shows its short fingerprint so it can be copied into the list. Slack-reaction muting is out of scope for v1.
- Q: What does the very first run do when there is no previous window to compare against? → A: Baseline only: store groups and counters, make no AI request, post one short message "baseline recorded: N groups, M events"; full analysis starts from the second run.
- Q: Should a run pull every raw line or let the log backend do the counting? → A: Backend-side counting by level and pattern where the source supports it, plus a small per-group sample read only to derive the template; sources without aggregation fall back to paged raw reading up to a hard event cap, with "truncated" stated in the digest.
- Q: What happens when several replicas of a service fire the hourly trigger at the same time? → A: The package takes a lock per (service, window) in the shared state storage before running; whoever does not get it exits without external calls. The local-file state storage is supported only for a single runner, and this is stated in its configuration documentation.
- Q: Where do service notices (failures, budget exhausted, baseline recorded) go — the digest channel or a separate one? → A: An optional separate notice destination; when not set, notices go to the digest destination.
- Q: How are new or growing groups below the read level (e.g. a burst of info lines) detected? → A: Level anomaly on backend totals: if a level's total grows ≥ growth factor vs the previous window, that level's lines are read in a second pass up to a separate smaller cap and grouped like any other lines.
- Q: What do watch rules match? → A: Explicit form only: `template` (regex on the normalised template), `error_type` (regex), or `field` + `equals`/`matches` on a field value evaluated locally; only the rule name ever leaves the service.
- Q: Free-text names cannot be recognised by patterns — how does message text leave the service safely? → A: Message text leaves only as a template whose variable tokens are masked by cross-event variation within a cluster, and only for clusters with at least `min_group_events_for_text` events (default 3); smaller clusters send level, error type, top frames and counters but no message text.
- Q: Which findings collapse into the "minor" line? → A: `noise` findings, and `warn`/`info` findings with confidence below the confidence threshold; `error`/`critical` findings are never collapsed.
- Q: How does a service's analysis account for the microservices it depends on? → A: Declared dependencies in configuration plus auto-detection from hosts in log templates; when a candidate references a dependency or the dependency errs in the same window, the dependency's totals and top error groups for that window are read and given to the model as context. Undeclared hosts seen in templates are listed in the digest.
- Q: Does the package know the deployed version and the code? → A: It knows the deployed release and commit (from its own environment) and links stack frames to the repository at that commit. Reading code is allowed by the owner but is a separate feature (spec 002), not part of this one.
- Q: What other context is in scope here? → A: A short per-service context text for the model, and a deeplink per finding to the log backend's query view. Kubernetes events, metrics, Sentry, ticket drafts and weekly trends are later specs (see docs/roadmap.md).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Daily digest of new and growing problems (Priority: P1)

A Reader opens Slack in the morning and sees one message for the service: the problems that appeared
or got worse during the last window, each with a severity, a one-line title, short evidence, how often
it happened, the trend versus the previous window, and a labelled hypothesis about the cause and a
suggested next step. Problems that were already there yesterday at the same level are not repeated.

**Why this priority**: This is the product. Today the same result is produced by hand
(`docs/context/logs/backlog.md`, a 15-row backlog assembled from raw pod logs).

**Independent Test**: Feed a fixed set of log events for two consecutive windows into a run with a
stub log source, stub AI and stub Slack; the digest lists exactly the new and grown groups with the
required fields.

**Acceptance Scenarios**:

1. **Given** a window containing a problem type never seen before, **When** the run completes,
   **Then** the digest contains a finding for it with severity, title, evidence, count, trend, a
   cause labelled as a hypothesis, and a suggested action.
2. **Given** a problem type whose count grew by at least the configured growth factor versus the
   previous window, **When** the run completes, **Then** the digest contains it marked as grown.
3. **Given** many events that differ only in ids, numbers or emails, **When** the run completes,
   **Then** they appear as one group with their total count, not as separate findings.
4. **Given** a window in which nothing qualifies as new, grown, severe or tag-matched, **When** the run
   completes, **Then** no message is posted and no AI request is made.
5. **Given** `noise` findings, or `warn`/`info` findings below the confidence threshold (never
   `error`/`critical`), **When** the digest is built,
   **Then** they are collapsed into a single "N minor" line instead of being listed.

---

### User Story 2 - Personal data never leaves the service (Priority: P1)

The Operator must be able to state that no personal data (names, emails, phone numbers, tokens,
request bodies, free-text log context) is sent to the AI provider or to Slack.

**Why this priority**: The first consumer stores personal data of participants; sending it out would
be a GDPR breach. The package cannot be enabled anywhere without this guarantee.

**Independent Test**: Run with logs seeded with known personal values; capture everything handed to
the AI and to Slack; assert none of the seeded values appear and only allowlisted fields are present.

**Acceptance Scenarios**:

1. **Given** log events containing emails, names, phone numbers and access tokens, **When** a run
   prepares data for analysis, **Then** none of those values appear in what is sent to the AI or Slack.
2. **Given** a log field that is not on the allowlist, **When** data is prepared, **Then** that field
   is absent from the outgoing data.
3. **Given** the redaction step fails for an event, **When** data is prepared, **Then** that event's
   affected fields are dropped rather than sent unredacted (fail closed).
4. **Given** a completed run, **When** storage is inspected, **Then** no raw log line has been stored;
   only group fingerprints, counters and findings.

---

### User Story 3 - AI spend stays small and visible (Priority: P2)

The Operator wants the cheapest model that can do each step, a hard daily spending cap per service,
and to see what each run cost.

**Why this priority**: The job runs daily in every service of every environment; an unbounded design
multiplies cost by services × environments × days.

**Independent Test**: Run with a stub AI that records which model tier was requested for which step
and how much input it received; assert the routing table, the caps and the usage line.

**Acceptance Scenarios**:

1. **Given** candidate groups, **When** the run analyses them, **Then** a cheap model first triages
   all of them in one request, and only groups triaged as interesting go to the mid-tier model.
2. **Given** a finding of severity error or higher whose confidence is below the configured threshold,
   **When** analysis completes, **Then** it is re-analysed once by the strong model, and the number of
   such escalations per run does not exceed the configured cap.
3. **Given** a group whose verdict was already produced in an earlier run and whose count has not
   grown, **When** the run executes, **Then** it is not sent to any model again.
4. **Given** the prepared input would exceed the per-step input budget, **When** the run executes,
   **Then** the lowest-priority groups are dropped and the digest states how many were dropped.
5. **Given** the service's daily spend cap is reached, **When** a run executes, **Then** it performs
   at most triage and the digest states that the budget is exhausted.
6. **Given** any run that called a model, **When** the digest is posted, **Then** it ends with one line
   showing input, output and cached usage, the model used per step, and approximate cost.

---

### User Story 4 - One package, any service, any environment (Priority: P2)

An Integrator adds the package to a service and, by configuration only, chooses the log backend for
each environment (Grafana/Loki in one, Datadog in another), which logs to watch (tags), when the job
runs and over what window, which model handles each step, where state is kept, and where the digest
goes.

**Why this priority**: The owner's stated priority is that the package be abstract and flexible;
orders-api environments already differ in log backend.

**Independent Test**: Run the same service build with two configurations pointing at two different
log sources and two different model maps; both produce digests; no code change between them.

**Acceptance Scenarios**:

1. **Given** environment A configured for Grafana/Loki and environment B for Datadog, **When** each
   runs, **Then** each reads from its own backend and produces a digest of the same shape.
2. **Given** stream tags `app=orders-api, env=stg`, **When** a run reads logs, **Then** only logs carrying
   those labels are read.
3. **Given** a watch rule `QuotaTracking` and a warning-level group whose message matches it,
   **When** the run selects candidates, **Then** that group is a candidate even though it is below
   the minimum level.
4. **Given** `run_at` 06:00 in a configured timezone and an hourly host trigger, **When** the host
   triggers at 05:00, **Then** the package exits without external calls; **When** it triggers at
   06:00, **Then** it analyses exactly the configured window ending at that time.
5. **Given** a model map changed in configuration, **When** the next run executes, **Then** the new
   models are used without a package release.
6. **Given** state storage set to key-value cache, local file or relational database, **When** runs
   execute, **Then** deduplication behaves identically for each.
7. **Given** a service on a framework other than the first consumer's, **When** the Integrator invokes
   the package's plain entry point, **Then** it runs without that framework.

---

### User Story 5 - Failures are loud, never silent (Priority: P3)

A Reader must be able to tell "nothing happened" from "the watcher did not work".

**Why this priority**: A silent watcher is worse than none; people stop looking at logs because they
trust it.

**Independent Test**: Make the log source, the AI and the state store fail in turn; each failure
produces a short explicit notice.

**Acceptance Scenarios**:

1. **Given** the log source is unreachable or rejects credentials, **When** the run executes, **Then**
   a short notice names the service, the source and the reason, and the run exits non-successfully.
2. **Given** the AI returns output that does not match the required structure, **When** this happens,
   **Then** the request is retried once; if it fails again, a short "analysis failed: reason" notice
   is posted.
3. **Given** Slack delivery fails, **When** the run ends, **Then** the failure is reported through the
   host's own error output and the run exits non-successfully.

---

### User Story 6 - Findings come with their context (Priority: P2)

A Reader sees, for each finding, whether a dependency misbehaved at the same time, whether the
problem started after a deploy, a link to the exact log query and links to the code lines in its
stack trace — so the first click goes to the cause, not to a search.

**Why this priority**: In the hand-made baseline (`backlog.md`) several High items had their cause in
another service or in configuration (e.g. WT-02, a BFF calling a host that does not exist). Logs of
one service alone give the symptom, not the cause.

**Independent Test**: Two fake services where service A logs connection errors to `http://core-api/...`
and core-api logs a new `Lock wait timeout` group in the same window; plus a release change between
windows. A's digest names core-api with its error count, marks the group "after <release>", and every
finding has a log link and code links at the deployed commit.

**Acceptance Scenarios**:

1. **Given** a declared dependency core-api and a candidate whose template references host `core-api`,
   **When** the run analyses it, **Then** core-api's totals and top error groups for the same window
   are given to the model and the finding names core-api.
2. **Given** a template referencing a host that is not declared as a dependency, **When** the digest
   is built, **Then** it lists "undeclared dependency: <host>".
3. **Given** the release changed since the previous window, **When** a group is new, **Then** the
   finding is marked "first seen after <release>".
4. **Given** a finding with stack frames, **When** the digest is built, **Then** each top frame links
   to the repository file and line at the deployed commit.
5. **Given** any finding, **When** the digest is built, **Then** it carries a link to the log backend
   query for that group and window, and the link contains no personal data.

### Edge Cases

- Window with zero log events (service idle or source returning nothing): distinguish "source returned
  nothing" from "source unreachable"; the first is silent, the second is a notice.
- Very large window (millions of events): counting happens at the backend where possible (FR-036);
  otherwise reading stops at the event cap and the digest says "truncated"; reduction never holds
  all raw events at once.
- Log lines in different formats within one service (plain text lines and JSON): the format is
  detected per line.
- A one-off error whose message contains a person's name: fewer than `min_group_events_for_text`
  events → message withheld, the finding still shows error type, frames and counts (FR-044).
- A burst of lines below the read level (e.g. info): caught by the level anomaly pass (FR-038).
- A dependency seen for the first time (no previous snapshot): no growth trigger, context without
  new/grown marks, snapshot stored (FR-039).
- First ever run, no previous window: baseline only (FR-035) — no AI request, one short "baseline
  recorded" message, so the Reader does not read it as an incident.
- Two runs overlapping (scheduler fires twice, or several replicas fire together): only the lock
  holder runs (FR-028); the others exit without external calls.
- A run crashes while holding the lock: the lock expires and the next window runs normally.
- Clock skew or timezone differences between scheduler, log backend and Slack.
- AI provider down or rate-limited for the whole run: after the client's retries the run fails with
  an "analysis failed: provider unavailable" notice; snapshot is still written so the next window
  compares correctly.
- A single problem group whose message template itself contains personal data in an unusual shape.
- Configuration missing a required value: fail at start with a clear message, not mid-run.

## Requirements *(mandatory)*

### Functional Requirements

#### Reading logs

- **FR-001**: The package MUST read log events for a configured service and time window from a log
  source selected by configuration.
- **FR-002**: The first release MUST support Grafana/Loki and Datadog as log sources; adding another
  source MUST NOT require changes to the rest of the pipeline.
- **FR-003**: The package MUST accept both plain-text and structured (JSON) log lines and normalise them
  to one internal event shape.
- **FR-045**: Log entries that span several lines (a message line followed by stack-trace lines
  such as `#0 /path/File.php(12): ...`) MUST be assembled into one event, and the exception class,
  the top frames (at most 5) and the originating file:line MUST be extracted from the forms real
  frameworks write: a context field `exception` holding `[object] (Class(code: N): message at
  /path/File.php:LINE)` optionally followed by `[stacktrace]` and `#N` lines, and a message of the
  form `Class: message in /path/File.php:LINE`. When nothing can be extracted the event simply has
  no error type and no frames.
- **FR-036**: Totals per level over the window MUST be counted by the backend. Lines that can become
  candidates — level ≥ a configurable read level (default warning) or matching a watch rule — MUST
  be filtered by the backend and read in pages up to a hard per-run event cap (default 200,000), then
  grouped by the package; at most a small sample of lines per group (default 3) is kept in memory to
  derive the template, and it stays inside the service perimeter (FR-009). When the cap is hit the
  digest MUST say "truncated" with the number of events read. Groups below the read level that match
  no watch rule are reflected only in the totals, unless FR-038 applies.
- **FR-038**: If the backend total for a level below the read level grows ≥ growth factor versus the
  previous window, the run MUST read that level's lines in a second pass up to a separate cap
  (default 20,000), group them like other lines, and show one line in the digest
  ("info ×6 vs previous window"). No second pass happens on a first run (FR-035).
- **FR-004**: Access to log sources MUST be read-only; the package MUST NOT perform any write or
  administrative action on any external system other than posting the digest.

#### Reducing and selecting

- **FR-005**: The package MUST group events into problem groups by a fingerprint that ignores variable
  parts (numbers, ids, emails, tokens, timestamps), and record per group: level, normalised message
  template, error type, top stack frames (at most 5), count, first seen, last seen.
- **FR-006**: The package MUST compare each group against the previous window and mark it new, grown
  (count ≥ growth factor × previous), or unchanged.
- **FR-007**: Among the groups read per FR-036, groups MUST become candidates for analysis only if
  new, grown, at or above the configured minimum level, watch-rule-matched, or read in a level-anomaly
  pass (FR-038); plus the top N groups by volume; everything else is reported as one
  summary line with counts.
- **FR-008**: If no group is a candidate, the run MUST make no AI request and post no digest.
- **FR-035**: When no previous window exists for the service (first run, or state expired/cleared),
  the run MUST record groups and counters as the baseline. If no group is at or above the Error
  level, it MUST make no AI request and post one short message "baseline recorded: N groups, M
  events". If any group is at or above the Error level, the run MUST post a digest with only those
  groups (no top-N by volume, no lower levels) and MUST NOT make an AI request for groups that
  were already reported; the baseline is recorded in both cases. Growth and trend comparison
  start from the next run.

#### Privacy

- **FR-009**: Only allowlisted fields (FR-005 attributes and counters) MAY leave the service; raw
  context, request bodies and raw sample lines MUST never be sent.
- **FR-044**: Message text MUST leave the service only as a template mined from a cluster of events:
  tokens whose value varies between events of the same cluster MUST be masked (`<*>`), and the
  template MUST be sent only if the cluster has at least `min_group_events_for_text` events
  (default 3). For smaller clusters the message text MUST be withheld and only level, error type,
  top frames and counters are sent ("message withheld: low volume"). Bare host names (not only URLs)
  follow the same rule as URL hosts (FR-039). Names of people in free text are not pattern-detectable;
  this rule, not pattern matching, is what protects them.
- **FR-010**: A second redaction layer MUST scan outgoing templates for personal-data patterns
  (emails, phone numbers, tokens, card-like numbers, IP addresses) and replace them with typed
  placeholders; the Integrator MUST be able to add patterns and allowlist entries.
- **FR-011**: On any redaction failure the affected field MUST be dropped (fail closed); the AI MUST
  NOT be used to perform redaction.

#### Configuration

- **FR-012**: The Integrator MUST be able to configure two kinds of tags: (a) **stream tags** —
  source labels (e.g. `app=orders-api`, `env=stg`) that scope which logs are read at all; (b) **watch
  rules** — named matchers in explicit form only: `template` (regex on the normalised template),
  `error_type` (regex), or `field` + `equals`/`matches` (evaluated locally on a field value) — that
  make any matching group a candidate for analysis regardless of its level. Watch rules do not filter
  out non-matching groups, carry no severity of their own, and only the rule name ever leaves the
  service. Mute entries (FR-034) use the same matcher form or a fingerprint.
- **FR-013**: The package MUST own the schedule: the Integrator configures the run time (`run_at`,
  with timezone) and the window length (default 24 hours) in the package configuration. The host
  only triggers the package frequently (e.g. hourly); the package decides whether a run is due and
  otherwise exits successfully without any external call. Changing the run time MUST NOT require
  changes to the host scheduler.
- **FR-014**: All settings (source and credentials, tags, schedule/window, minimum level, growth
  factor, top-N, top-M, confidence threshold, escalation cap, state storage, retention, digest and
  notice destinations, model per step, token limits per step, daily spend cap, redaction allowlist
  and patterns, mute list, per-group sample size, per-run event cap, anomaly cap, minimum events for message text, dependencies, dependency domains,
  service context, release/commit, log-link base URLs, repository URL and path mapping) MUST be
  settable per service and per environment without code changes.
- **FR-015**: Invalid or incomplete configuration MUST be rejected before any external call, with a
  message naming the missing or invalid setting.

#### Analysis and model routing

- **FR-016**: Analysis MUST run in up to three steps with separately configured models: triage
  (all candidates in one request, minimal output), deep analysis (only groups triaged interesting,
  at most M), escalation (severity ≥ error and confidence below threshold; at most once per finding
  and at most the configured cap per run).
- **FR-017**: No model identifier may be fixed in the package; every step's model comes from
  configuration, with documented defaults (cheap / mid / strong tier).
- **FR-018**: A verdict produced for a group MUST be reused in later runs while the group's count has
  not grown past the growth factor; such groups MUST NOT be sent to a model again.
- **FR-019**: Input sent to a model MUST be compact: each message template sent once and referenced,
  no redundant formatting, at most 5 stack frames; output length MUST be bounded per field and per
  step.
- **FR-020**: Stable instruction text MUST be structured so the provider can reuse it across requests
  (prompt caching) where the provider supports it.
- **FR-021**: Before each request the package MUST estimate input size; if it exceeds the step's
  budget, lowest-priority groups MUST be dropped and the digest MUST state how many.
- **FR-022**: A hard daily spend cap per service MUST be enforced; when reached, runs perform at most
  triage and the digest MUST say the budget is exhausted.
- **FR-046**: Every run that calls a model MUST add the provider-reported tokens (input, output,
  cache read, cache write) and the estimated cost (configured per-model prices, which are REQUIRED
  for every configured model) to per-service daily counters kept in state. When a counter first
  crosses 25%, 50%, 75% or 90% of its daily limit (cost: `daily_budget_usd`; tokens: optional
  `daily_token_limit`; thresholds configurable) the run MUST send one notice to the notice
  destination naming the counter, the percentage, the absolute value and the limit. Each threshold
  is announced at most once per day and per counter; if one run crosses several, only the highest is
  announced. The digest footer MUST show the day's total and percentage. At 100% FR-022 applies.
- **FR-023**: Model output MUST follow a fixed structure: for each finding the affected groups, kind
  (bug, regression, anomaly, noise), severity (info, warn, error, critical), title, evidence,
  suspected cause, suggested action, confidence (0–1). Output not matching the structure MUST be
  retried once, then reported as an analysis failure.
- **FR-024**: Instructions to the model MUST forbid stating facts not present in the input, and
  suspected cause MUST be presented to the Reader as a hypothesis.

#### Deduplication and state

- **FR-025**: The package MUST remember, per service, group fingerprints, counters, verdicts and what
  was already reported, so the same unchanged problem is not reported on consecutive runs.
- **FR-026**: State storage MUST be selectable among key-value cache, local file and relational
  database, with identical behaviour.
- **FR-027**: Every stored item (fingerprints, counters, verdicts, findings, run records) MUST
  expire; default retention is 30 days, configurable per service.
- **FR-034**: The Integrator MUST be able to configure a mute list per service: entries are
  fingerprints or content rules, each with an optional expiry date. Muted groups MUST NOT become
  candidates, MUST NOT be sent to any model, and MUST be counted in the digest's summary line
  ("N muted"). An expired entry MUST stop applying without any configuration change.
- **FR-028**: A run MUST be idempotent per service and window: a repeated or overlapping run MUST NOT
  post a second digest for the same window. Before any external call the package MUST acquire a lock
  for (service, window) in the state storage; a runner that does not acquire it MUST exit
  successfully without external calls. Locks MUST expire so a crashed run does not block the next
  window. The local-file state storage is valid only for a single runner, and its documentation MUST
  say so.

#### Reporting

- **FR-029**: The package MUST post at most one digest per service per run to a configured Slack
  destination, grouped by severity, each finding showing title, one-sentence evidence, count,
  trend and its short fingerprint (copyable into the mute list, FR-034); `noise` findings and
  `warn`/`info` findings with confidence below the confidence threshold (`error`/`critical` never) collapsed into one line.
- **FR-030**: The digest MUST end with one usage line: input, output and cached usage, model per
  step, approximate cost.
- **FR-031**: Failures of the source, the analysis or the budget MUST produce a short explicit notice
  (see User Story 5); failure to deliver to Slack MUST be surfaced through the host's error
  output and a non-successful exit.
- **FR-037**: Notices (source failure, analysis failure, budget exhausted, baseline recorded) MUST go
  to an optional separate notice destination; when it is not configured they MUST go to the digest
  destination.
- **FR-032**: The digest format MUST be identical across services so several services can share one
  channel.

#### Context

- **FR-039**: The Integrator MUST be able to declare dependencies (name, stream tags, optional source
  override). A host appearing in a log URL MUST be kept in the normalised template as `<URL:host>`
  (path, query, credentials and port removed) only if it is a single DNS label (a cluster service
  name such as `core-api`), a declared dependency host, or ends with a domain in the configured
  `dependency_domains` list (e.g. `svc.cluster.local`, `sendgrid.com`); any other host becomes
  `<URL>` and an IP host becomes `<IP>` (fail closed). Every run MUST read each declared
  dependency's per-level totals for the window (one aggregate query per dependency). When a
  candidate references a declared dependency's host, or the dependency's error-level total grew
  ≥ growth factor versus its previous window, the run MUST also read that dependency's top error
  groups (at most 5 per dependency, read cap default 5,000 lines) for the same window, redact them like the service's own groups, and
  give them to the analysis step as context. Hosts seen in templates that are not declared MUST be
  listed in the digest as "undeclared dependency: <host>". When a dependency has no previous
  snapshot, the growth trigger does not apply, its context (if requested by a host reference) carries
  totals and top error groups without new/grown marks, and its snapshot is stored for the next run. Dependency reads MUST use read-only access
  and count toward the run's token budget.
- **FR-040**: The Integrator MUST be able to set a short service context text (≤ 1,000 characters:
  purpose, key domains, what is critical) that is sent with every analysis request as part of the
  stable prefix; it passes the same redaction scan as templates.
- **FR-041**: The package MUST take the deployed release and commit from configuration (normally the
  host's environment), store them in every window snapshot, show them in the digest header, and mark
  a new group "first seen after <release>" when the release changed since the previous window. If the
  log source exposes a per-line version label, the deploy moment inside a window MUST be taken from it.
- **FR-042**: Every finding MUST carry a deeplink to the log backend's query view (Grafana Explore or
  Datadog Logs) for its group and window, built from configured base URLs, stream tags, level and
  literal (placeholder-free) parts of the template; the link MUST contain no personal data.
- **FR-043**: Top stack frames MUST link to the repository web URL at the deployed commit, using a
  configured path mapping from runtime paths to repository paths (e.g. `/var/www/html/` → `app/`).
  This feature does not read code; code-aware analysis is spec 002.

#### Integration

- **FR-033**: The package MUST be usable from plain PHP through a single entry point and MUST ship an
  adapter that exposes it as a command in the first consumer's framework family (Lumen/Laravel).

### Key Entities

- **Log Event** — one normalised entry from a source: time, level, message, error type, stack frames,
  fields. Transient; never stored.
- **Problem Group** — events sharing a fingerprint: template, level, error type, top frames, count,
  first/last seen, status vs previous window (new / grown / unchanged).
- **Verdict** — the AI's triage or analysis result for a group, reusable across runs while the group
  does not grow.
- **Finding** — a reportable problem: affected groups, kind, severity, title, evidence, suspected
  cause, suggested action, confidence.
- **Run** — one execution for a service and window: candidates, findings, usage, cost, outcome.
- **Watch Configuration** — per service and environment: source, tags, schedule/window, thresholds,
  model map, budgets, state storage, destination, redaction rules.
- **Digest** — the single Slack message produced by a run.
- **Mute Entry** — a fingerprint or content rule in configuration, optional expiry; excludes matching
  groups from analysis and reporting.
- **Dependency** — another service this one calls: name, stream tags, optional source override;
  declared or auto-detected from template hosts.
- **Dependency Context** — for one dependency and window: per-level totals and top error groups
  (redacted), attached to the analysis request.
- **Deploy Info** — release and commit in effect for a window, and the deploy moment if known.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For the first consumer, a daily digest reproduces at least 80% of the High/Med items an
  engineer found by hand for the same window (baseline: `backlog.md`, 2026-10-07).
- **SC-002**: Zero seeded personal values appear in anything sent to the AI or Slack across the privacy
  test corpus (100% of seeded values removed).
- **SC-003**: On a window with no new or grown problems, the run makes zero AI requests.
- **SC-004**: A typical daily run for one service costs less than US$0.10 and never exceeds the
  configured daily cap.
- **SC-005**: An unchanged problem is reported once, not on every subsequent day (repeat rate 0% while
  count is stable).
- **SC-006**: A new service is onboarded (installed, configured, first digest posted) in under 30
  minutes by an Integrator following the documentation.
- **SC-007**: Switching the log backend or a model for an environment requires only configuration
  changes (0 code changes).
- **SC-008**: Every failed run (source, analysis, budget, delivery) is visible to a Reader or Operator
  within the same run; 0 silent failures in the failure test matrix.
- **SC-010**: In the dependency test matrix, 100% of findings whose cause is in a declared dependency
  name that dependency, and 100% of undeclared hosts are listed.
- **SC-011**: Every finding in a digest has a log link that is well-formed per the link fixture for
  the configured backend, and every stack frame with a mapped
  path has a code link to the deployed commit.
- **SC-009**: Readers rate at least 70% of listed findings as worth reading (not noise) after two weeks
  of use on the first consumer.

## Assumptions

- The package targets PHP ^8.1 services; the first consumer runs PHP 8.2 on Lumen 10.
- Package identity: `everrest/watcher`, hosted in the owner's `EverRest` organisation (not the company
  GitLab, for now).
- Embedded per service: each service runs its own job with its own credentials and its own spend cap;
  a central multi-service runner is out of scope.
- The host provides a scheduler (Kubernetes CronJob or the framework's scheduler).
- AI provider is Anthropic; default model tiers are Haiku-class (triage), Sonnet-class (analysis),
  Opus-class (escalation), all overridable.
- Slack delivery uses an incoming webhook by default (the first consumer already has one configured).
- Log source credentials are read-only and provided per environment by the Operator.
- Default thresholds (overridable): growth factor 3×, top-N 50, top-M 10, confidence threshold 0.6,
  escalation cap 3 per run, minimum level error.
- Code reading by the model is approved by the owner (2026-10-23) and specified separately as
  spec 002; this feature only links to code. Later specs are listed in `docs/roadmap.md`.
- Release and commit are provided to the service's runtime environment by its CI (e.g.
  `WATCHER_RELEASE`, `WATCHER_COMMIT`).
- Out of scope for v1: automatic tickets, any action on services, storing raw logs, a UI, the
  provider's batch processing mode, Sentry and Splunk sources (to be added as further sources).
