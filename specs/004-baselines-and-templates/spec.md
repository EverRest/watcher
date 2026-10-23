# Feature Specification: Smarter Baselines and Better Templates

**Feature Branch**: `004-baselines-and-templates`

**Created**: 2026-10-23

**Status**: Draft

**Input**: User description: "Smarter baselines and better message templates for everrest/watcher: compare against a rolling window and the same weekday instead of only yesterday; keep a last-good-snapshot pointer so a missed day or a daylight-saving change does not force a new baseline; group messages by similarity instead of exact length so unrelated problems stop collapsing into fully masked templates; optionally use the log backend's own pattern detection. Source: docs/audit-2026-10-23.md, features 6 and 7, and the reliability findings on daylight saving and transient failures."

## Overview

Two weaknesses decide how useful a daily digest is, and the audit found both.

**Comparison.** Each window is compared only with the one before it. Monday looks "grown" because Sunday was quiet,
a holiday looks like an incident, and any missed run or clock change throws the comparison away: the next run records a
new baseline instead of reporting, so one transient failure costs two days of coverage.

**Message grouping.** Messages are grouped when they have the same level, error type, number of words and first stack
frame. Unrelated problems that happen to have the same length share a group and are masked to `<*> <*> <*>`; the model
sees nothing useful. Meanwhile rare problems have no text at all by design (privacy), so the quality of the grouped text
for the common problems matters even more.

This feature makes the comparison robust and the grouped text informative, **without weakening any privacy guarantee**.

**Actors**

- **Reader** — reads the digest and wants "new" and "grown" to mean something.
- **Operator** — owns the job; wants a missed trigger or a clock change to cost nothing.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A missed day or a clock change does not reset the comparison (Priority: P1)

When the previous window is missing (a failed run, a skipped day, daylight-saving change), the run compares against the
newest earlier window that exists, says so, and carries on; it never silently starts over.

**Why this priority**: today one transient failure costs two days of coverage, and every daylight-saving change forces
a new baseline twice a year for every service in the affected time zones.

**Independent Test**: run three consecutive windows with the middle one failed, and a window pair across a daylight-saving
change; in both cases the later run reports normally against the newest earlier snapshot.

**Acceptance Scenarios**:

1. **Given** the previous window has no snapshot but an older one exists within the allowed age, **When** the run
   executes, **Then** it compares against the newest earlier snapshot, normalises counts to the same duration, and the
   digest says "compared with the window ending <time>".
2. **Given** no snapshot is within the allowed age (default 7 days), **When** the run executes, **Then** it records a
   baseline as it does today.
3. **Given** the local clock changes between two runs, **When** the windows are computed, **Then** each window still
   starts where the previous one ended (no gap, no overlap, no second baseline), and the due hour is evaluated once per
   local day, including the day with a repeated hour.
4. **Given** a transient failure of the log source or the model provider, **When** it happens, **Then** the package
   retries a bounded number of times with waiting before it gives up, and a run that still fails writes no snapshot but
   leaves the older ones usable for the next run.
5. **Given** several snapshots exist, **When** a window is stored, **Then** a pointer to the newest good snapshot is
   updated, and a failed run never moves it.

---

### User Story 2 - Compare with what is normal for this day (Priority: P1)

The Operator can choose how "grown" is decided: against the previous window (today's behaviour), against the average of
the last N windows, or against the same weekday in earlier weeks, so that weekly rhythms do not produce false alarms.

**Why this priority**: it removes the most common class of false "grown" findings (Monday after a quiet weekend) and
lets real regressions stand out.

**Independent Test**: feed a weekly pattern (quiet weekends, busy weekdays) with a constant per-weekday level; the
previous-window mode flags Mondays, the weekday mode flags nothing; then add a real 4x jump and both modes flag it.

**Acceptance Scenarios**:

1. **Given** the weekday mode and at least 3 earlier same-weekday snapshots, **When** the run executes, **Then** a
   group is "grown" only if its count is the growth factor times the median of those days, and the digest names the
   mode used.
2. **Given** the rolling mode with N windows, **When** fewer than N exist, **Then** it uses the ones available if there
   are at least 2, otherwise it falls back to the previous window.
3. **Given** a group never seen in any retained snapshot, **When** the run executes, **Then** it is "new" in every mode.
4. **Given** a group that appears on a rarely busy day and is absent on other days, **When** the weekday mode applies,
   **Then** its status says it is expected for this weekday and it is not reported as new unless it grew.
5. **Given** any mode, **When** the retention period is shorter than the comparison needs, **Then** configuration
   validation says so before any call.
6. **Given** a holiday or an incident day was marked as an exception by the Operator, **When** later windows are
   compared, **Then** that day is excluded from the average.

---

### User Story 3 - Group messages by similarity, not by length (Priority: P1)

Messages are grouped when they are alike in wording, so that one problem becomes one group with a readable template and
unrelated problems stop collapsing into fully masked text.

**Why this priority**: it makes the common, privacy-safe part of the digest informative; today many templates are
all placeholders.

**Independent Test**: a stream mixing three distinct problems of the same word count, each with varying ids and names,
yields three groups, each with a readable template; the privacy corpus still yields no personal value in any template.

**Acceptance Scenarios**:

1. **Given** events with the same words in most positions and different values in a few, **When** they are grouped,
   **Then** they form one group and only the positions that vary are masked.
2. **Given** unrelated messages with the same number of words, **When** they are grouped, **Then** they form separate
   groups and keep their distinguishing words.
3. **Given** a group of fewer events than the configured minimum, **When** its text would be sent, **Then** the text is
   still withheld, exactly as before.
4. **Given** the privacy corpus, **When** the new grouping runs, **Then** the number of seeded personal values in any
   outgoing template is zero, including names that vary inside a group and names that repeat across a retry loop of fewer
   than the minimum size.
5. **Given** the same events in any order, **When** they are grouped, **Then** the groups and templates are identical.
6. **Given** the fingerprint of a group, **When** the grouping changes, **Then** the fingerprint stays stable across
   volume changes (a problem keeps its identity when its daily count drops), and a one-time migration note explains that
   fingerprints change once on upgrade.

---

### User Story 4 - Use the log backend's own pattern detection when it exists (Priority: P3)

Where the log backend offers pattern detection, the Operator can enable it as an additional source of grouping hints
for very large windows, falling back to the built-in grouping otherwise.

**Why this priority**: it lowers the cost of reading very large windows, but it depends on features that are not
enabled everywhere and was not verified against a live system, so it is optional.

**Independent Test**: with a fake backend that returns patterns, the groups use them; with one that does not, the
built-in grouping is used and nothing fails.

**Acceptance Scenarios**:

1. **Given** the option is on and the backend returns patterns, **When** the run executes, **Then** patterns are used as
   hints only and every outgoing template still passes the same masking and minimum-size rules.
2. **Given** the option is on and the backend does not support it or fails, **When** the run executes, **Then** the
   built-in grouping is used and the digest is unaffected.
3. **Given** patterns come from the backend, **When** they are examined, **Then** their text is treated as untrusted
   log content (masked and scanned like any other message).

### Edge Cases

- The first run ever: baseline, as today.
- Two windows of different length (the Operator changed the window): counts are normalised to events per hour for
  comparison, and the digest says so.
- A very large number of distinct messages: grouping stays within a memory and time budget and reports "truncated" rather
  than failing.
- A group splits into two after an upgrade of the grouping rules: both are new once; documented as part of the migration.
- A message in a language with no spaces: grouping falls back to character-level similarity or to the built-in rules and
  never fails.
- The weekday history contains a deployment that changed volumes permanently: the Operator can reset the history for a
  service.

## Requirements *(mandatory)*

### Functional Requirements

**Baselines**

- **FR-001**: The package MUST keep a pointer to the newest good snapshot per service, updated only by successful runs.
- **FR-002**: When the previous window's snapshot is missing, the package MUST compare against the newest snapshot not
  older than the maximum comparison age (default 7 days), normalise counts to the same duration, and state in the digest
  which window was used; otherwise it MUST record a baseline.
- **FR-003**: Window boundaries MUST be computed in the service's local time so that each window starts where the previous
  one ended across clock changes, and the due hour MUST be evaluated once per local day.
- **FR-004**: The log source and the model provider MUST be retried a bounded number of times with waiting on transient
  failures (server errors, connection errors, rate limits) before a run fails; the total waiting MUST respect a run time
  budget below the lock lifetime.
- **FR-005**: The comparison mode MUST be configurable per service: previous window (default), rolling average of the
  last N windows, or same weekday (median of the last N same-weekday windows); the digest MUST name the mode and the
  windows used.
- **FR-006**: A group MUST be "new" if absent from all retained comparison windows, and "grown" only if its count is at
  least the growth factor times the comparison value for the mode; the existing growth factor setting applies.
- **FR-007**: The Operator MUST be able to mark a window as an exception (holiday, incident) so that it is excluded from
  averages, and to reset a service's comparison history.
- **FR-008**: Configuration validation MUST reject a mode whose required history exceeds the state retention period.

**Grouping**

- **FR-009**: Messages MUST be grouped by similarity of their masked words (same level and error type, same first stack
  frame, and a similarity above a configurable threshold) rather than by equal word count; positions that vary within a
  group MUST be masked, positions that do not MUST be kept.
- **FR-010**: Grouping MUST be deterministic (independent of input order), bounded in memory and time, and MUST report
  truncation instead of failing when its bounds are reached.
- **FR-011**: Every rule of the first release that protects personal data MUST continue to hold unchanged: minimum group
  size for sending text, masking of varying positions, the pattern table, the second scan, withholding on failure.
- **FR-012**: A group's fingerprint MUST remain independent of its volume and of the grouping similarity, so that a
  problem keeps its identity when its count changes; if the fingerprint definition changes in this feature, the change
  MUST be documented as a one-time migration with a statement of its effect on comparisons.
- **FR-013**: The privacy test corpus MUST be extended with cases that target the new grouping (unrelated same-length
  messages, names varying inside a group, names repeating in a retry loop) and MUST show zero seeded values in any
  outgoing template.

**Backend patterns (optional)**

- **FR-014**: An option, off by default, MUST allow using the log backend's pattern detection as grouping hints; absence
  or failure of the backend feature MUST fall back to the built-in grouping without error, and backend-provided text MUST
  be masked and scanned as untrusted.

### Key Entities

- **Snapshot Pointer** — per service, the newest good window.
- **Comparison Set** — the windows used to decide new/grown for a run, with the mode and their end times.
- **Exception Window** — a window excluded from averages by the Operator.
- **Message Group** — events alike in wording; carries a template with only the varying positions masked.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A single missed or failed run, or a clock change, never causes a new baseline when an earlier snapshot
  within the maximum age exists (0 unwanted baselines in the scenario matrix).
- **SC-002**: On a synthetic weekly pattern with constant per-weekday levels, the weekday mode raises 0 false "grown"
  findings while the previous-window mode raises at least one, and both detect a genuine fourfold increase.
- **SC-003**: On the sample logs of the three first target services, at least 80% of groups with the minimum number of
  events carry a template with at least half of its words readable (today a large share are fully masked).
- **SC-004**: The privacy corpus shows 0 seeded personal values in outgoing templates, with the new grouping and for
  every comparison mode.
- **SC-005**: Grouping 200,000 events with 100,000 distinct messages stays within 256 MB of memory and 60 seconds, or
  reports truncation.
- **SC-006**: A transient 5xx or connection failure of the log source is absorbed by retries in at least 95% of simulated
  single-failure cases without a failed run.
- **SC-007**: No change in the number of model calls for an unchanged workload; better grouping must not increase cost
  by more than 10%.

## Assumptions

- Snapshots are already stored per window with their counts and totals; the new modes read more of them within the
  existing retention (30 days covers four same-weekday comparisons).
- The default comparison mode stays "previous window" so existing behaviour does not change without a decision.
- The maximum comparison age defaults to 7 days.
- Similarity is computed on already-masked words only, never on raw text.
- Backend pattern detection is not enabled in every environment and is unverified against live systems; it is optional
  and off by default.
- The one-time effect on fingerprints (if any) is accepted: the first run after an upgrade may report existing problems
  as new once.
