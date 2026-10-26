# Feature Specification: Log Hygiene Reports

**Feature Branch**: `006-log-hygiene`

**Created**: 2026-10-23

**Status**: Implemented

**Input**: User description: "Log hygiene reports for everrest/watcher: because the package reads raw log lines locally it can measure, without sending anything out, which log call sites leak personal data, which log sites are noisy or badly shaped, and what the model spend is per service and day; it reports these to the owning team and proposes mute and watch rules from observed data. Source: docs/audit-2026-10-23.md, features 16, 17, 18, and the rollout survey findings (recipient e-mail logged at INFO, SSO user JSON at ERROR, audit JSON with e-mail and IP)."

## Overview

The package already reads every log line of a service inside the service's own environment, masks it, and sends only
masked groups out. That position is unusual and valuable: it can **measure things about the logs themselves** that nobody
else can without copying them. The survey of the first three services found real examples: a recipient e-mail written
at INFO level, a full user record written at ERROR level, an audit log with e-mail, name and IP on every request,
stack traces pasted into messages, access-log lines tagged as errors. Each of these is invisible until someone reads
raw logs. This feature turns the package's local view into reports for the owning team, **without any raw value ever
leaving the process**: the reports contain call-site identifiers, counts and categories only.

**Actors**

- **Service owner** — owns the code that writes the logs and receives the reports.
- **Operator** — enables reports, sets thresholds, decides who receives them.
- **Data protection reviewer** — wants evidence of where personal data is written to logs, with counts, not examples.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Find the places that write personal data into logs (Priority: P1)

The Service owner receives a periodic report that lists the log call sites (the place in the code that writes the line,
or the message template when the place is unknown) that were seen to contain personal-data patterns, with the kind of
data, the level, how often, and since when, and never the data itself.

**Why this priority**: it addresses the cause of the privacy risk instead of only masking its effect, and it is the evidence
a data-protection review asks for.

**Independent Test**: feed a log stream in which three known call sites write e-mails, tokens and names; the report lists
exactly those three with the right categories and counts, and no seeded value appears in the report.

**Acceptance Scenarios**:

1. **Given** lines whose masking replaced one or more personal-data patterns, **When** the window is processed, **Then**
   each call site (file and line when the line carries them, otherwise the masked template) is counted per category
   (e-mail, token, address, phone-like number, card-like number, host name, other pattern) and per level.
2. **Given** the report, **When** it is examined, **Then** it contains only: call-site identifier, category, level,
   count, first and last seen, and a trend; it contains no value, no example line, no template text beyond what is
   already allowed to leave the service, and no field content.
3. **Given** a call site that is clean, **When** the report is built, **Then** it is not listed.
4. **Given** the same site was reported before, **When** the next report is built, **Then** it shows the trend (new,
   growing, stable, gone after a release) and does not repeat unchanged sites more than once a week.
5. **Given** names inside repeated messages (the case patterns cannot see), **When** a cluster's words vary in a way that
   suggests personal data (many distinct values at one position of a stable sentence), **Then** that position is listed
   as "variable free text, possible personal data" with the count of distinct values, never the values.
6. **Given** a site that logs at INFO or DEBUG level a structure with personal fields, **When** it is found, **Then** its
   level is shown so the owner can judge exposure, and levels above the read level are included through the lower-level
   pass already described for anomalies.

---

### User Story 2 - Find noisy and badly shaped log sites (Priority: P2)

The Service owner receives a report of log habits that make everything else harder: stack traces pasted into messages,
lines longer than a limit, a single site that produces most of the volume at INFO level, access-log lines that arrive as
errors, mixed formats in one stream, and messages whose varying part is so large that grouping fails.

**Why this priority**: noise costs reading time and model tokens every day; this tells the owner exactly what to change
at the source.

**Independent Test**: a synthetic stream with one of each habit produces one entry per habit, each with its measure; a
clean stream produces an empty report.

**Acceptance Scenarios**:

1. **Given** a window of lines, **When** it is processed, **Then** the report lists, with counts and shares of volume:
   sites producing more than a configurable share of all lines; lines longer than a configurable length; messages
   containing multi-line traces; lines in a format different from the service's dominant format; and lines whose level
   disagrees with their content class (access-log lines marked as errors).
2. **Given** a noisy site, **When** the entry is built, **Then** it carries a proposed rule (mute, or lower level at the
   source) as text for the owner, never applied automatically.
3. **Given** no habit crosses its threshold, **When** the report is due, **Then** nothing is sent.
4. **Given** the report, **When** it is examined, **Then** it contains no message text beyond masked templates already
   allowed to leave the service.

---

### User Story 3 - Proposed mute and watch rules from observed data (Priority: P2)

From what the package has seen over a period, it proposes mute entries (stable, high-volume, never-severe families) and
watch rules (families that repeatedly preceded a severe event), as ready-to-paste configuration, with the evidence and the
effect on cost and noise.

**Why this priority**: configuration is where the value of the digest is tuned; proposing it from data replaces guesses.

**Independent Test**: simulate 14 days of history with a stable noisy family and a repeating precursor; the proposal
contains a mute for the first and, for the second, a watch rule *when its masked template may be shown* (see FR-010a); each with
its evidence; applying the mute in a replay
removes the family from candidates.

**Acceptance Scenarios**:

1. **Given** at least 14 days of history, **When** proposals are requested, **Then** each proposal is a configuration
   fragment with the fingerprint or matcher, the observed volume, the number of days seen, the model tokens it consumed,
   and the expected saving.
2. **Given** a proposal, **When** it is applied in a replay of the stored history, **Then** the replay shows the effect
   (candidates removed, tokens saved) before the owner applies it.
3. **Given** a family that was ever severe, **When** proposals are built, **Then** it is never proposed for muting.
4. **Given** the owner declines a proposal, **When** it is marked declined, **Then** it is not proposed again for 90 days.
5. **Given** any proposal, **When** it is examined, **Then** the package has not changed its own configuration; applying
   is a manual act.

---

### User Story 4 - Know what the watcher costs, per service and per day (Priority: P2)

The Operator receives, on demand and in the weekly material, the model tokens and cost per service and per day, with the
top spenders (which families consumed the most tokens) and the trend, so spend is explained and not just capped.

**Why this priority**: caps protect the budget but do not tell anyone why it is being spent.

**Independent Test**: simulate a week with known token counts per service; the report equals the sum, ranks the families
by tokens, and flags a day that exceeded twice the weekly median.

**Acceptance Scenarios**:

1. **Given** stored spend counters and run records, **When** a cost report is requested, **Then** it shows per service per
   day: tokens in, out, cached, estimated cost, number of calls per step, and the share of the day's cap used.
2. **Given** the report, **When** the top spenders are listed, **Then** they are identified by fingerprint and masked
   template (already allowed to leave the service), with tokens and number of runs they were analysed in.
3. **Given** a day with spend over twice the median of the previous days, **When** the report is built, **Then** the day is
   flagged with the most likely causes (new families, escalations, re-triage after growth).
4. **Given** the cost report is exported, **When** it is examined, **Then** it is machine-readable with stable field names.

### Edge Cases

- A call site cannot be identified (no file or line): the masked template stands in, marked as such.
- A site changes line number after a release: it is matched by template and file so it is not reported as new.
- The same site writes both clean and personal-data lines: it is listed with the share of lines affected.
- Reports are enabled on a very large service: all measurements are made while streaming and keep memory bounded; they
  may sample above a configurable event count and say that they did.
- A hygiene finding itself would contain personal data (for example, the site identifier is a path with a name in it):
  the identifier goes through the same masking before it is stored or reported.
- History shorter than needed for proposals: the report says how many more days are needed and proposes nothing.
- Hygiene measurements add model tokens: they must not; they are computed in code only.

## Requirements *(mandatory)*

### Functional Requirements

**Measurement**

- **FR-001**: While processing a window, the package MUST be able to count, per call site (file and line when present,
  otherwise the masked template), per level and per category, how many lines contained a personal-data pattern that the
  masking replaced, without storing or emitting the matched value.
- **FR-002**: The package MUST detect variable free text in otherwise stable messages (many distinct values at one
  position of a stable sentence) and report the position and the number of distinct values, never the values.
- **FR-003**: The package MUST measure, per window and service: volume share by call site, line length distribution and
  count over a configurable limit, share of lines with multi-line traces, format mix, and level/content mismatches.
- **FR-004**: All measurements MUST be computed in code during the existing read, MUST NOT call any model, and MUST keep
  memory bounded (sampling above a configurable event count, with a statement that sampling occurred).

**Reports**

- **FR-005**: The package MUST be able to produce a privacy-hygiene report, a log-shape report, a rule-proposal report and
  a cost report, on demand and, if enabled, on a schedule to a configured destination.
- **FR-006**: Every report MUST contain only identifiers (call site, fingerprint), categories, levels, counts, shares,
  times, trends and masked text that is already allowed to leave the service; it MUST NOT contain any matched value,
  example line, field content or secret. Call-site identifiers MUST pass the same masking as any other text.
- **FR-007**: A site MUST be reported at most once a week unchanged; changes (new, growing, gone) MUST be reported when
  they occur.
- **FR-008**: A report with nothing to say MUST NOT be sent.
- **FR-009**: Reports MUST be machine-readable with documented, versioned field names in addition to the chat form.

**Proposals**

- **FR-010**: With at least 14 days of history, the package MUST propose mute entries (stable, high-volume, never severe)
  and watch rules (repeated precursors of severe events) as configuration fragments with evidence and estimated effect.
- **FR-010a** (privacy floor, review of lane B): a masked template leaves the service (report, notice, stored record, watch fragment) only
  when it generalises at least `hygiene.variable_distinct` different messages, and each literal word of it is common log vocabulary or
  was seen at `hygiene.literal_min_sites` different sites in the same pass; every other word is shown as `<w>`. A constant message
  (one that never varies) therefore has no label and no watch proposal, because it may be one person's name. The operator can opt in with
  `hygiene.allow_constant_labels: true` (the word floor still applies). Mute proposals never need the text: they carry a fingerprint only.
  The guard of FR-011 also covers a message whose text is withheld, through a stored hash of its masked template.
- **FR-011**: A family that has ever been severe within the retained history MUST NOT be proposed for muting.
- **FR-012**: The package MUST be able to replay a proposal against stored history and show the effect before it is
  applied; it MUST NOT change its own configuration.
- **FR-013**: A declined proposal MUST NOT be proposed again for a configurable period (default 90 days).

**Cost**

- **FR-014**: A cost report MUST show, per service and day, tokens (in, out, cached), estimated cost, calls per step and
  the share of the cap used, and MUST list the top spending families by fingerprint and masked template.
- **FR-015**: A day whose spend exceeds a configurable multiple (default 2x) of the median of the previous days MUST be
  flagged with likely causes derived from stored run records.

**History**

- **FR-016**: The package MUST store the aggregated counters that reports need (per site, per category, per day) with the
  same retention as other state; these aggregates MUST contain no values or text beyond what reports may contain.

### Key Entities

- **Call Site** — the place a log line comes from: file and line when present, else a masked template; with a stable
  identifier.
- **Hygiene Finding** — a call site with a category (personal data, noise, shape), level, count, share, trend.
- **Variable Position** — a position in a stable message with many distinct values (count only).
- **Rule Proposal** — a configuration fragment, its evidence, replay result and status (open, applied, declined).
- **Cost Day** — per service per day token and cost counters with call counts per step.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the privacy corpus, 100% of seeded call sites that write personal-data patterns are listed with the right
  category, and 0 seeded values appear in any report, aggregate or machine-readable output.
- **SC-002**: On a synthetic stream with one instance of each habit, the log-shape report lists each habit exactly once with
  correct counts, and a clean stream yields an empty report that is not sent.
- **SC-003**: Applying an accepted mute proposal in a replay reduces candidate count and model tokens by the amount the
  proposal stated, within 5%.
- **SC-004**: No family that was ever severe in the retained history is proposed for muting (0 violations in the test
  matrix).
- **SC-005**: The measurements add no model calls and less than 10% to the time of a run on the 200,000-event test window.
- **SC-006**: A cost report equals the sum of recorded runs for the period (0 discrepancy in tests) and flags 100% of days
  above the configured multiple.
- **SC-007**: A data-protection reviewer can answer "which log sites write personal data, how often, since when" from the
  report alone, without access to raw logs.

## Assumptions

- Reports go to the Operator's configured destination; the owning team is a destination or a mention configured by the
  Operator (the package does not look up code ownership).
- Call-site identification relies on information present in the log line (file and line from exception text or context
  keys such as `file`/`line`); where absent, the masked template is the identifier.
- The measurements reuse the masking table and the grouping already in the package; no new detection of personal data is
  introduced in this feature beyond the "variable free text" signal.
- Fourteen days of history is enough to propose a rule; it fits within the default 30-day retention.
- Reports are off by default; the cost report on demand is always available.
- Applying a proposal is a manual configuration change by the owner; automation of that step is out of scope.
- "Never severe" means no occurrence at or above the configured candidate level in the retained history.
