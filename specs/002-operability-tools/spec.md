# Feature Specification: Operability Tools

**Feature Branch**: `002-operability-tools`

**Created**: 2026-10-23

**Status**: Draft

**Input**: User description: "Operability tools for everrest/watcher: a heartbeat the host can alert on, a `watcher check` doctor command that validates config and credentials without posting, a `--dry-run` mode that writes no state and prints the digest to stdout, a machine-readable `--json` run summary, and purge of expired files in the file state store. Source: docs/audit-2026-10-23.md, section 'Operational features'."

## Overview

Today an Operator learns that watcher works only by reading Slack, and learns that it does not work only by noticing
the absence of messages. A quiet day and a dead scheduler look identical. First runs post real messages, credentials are
tested by hand with `curl`, and a failed run shows only an exit code unless someone reads job logs. This feature gives
the Operator five small tools so that the package can be set up safely, verified on demand, observed without a metrics
stack, and left running unattended.

**Actors**

- **Operator** — the engineer who installs and owns the job for one service.
- **Host monitoring** — whatever already watches the Operator's platform (a Kubernetes alert, a cron monitor, a Prometheus
  rule). It is not part of this package; it only needs something to read.
- **Reader** — the person who reads the Slack digest.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Know that it ran, even when nothing was reported (Priority: P1)

The Operator wants one place to read when each service's watcher last ran successfully and what the outcome was, so that
Host monitoring can alert when the answer is stale. Optionally the Reader gets a short weekly line saying how many runs
were quiet, so a silent channel is distinguishable from a dead job.

**Why this priority**: a dead schedule is the most expensive failure of a watcher: everyone believes things are quiet.

**Independent Test**: run the pipeline with fakes through a quiet, a reported, a skipped and a failed run; read the
heartbeat record after each; advance the clock past the staleness limit and observe the stale answer.

**Acceptance Scenarios**:

1. **Given** a run finishes with any outcome (reported, quiet, baselined, failed), **When** it ends, **Then** a heartbeat
   record holds the time, the outcome, the window and the service name, and is readable without running a new watch.
2. **Given** a trigger that is not due or a window already done, **When** it ends, **Then** the record of the last real
   run is not overwritten (a skip is not evidence that the job works), but the time of the last trigger is recorded.
3. **Given** the heartbeat is older than the staleness limit (default 36 hours for a daily run), **When** an Operator or
   Host monitoring asks for the status, **Then** the answer is "stale" with the age, and the exit code is non-zero.
4. **Given** weekly quiet reports are enabled, **When** a week of runs ends, **Then** one message reports the counts of
   reported, quiet and failed runs and the total cost, and nothing is sent in a week with no runs at all (that case is
   exactly what the heartbeat alert is for).
5. **Given** the heartbeat itself cannot be written, **When** a run ends, **Then** the run's outcome and exit code are
   unchanged and the failure is logged to the host log.

---

### User Story 2 - Verify the setup without posting or spending (Priority: P1)

Before the first scheduled run, and after any credential rotation, the Operator runs one command that checks everything
that can be checked: the configuration, the log source, the model provider, the state store and the Slack destination.
It reports each check as pass or fail with a reason, and does not change anything unless explicitly asked.

**Why this priority**: the smoke-test guide currently asks for six manual `curl` commands and a throwaway channel; most
first-run failures are credential or wiring mistakes that a command can find in seconds.

**Independent Test**: run the command against fakes that fail one dependency at a time; each failure is named, the
exit code is non-zero, and no state changes and no message is posted.

**Acceptance Scenarios**:

1. **Given** a valid configuration, **When** the Operator runs the check, **Then** it reports: configuration valid; log
   source reachable and authorised (a small read-only query for the last five minutes); model provider reachable and the
   three configured model ids known to the provider and priced in the configuration; state store round-trip (write, read
   back an increment, expire); Slack destination syntactically valid; and exits 0.
2. **Given** a wrong log-source credential, **When** the check runs, **Then** it reports the source as failed with the
   short reason, still runs the remaining checks, and exits 1.
3. **Given** the check runs without the posting option, **When** it finishes, **Then** nothing was posted to Slack and no
   model tokens were spent (model checks use only free metadata calls).
4. **Given** the posting option is given explicitly, **When** the check runs, **Then** exactly one clearly labelled test
   message is posted to each configured destination.
5. **Given** any failure, **When** the report is printed, **Then** it contains no secret, no URL with credentials and no
   log content.
6. **Given** the model provider rejects the authentication scheme the package uses while an alternative scheme works,
   **When** the check runs, **Then** it says so explicitly (this is the known unverified assumption of the first release).

---

### User Story 3 - Dry run: see the digest without side effects (Priority: P2)

The Operator wants to run the full pipeline on real logs and read the digest in the terminal, without posting to Slack
and without leaving state behind, so that a first run needs no test channel and a tuning change can be tried safely.

**Why this priority**: it turns "run it and see" from a risky act into a safe one, and makes tuning of thresholds,
mutes and rules a short loop.

**Independent Test**: run the same window once with the dry-run option and once without, on identical fakes; the digest
text is identical, and after the dry run the state store and the notifier are untouched.

**Acceptance Scenarios**:

1. **Given** the dry-run option, **When** a run executes, **Then** the digest and any notices are printed to standard
   output exactly as they would be posted, and nothing is posted.
2. **Given** the dry-run option, **When** a run executes, **Then** no state is written: no snapshot, no run record, no
   verdict, no reported marker, no lock left behind, no spend counter, no alert marker.
3. **Given** the dry-run option, **When** the run needs the previous window's snapshot, **Then** it reads real state
   normally, so the preview reflects what the real run would do.
4. **Given** the dry-run option, **When** model calls are made, **Then** they are real and billed (the digest must be
   real), the printed footer says so, and the day's spend is **not** recorded; an optional estimate-only variant makes
   no model calls and prints what would be sent (counts and token estimates only).
5. **Given** the dry-run option, **When** the window was already reported by a real run, **Then** the dry run still
   executes (it ignores the "already done" record) and says it did.

---

### User Story 4 - A machine-readable run summary (Priority: P2)

The Operator wants every run to leave one structured line that log pipelines and dashboards can parse, instead of
scraping the human-readable line.

**Why this priority**: cheap metrics without a metrics stack; the cost and volume trends are otherwise invisible.

**Independent Test**: run with the JSON option and parse the output; compare fields with the known fake inputs.

**Acceptance Scenarios**:

1. **Given** the JSON option, **When** a run ends, **Then** one line of JSON is printed with: service, outcome, reason
   (if any), window start and end, duration, events read, groups, candidates, findings, model calls per step, tokens in,
   out and cached, estimated cost, today's total, the release, and the package version.
2. **Given** the JSON option, **When** the run fails or the configuration is invalid, **Then** the line is still printed
   (with the outcome and reason) and the exit code is unchanged.
3. **Given** the JSON line, **When** it is inspected, **Then** it contains no secret, no log text, no template and no
   field value: counts, ids of outcomes and numbers only.

---

### User Story 5 - The file state does not grow forever (Priority: P3)

An Operator using the single-runner file state wants expired files removed automatically so the directory does not grow
without bound.

**Why this priority**: low risk but certain to bite on long-lived hosts; the other drivers already expire by themselves.

**Independent Test**: create expired and live state files, run the purge, and check which remain.

**Acceptance Scenarios**:

1. **Given** expired entries in the file state directory, **When** a run starts, **Then** entries past their expiry are
   removed, at most once per day per directory and within a bounded time (default 2 seconds, 5,000 files).
2. **Given** leftover lock sidecar files, **When** the purge runs, **Then** those older than the lock lifetime are removed.
3. **Given** a write that cannot be completed (full disk), **When** a record is saved, **Then** the previous good record is
   kept and the failure is reported, never replaced by an empty file.
4. **Given** files that do not look like watcher state, **When** the purge runs, **Then** they are left alone.

### Edge Cases

- The Operator runs the check before any state exists: the state round-trip uses a clearly named scratch key and removes
  it; the check never touches real run data.
- Two checks at the same time: no interference (scratch keys are unique).
- The heartbeat store and the main state store are the same by design; a failure that makes one unreadable makes both
  unreadable, and the status command reports "unknown", not "ok".
- The dry run is interrupted: because it writes nothing, nothing needs cleaning.
- A host with several services sharing one state store: heartbeat records are per service.
- The clock is wrong on the host: the staleness answer states the times it compared.

## Requirements *(mandatory)*

### Functional Requirements

**Heartbeat**

- **FR-001**: After every run that is due (any outcome), the package MUST record, per service: time of finish, outcome,
  reason, window, duration and cost; this record MUST survive the normal state retention (it is refreshed by each run).
- **FR-002**: A trigger that is skipped (not due, done, locked) MUST NOT overwrite the last-run record; it MAY update a
  separate "last trigger" time.
- **FR-003**: A status command MUST print the last-run record and exit 0 when the last successful-or-quiet run is younger
  than the staleness limit, and non-zero with the age when older or unknown. The limit MUST be configurable (default:
  1.5 x the window).
- **FR-004**: An optional weekly summary message (default off) MUST report counts of reported, quiet, failed and skipped
  runs and the week's total cost, sent once per week to the notice destination, and MUST NOT be sent when the week had no
  due run.
- **FR-005**: Failure to write the heartbeat MUST NOT change a run's outcome or exit code.

**Check command**

- **FR-006**: A `check` command MUST run these checks independently and report each as pass/fail with a short reason:
  configuration validity; log-source access (read-only query over the last five minutes); model-provider access and the
  availability of each configured model id (metadata calls only); presence of a price for each model; state-store
  round-trip including reading back a counter after an increment; notifier destination validity.
- **FR-007**: The check MUST NOT post any message and MUST NOT call any billable model endpoint unless an explicit option
  is passed; with that option it posts one labelled test message per destination.
- **FR-008**: The check MUST exit 0 only if every check passed, and MUST run all checks even after a failure.
- **FR-009**: The check MUST test the provider authentication scheme the package uses and, if it fails, MUST try the
  provider's documented alternative scheme and report which one works.
- **FR-010**: Check output MUST contain no secret, credential-bearing URL, log line or personal value.

**Dry run**

- **FR-011**: A dry-run option MUST execute the full pipeline and print every message that would be posted (digest and
  notices) to standard output, posting nothing.
- **FR-012**: A dry run MUST NOT write any state (all keys listed in User Story 3) and MUST NOT leave a lock; reads of
  existing state (previous snapshot, verdicts, reported markers) MUST work normally.
- **FR-013**: A dry run MUST ignore the run-already-done record and MUST label its output as a dry run.
- **FR-014**: A dry run that calls the model MUST say that the calls were real and billed, and MUST NOT add to the day's
  spend counters or fire budget alerts.
- **FR-015**: An estimate-only variant MUST make no model calls and print, per step, the number of groups and the token
  estimate that would be sent, and the cost estimate at the configured prices.

**JSON summary**

- **FR-016**: A JSON option MUST print exactly one JSON object per run on standard output with the fields of User Story
  4, for every outcome including failures and invalid configuration.
- **FR-017**: The summary MUST contain only counts, numbers, enumerated outcome names, times and identifiers of the
  service and release; it MUST NOT contain log text, templates, field values or secrets.

**File state purge**

- **FR-018**: The file state store MUST remove expired entries and stale lock sidecar files, at most once per day per
  directory, bounded in time and number of files, and MUST ignore files it did not create.
- **FR-019**: A failed write MUST leave the previous record intact.

### Key Entities

- **Heartbeat Record** — per service: last finished run (time, outcome, reason, window, duration, cost), last trigger
  time, and the weekly counters.
- **Check Report** — a list of named checks, each with pass/fail and a short reason, plus an overall result.
- **Run Summary** — the machine-readable record of one run.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A stopped scheduler is detectable by Host monitoring within one staleness limit (36 hours by default) with
  no change to the service being watched, in 100% of simulated stop scenarios.
- **SC-002**: A new Operator can verify a complete setup with one command and read a named cause for every failing
  component, without any manual call to a provider.
- **SC-003**: A dry run leaves the state store and the notifier byte-for-byte untouched in 100% of tested outcomes.
- **SC-004**: The check command makes zero model-token charges and posts zero messages unless the explicit option is
  given.
- **SC-005**: Every run, including failed and invalid-configuration runs, produces a parsable summary with the same
  field names.
- **SC-006**: No secret, personal value or log text appears in any check report, summary or heartbeat record across the
  privacy test corpus (0 occurrences).
- **SC-007**: With the file state, a directory with 5,000 expired entries is cleaned by one run within the time bound and
  never grows past the retention window plus one day.

## Assumptions

- The Operator already has a way to alert on a failing or stale command (a Kubernetes job failure, a cron monitor); this
  package provides the signal, not the alerting.
- The state store the package already requires is also where the heartbeat lives; no new storage is introduced.
- "Free metadata calls" to the model provider (listing models) exist and are not billed.
- The weekly summary is off by default because many teams will not want another message.
- The default staleness limit (1.5 x the window) tolerates one missed trigger.
- The check and the dry run are available in the command line and in the framework command; the framework command takes
  the same options.
- Dry-run model calls are real because an accurate digest requires them; the estimate-only variant exists for people who
  do not want to spend tokens at all.
