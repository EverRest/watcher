# Feature Specification: Incident Workflow

**Feature Branch**: `005-incident-workflow`

**Created**: 2026-10-23

**Status**: Draft

**Input**: User description: "Incident workflow for everrest/watcher: remember which fingerprint is already tracked in a ticket and stop re-analysing it; ready-to-paste ticket drafts (no creation in the tracker); Slack threads with updates and interactive mute/acknowledge buttons; routing by severity to different destinations; additional notification channels; a weekly report; a run triggered right after a release; and an on-demand 'what changed in the last N hours' run. Source: docs/audit-2026-10-23.md, features 4, 5, 10, 11, 12, 13, 14, 15. Writes to external systems stay out of scope unless the constitution is amended (Principle II): draft text only, buttons only change the package's own state."

## Overview

A digest tells the Reader that something is wrong. What happens next is manual and repetitive: the same problem is
re-described every morning even though a ticket exists, the Reader copies evidence into a ticket by hand, a muted
problem needs a configuration change and a deployment, urgent and routine findings land in the same channel, and nobody
looks at the digest right after a release, when it matters most. This feature closes that gap **without writing to any
monitored or tracking system**: it remembers links to tickets, drafts text for people to paste, and changes only the
package's own state when someone presses a button.

**Actors**

- **Reader** — reads digests, acknowledges, mutes, files tickets by hand.
- **Operator** — configures destinations, routing and the optional chat integration.
- **Service owner** — wants only their own service's findings, urgent ones loudly.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A problem that is already tracked stops costing attention (Priority: P1)

A Reader links a finding's fingerprint to a ticket key. From then on the finding shows the key, is not analysed again by
the model while it stays at its usual size, and reappears prominently when it grows or when the ticket is marked
resolved and the problem returns.

**Why this priority**: a daily digest that repeats known problems is ignored within a week; known problems are also the
largest avoidable model spend.

**Independent Test**: link a fingerprint to a key, run on two windows (stable, then grown); the first shows the key and
makes no model call for it, the second reports it as grown with the key.

**Acceptance Scenarios**:

1. **Given** a fingerprint linked to a ticket key, **When** the group is present and not grown, **Then** the digest shows
   it in a compact "known" section with the key and makes no model call for it.
2. **Given** a linked group grows by the growth factor, **When** the run executes, **Then** it is reported as a finding
   ("known, but grown") with the key.
3. **Given** a link marked resolved, **When** the group appears again, **Then** it is reported as a regression with the
   key, and the link returns to active.
4. **Given** a link with an expiry (default 30 days without the problem being seen), **When** the expiry passes, **Then**
   the link is dropped and the group is treated normally.
5. **Given** an Operator adds or removes a link in configuration or through the interactive control, **When** the next
   run executes, **Then** the change applies, and the source of each link (configuration or control) is visible.
6. **Given** the Operator provides read-only search access to the tracker (optional), **When** a new finding appears,
   **Then** up to three existing tickets whose text matches the finding's masked terms are suggested, labelled as
   suggestions.

---

### User Story 2 - A ticket draft ready to paste (Priority: P1)

For any finding the Reader can obtain a complete ticket text: title, severity, what was seen (counts, trend, window,
release), a hypothesis labelled as such, suggested next steps, links to logs and code, and the fingerprint. The package
never creates the ticket.

**Why this priority**: it removes the slowest manual step between "I saw it" and "someone is on it".

**Independent Test**: request the draft for a finding; it contains every listed element and no personal value; no
network call to any tracker is made.

**Acceptance Scenarios**:

1. **Given** a finding, **When** a draft is requested (in the digest, in the thread, or on the command line), **Then**
   the draft contains the elements listed above in a fixed layout, in plain text suitable for a tracker's description.
2. **Given** the draft, **When** it is examined, **Then** it contains only data already allowed to leave the service and
   no secret.
3. **Given** a finding that links to an origin in another service, **When** a draft is built, **Then** it names both and
   suggests the owning team if the service has an owner configured.
4. **Given** the tracker's field conventions differ, **When** the Operator sets a template for drafts, **Then** the
   template (with named placeholders) is used.

---

### User Story 3 - Findings live in a thread (Priority: P2)

With a chat integration that can post to threads, each finding is one thread: later runs add "still happening, +N events"
or "no longer seen" to the same thread instead of a new line in a new digest, and the thread offers buttons to
acknowledge, mute for a period, link a ticket, or request the draft.

**Why this priority**: it turns the digest from a daily broadcast into a place to work, and mute/acknowledge without a
code change or deployment is the most requested noise control.

**Independent Test**: simulate two runs and a button press; the second run updates the existing thread; pressing mute
makes the next run skip the group and record who and until when.

**Acceptance Scenarios**:

1. **Given** a finding was posted earlier and is still present, **When** the next run executes, **Then** an update is
   added to the same thread, not a new top-level message.
2. **Given** a finding is no longer seen for the configured number of runs, **When** the run executes, **Then** the thread
   gets a "resolved?" update once.
3. **Given** a button press to mute for a period, **When** the interaction arrives, **Then** the package's own state is
   updated (fingerprint, until, who, from where), the next run honours it, and the thread confirms it. **Nothing** in any
   monitored or tracking system changes.
4. **Given** a button press from a person who is not allowed to change state, **When** it arrives, **Then** it is
   refused with a visible message and nothing changes.
5. **Given** an interaction request whose signature or age is invalid, **When** it arrives, **Then** it is rejected and
   nothing changes.
6. **Given** the interactive integration is not configured, **When** a run executes, **Then** the package behaves as in
   the first release (one digest message, no threads, no buttons).

---

### User Story 4 - Urgent things reach the right place (Priority: P2)

The Operator routes findings by severity and by service: critical ones to an on-call destination (optionally with a
mention), the rest to the team channel, and quiet days to nothing. Findings can also go to other channels than Slack.

**Why this priority**: critical problems must not wait for someone to open a daily message; routine ones must not wake
anyone.

**Independent Test**: a run with one critical and two warning findings posts the critical one to the on-call destination
and the others to the team destination, each exactly once.

**Acceptance Scenarios**:

1. **Given** routing rules by severity, **When** a digest is built, **Then** each finding goes to the destination of its
   severity, and a destination with nothing to post receives nothing.
2. **Given** a critical finding and an on-call destination with a mention, **When** it is posted, **Then** the mention
   is configured text from the Operator, never text produced by the model.
3. **Given** another notification channel is configured (a generic webhook, a chat tool other than Slack, e-mail),
   **When** a run executes, **Then** the same content is delivered there through the same contract, and a failure in one
   channel does not block the others.
4. **Given** the generic webhook is used, **When** it receives a payload, **Then** the payload is a documented,
   versioned structure containing only data allowed to leave the service.

---

### User Story 5 - Look closely when it matters: after a release and on demand (Priority: P2)

After a release the package compares the hour or two that follows with the same period before and reports regressions
within the hour instead of the next morning. At any time a person can ask "what changed in the last N hours" and get an
answer in the terminal or in a thread.

**Why this priority**: the first hours after a release are when regressions are cheapest to fix and the daily rhythm is
slowest to see them.

**Independent Test**: change the release value between two triggers; the post-release run compares the two periods and
reports a regression that the daily run would not have shown for a day; the on-demand run over two hours returns the same
structure.

**Acceptance Scenarios**:

1. **Given** the release value of a service changed since the last trigger, **When** the next trigger arrives, **Then** a
   post-release run executes for the period since the change, compares it with the same length before the change, and
   reports only new and grown groups, labelled as post-release.
2. **Given** post-release runs are enabled, **When** several releases happen in a day, **Then** each produces at most one
   post-release run and the daily run is unaffected.
3. **Given** an on-demand request for the last N hours (command line, or a chat command if the integration allows),
   **When** it runs, **Then** it returns the findings for that period, never writes the daily run record, respects the
   budget, and says that it is an ad hoc run.
4. **Given** the budget is nearly spent, **When** a post-release or on-demand run executes, **Then** it follows the same
   caps as any run.

---

### User Story 6 - A weekly picture (Priority: P3)

Once a week the Reader gets one report: the problems that recurred, the problems that disappeared after a release, the
noisiest sources, and the model spend per service.

**Why this priority**: it shows whether things are getting better and whether the tool earns its cost.

**Independent Test**: simulate a week of runs; the report lists recurring, resolved and noisiest items with figures that
match the stored history.

**Acceptance Scenarios**:

1. **Given** the weekly report is enabled, **When** the week ends, **Then** one message is sent with: top recurring
   fingerprints (days seen), groups that stopped after a release (with the release), the highest-volume sources, tokens
   and cost per service, and the number of acknowledged, muted and linked items.
2. **Given** no due run happened in the week, **When** the week ends, **Then** nothing is sent.
3. **Given** the report is built, **When** it is examined, **Then** it contains only masked text, counts and identifiers.

### Edge Cases

- The same fingerprint is linked to two tickets: both are shown, the older first.
- A button press arrives after the finding's thread was archived or the message deleted: the state change still applies,
  the confirmation falls back to a new message.
- Two people press different buttons at once: the later one wins and both are recorded.
- The chat platform is down during a run: findings are delivered to the next available channel by the failure rules of
  the first release; thread updates are retried on the next run.
- A link expires while the problem is still present: it is reported as a normal finding with a note that the link expired.
- A release is detected for a service that has not yet had a baseline: no post-release run.
- The on-demand period is longer than the log source can serve: it is cut and says so.
- Mute entries created through the control and entries from configuration overlap: the stricter (longer) applies and both
  are shown.

## Requirements *(mandatory)*

### Functional Requirements

**Known issues**

- **FR-001**: The package MUST keep links between a fingerprint and one or more ticket keys, each with a source
  (configuration, control), a creation time, an expiry (default 30 days without the problem seen) and a status
  (active, resolved).
- **FR-002**: A group with an active link MUST be shown in a compact "known" section with its key and MUST NOT be sent to
  any model while its count stays below the growth factor times the count at link time.
- **FR-003**: A linked group that grows past that threshold MUST be reported as "known, grown"; a group whose link is
  resolved and that reappears MUST be reported as a regression and the link MUST return to active.
- **FR-004**: An optional read-only tracker search MAY suggest up to three existing tickets for a new finding using only
  masked terms; suggestions MUST be labelled and MUST NOT create or change anything in the tracker.

**Drafts**

- **FR-005**: For any finding the package MUST be able to produce a ticket draft in plain text with the fixed elements:
  title, severity, evidence with counts, trend, window and release, labelled hypothesis, suggested steps, log and code
  links, fingerprint, and (if configured) the owning team.
- **FR-006**: Draft production MUST make no network call to a tracker and MUST contain only data already allowed to leave
  the service.
- **FR-007**: The Operator MUST be able to supply a draft template with named placeholders.

**Threads and interaction**

- **FR-008**: When a chat integration with thread support is configured, each finding MUST be posted once as a thread
  root and later runs MUST add updates to that thread ("still present, +N", "no longer seen", "grown") instead of new
  top-level messages; when it is not configured the first-release behaviour MUST be unchanged.
- **FR-009**: Interactive controls MUST offer: acknowledge, mute for a chosen period, link a ticket key, show the draft.
  Pressing a control MUST change only the package's own state and MUST record who, when and from where.
- **FR-010**: Interaction requests MUST be authenticated (signature and age check), MUST be refused for people not on the
  Operator's list of allowed actors, and MUST be idempotent.
- **FR-011**: The package MUST record thread identifiers per fingerprint with the same retention as other state.

**Routing and channels**

- **FR-012**: The Operator MUST be able to route findings by severity (and optionally by service) to different
  destinations; a destination with nothing to post MUST receive nothing.
- **FR-013**: Mentions MUST be configured text only; model-written text MUST NOT be able to produce a mention or link.
- **FR-014**: Additional channels (a generic webhook with a documented, versioned payload, another chat tool, e-mail)
  MUST be selectable per destination through the same notification contract; a failure of one MUST NOT block the others.

**Post-release and on-demand runs**

- **FR-015**: When the release value of a service differs from the one stored at the last trigger, the package MUST be
  able to run a post-release comparison (period since the change vs the same length before it) reporting only new and
  grown groups, at most once per release, without affecting the daily run.
- **FR-016**: An ad hoc run for the last N hours MUST be available on the command line (and in chat when enabled); it
  MUST NOT write the daily run record or the daily baseline, MUST respect budgets and caps, and MUST label its output.

**Weekly report**

- **FR-017**: An optional weekly report MUST list recurring problems, problems that stopped after a release, the noisiest
  sources, and tokens and cost per service, using stored history only, masked data only, and MUST NOT be sent for a week
  without due runs.

**Boundaries**

- **FR-018**: No feature in this specification MAY create, change or delete anything in a monitored system or in a ticket
  tracker; any such write requires a constitution amendment before it is specified.

### Key Entities

- **Ticket Link** — fingerprint, ticket key(s), source, status, created, expires, count at link time.
- **Thread Record** — fingerprint, destination, thread identifier, last update, state.
- **Mute Record (control)** — fingerprint, until, who, from where, when.
- **Route** — severity (and service) mapped to a destination with optional configured mention.
- **Post-Release Run** — release old/new, period compared, result.
- **Ticket Draft** — rendered text for one finding.
- **Weekly Report** — aggregated counts for one week.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A problem linked to a ticket produces 0 model calls and at most one compact line per day while stable, and
  is reported prominently within one run of growing past the threshold.
- **SC-002**: A Reader obtains a complete ticket draft for any finding in one action, and in testing 100% of drafts
  contain every required element and 0 personal values or secrets.
- **SC-003**: Muting a problem takes one button press and no code change or deployment, and is honoured by the next run
  in 100% of tested cases; 100% of unauthenticated or unauthorised interactions are rejected with no state change.
- **SC-004**: A critical finding reaches its on-call destination in the same run it is detected; a day with only routine
  findings sends nothing to the on-call destination.
- **SC-005**: A regression introduced by a release is reported within one hour of the trigger following the release,
  versus up to a day without this feature.
- **SC-006**: Thread updates keep the number of top-level messages per day at or below the number of new findings.
- **SC-007**: Across the privacy test corpus, drafts, thread updates, generic payloads and the weekly report contain 0
  seeded personal values.
- **SC-008**: No test in this feature performs a write to a tracker or monitored system (0 outbound calls other than to
  the configured notification channels and optional read-only search).

## Assumptions

- The chat integration for threads and buttons needs a bot-level credential with a way to receive interaction requests;
  without it the package behaves as in the first release. How interaction requests reach the package (a small endpoint,
  a socket connection, or a queue) is a planning decision; this specification only requires that they are authenticated
  and change nothing but the package's own state.
- Ticket keys are opaque text (for example `ABC-123`); the package does not need to know the tracker's API except for
  the optional read-only search.
- The list of allowed actors for state changes is configured by the Operator (chat user ids or groups).
- Post-release runs use the release value the package already reads from the environment.
- Default routing is "everything to the digest destination", i.e. unchanged behaviour.
- The weekly report is off by default.
- Ticket creation, tracker comments and any other write to external systems are explicitly out of scope until the
  constitution is amended.
