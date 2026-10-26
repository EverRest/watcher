# Feature Specification: Incident Workflow (v1, no inbound endpoint, no writes to trackers)

**Feature Branch**: `005-incident-workflow`

**Created**: 2026-10-23 — **Rewritten**: 2026-10-24

**Status**: Phases 1-3 implemented in 0.2.1; weekly report deferred. tasks.md checkboxes are not yet reconciled with the code

**Input**: Owner request 2026-10-24: spec 005 without Slack buttons. v1 contains no inbound endpoint, no buttons, no
threads and no write to any tracker. Only: (1) memory of known issues (fingerprint -> ticket key from configuration,
shown as `known: ABC-123`, no repeated analysis); (2) ticket drafts as text (title, evidence, counters); (3) routing by
severity to different destinations (the drivers already exist: Slack, Telegram, Discord, spec 011); (4) a weekly report
built from the events of spec 013. Buttons and threads are a separate deferred item that needs an amendment to
Principle II.

## Overview

A digest says something is wrong. What follows is manual and repetitive: the same problem is re-described and re-analysed
every morning although a ticket exists, the Reader retypes evidence into a ticket, urgent and routine findings share one
channel, and nobody sees a trend. This feature closes that gap **using only what Principle II already allows**: the
package posts messages to configured notifiers and writes its own state. It reads its own event file (spec 013). It
receives nothing from the network and changes nothing in a tracker, chat or monitored system.

**Actors**

- **Reader** — reads digests, copies drafts into a tracker by hand, records the ticket key in configuration (through the
  Operator).
- **Operator** — owns the configuration: known issues, routes, drafts, report.
- **Service owner** — wants the urgent findings of their service in their own channel.

## Scope change from the first draft (2026-10-23)

| First-draft item | v1 |
|---|---|
| US1 known-issue links (config **and** interactive control) | **Kept, configuration only.** No control, so no `source` field and no "mute from chat". |
| FR-004 optional read-only tracker search | **Deferred** (D2): a tracker credential is outside the "log sources read-only" wording of Principle II. |
| US2 ticket drafts, with an Operator template | **Kept, fixed layout**, delivered as messages. Template deferred (D5). |
| US3 threads, buttons, interaction endpoint | **Deferred** (D1): needs an amendment to Principle II. |
| US4 routing by severity | **Kept.** Mentions dropped (D4). Generic webhook / e-mail channels dropped: spec 011 covers chat drivers, spec 013's `http` sink covers machines. |
| US5 post-release run, on-demand run | **Removed from 005** (D6). They need no amendment; they depend on spec 014 and get their own spec. |
| US6 weekly report | **Kept**, built from spec 013 events, not from new state. "Acknowledged" is dropped: there is nothing to acknowledge without buttons. |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A problem that is already tracked stops costing attention (Priority: P1)

The Operator lists a fingerprint with its ticket key under `known_issues`. From then on the digest shows the group in one
compact line (`known: ABC-123`), the group is not sent to any model while it stays near its size when first seen, and it
comes back as a normal finding when it grows or when the entry is marked resolved and the problem returns.

**Why this priority**: a digest that repeats known problems is ignored within a week; known problems are also the largest
avoidable model spend.

**Independent Test**: configure one entry; run on three windows (stable, grown, entry resolved). Window 1: key shown, 0
model calls for the group. Window 2: reported as a finding "known, but grown" with the key. Window 3: reported as a
regression with the key.

**Acceptance Scenarios**:

1. **Given** an open entry and a group with that fingerprint whose count is below `selection.growth_factor` times the
   count recorded the first time it was seen, **When** the run executes, **Then** the group is shown only in the compact
   known line (key, level, count), is not a candidate, and no triage or analysis call is made for it.
2. **Given** the same group at or above that threshold, **When** the run executes, **Then** it is a candidate with reason
   `known_grown`, goes through the normal pipeline, and its finding shows `known, grown · ABC-123`; the reference count is not
   recomputed (O5), so it is reported again on every run while the count stays at or above the threshold.
3. **Given** an entry with `status: resolved` and the group appears, **When** the run executes, **Then** the group is
   handled by the normal pipeline and its finding shows `regression of ABC-123 (marked resolved)`. The Operator removes
   or reopens the entry; the package never edits configuration.
4. **Given** an entry with `until` in the past, **When** the group appears, **Then** it is handled normally and a note
   `link ABC-123 expired` is shown.
5. **Given** the group was not seen for `state.retention` (default 30 days), **When** it appears again, **Then** the
   reference count restarts from the count of that window.
6. **Given** a fingerprint is both muted and listed as known, **Then** mute wins (the group leaves first, as today) and
   the known line does not show it.
7. **Given** `known_issues` is empty or its flag is off, **Then** behaviour and output equal the previous release.

---

### User Story 2 - A ticket draft ready to paste (Priority: P1)

For findings worth a ticket, the package posts one plain-text draft per finding to a configured destination: title,
severity, evidence, counters, window, release, hypothesis labelled as such, next step, how to check, links and
fingerprint. The package never contacts a tracker.

**Why this priority**: it removes the slowest manual step between "I saw it" and "someone is on it", and it needs no new
credential.

**Independent Test**: end-to-end run with two error findings and one warning; drafts are sent for the two errors only, each
containing every element of contracts/ticket-draft.md, no seeded personal value, no call to anything but the notifier.

**Acceptance Scenarios**:

1. **Given** `drafts.enabled` and findings at or above `drafts.min_severity` (default `error`), **When** the digest has been
   delivered, **Then** one message per finding is sent to the drafts destination, in the fixed layout, as a preformatted
   block so it pastes cleanly.
2. **Given** more such findings than `drafts.max_per_run` (default 5), **Then** the highest severity first (then higher
   count, then fingerprint) are sent and the digest says `N more drafts not sent`.
3. **Given** a finding whose group has an open known-issue entry (a `known_grown` one), **Then** the draft is titled
   `Update on ABC-123 — ...` and names the key; a finding without an entry gets no key line.
4. **Given** the same window is retried after a partial failure, **Then** no draft is sent twice.
5. **Given** a draft cannot be delivered, **Then** a notice names the draft count and the HTTP status class; the digest
   outcome is unchanged.
6. **Given** `--dry-run`, **Then** the drafts are printed after `== DRY RUN drafts ==` and nothing is sent or stored.
7. **Given** model text containing markup, fences, links or mentions, **Then** it is inert in every driver (spec 011 rules)
   and cannot close the preformatted block.

---

### User Story 3 - Urgent things reach the right place (Priority: P2)

The Operator routes findings by severity: critical and error ones to one destination (any driver), warnings to the team
channel, and quiet days to nothing extra.

**Why this priority**: critical problems must not wait for someone to open a daily message; routine ones must not wake
anyone.

**Independent Test**: one critical, one error and two warn findings with a route for `critical, error` to a second
destination: the second destination receives one message with two findings; the default digest receives the warnings and
a line saying two findings were routed; each finding appears in exactly one place.

**Acceptance Scenarios**:

1. **Given** `notify.routes` with entries `{name, severity: [...], to: <destination>}` in order and a last catch-all entry
   `{name, to?}` (no `severity`; `to` omitted = the default digest destination), **When** a digest is built, **Then** each
   finding goes to the first route, in configuration order, whose severity list contains its severity, otherwise to the
   catch-all. Routes without a catch-all are rejected at start. A finding that would still match nothing (defence in depth)
   goes to the default digest destination with a notice, never dropped.
2. **Given** a route with no matching finding, **Then** it receives nothing (no empty message).
3. **Given** findings were routed to a destination other than the default digest destination, **Then** the default digest
   shows `N finding(s) routed to <route name>` (the name is configured text), so a Reader of the default channel is never
   misled; findings the catch-all sends to the default destination need no such line.
4. **Given** a route destination fails, **Then** a delivery-failure notice is posted and the other destinations are still
   attempted; on a retry of the same window a destination that already received its message is not posted to again.
5. **Given** no `notify.routes`, **Then** output and delivery equal the previous release.
6. **Given** a non-catch-all route names the same destination as the default digest or as another route, **Then** it is
   rejected at start (a route to the same place changes nothing and hides a mistake).

---

### User Story 4 - A weekly picture from the event stream (Priority: P3)

Once a week the Reader gets one report: the groups that recurred on most days, the groups that stopped after a release,
the noisiest groups, model spend per service, and how many known issues were seen. It is built only from spec 013 events
in the operator's `file` sink.

**Why this priority**: it shows whether things get better and whether the tool earns its cost, with no new state.

**Independent Test**: a fixture event file covering one ISO week (7 days, 2 releases); the report lists recurring, stopped
and noisiest groups and cost totals that equal hand-computed figures; a second invocation for the same week sends nothing.

**Acceptance Scenarios**:

1. **Given** `report.weekly.enabled` and the 013 `file` sink with `retention_days` of at least 8, **When**
   `watcher report --weekly` runs (the host scheduler calls it weekly), **Then** one message for the last complete ISO week is sent
   to `report.weekly.destination` (default: the notice destination), with the blocks of contracts/weekly-report.md.
2. **Given** the report for that week was already sent, **When** the command runs again, **Then** nothing is sent and the
   exit code is 0, with `report <week> already sent` on stderr.
3. **Given** no run event with outcome reported, quiet or baselined in the week, **Then** nothing is sent and the command
   says so on stderr (exit 0).
4. **Given** the sink file is missing, unreadable, or its retention is below 8 days, **Then** the command fails loudly
   (non-zero exit, message naming the setting) and sends nothing.
5. **Given** lines that are not valid `watcher.event.v1`, **Then** they are skipped and counted, and the report says
   `N lines skipped`.
6. **Given** a file written with `include_text` on, **Then** the report still contains no text from events: only
   fingerprints, levels, counts, release names, configured ticket keys and service names.
7. **Given** `--dry-run`, **Then** the report is printed and neither sent nor marked sent.

### Edge Cases

- A group seen in 5 of 7 days but outside the top-N of most runs: the report covers only the groups the file contains
  (`observe.top_groups`); it says so in one line.
- A fingerprint in `known_issues` that never occurs: nothing is shown; `check` reports entries not seen for
  `state.retention` as an informational line.
- Two entries for one fingerprint: both keys are shown, in configuration order.
- Fleet: `known_issues`, `drafts`, `notify.routes` and `report` are per service and a service entry replaces the shared
  block **as a whole** (the fleet merge uses `array_replace_recursive`, which would otherwise merge two lists element by
  element). With `digest: combined`, `notify.routes` is rejected at start (`notify.routes is not supported with digest combined`):
  the combined digest is built from collected service messages and has no per-severity destinations in v1.
- Reference count of a known group is written only after the digest is delivered, like verdicts (`BufferedStateStore`).
- A finding with several fingerprints, one of them known: it is known only if **every** fingerprint is known and below
  threshold; otherwise it follows the normal path and shows the keys it has.
- Draft requested for a finding whose model text was dropped by the output scrub: the field is omitted, not blanked with a
  placeholder that looks like data.
- The report week crosses a DST change or a year boundary: ISO weeks in UTC; documented.

## Requirements *(mandatory)*

### Functional Requirements

**Known issues**

- **FR-001**: `known_issues` is a list of `{fingerprint, ticket, status?, until?}` (contracts/config.md). `fingerprint`
  is 12 to 64 lowercase hex characters and matches a group whose fingerprint **starts with** it (the digest prints only the first 12; `mute[].fingerprint` is an exact full match, so its rule cannot be reused); `ticket` is opaque text validated by pattern; `status` is `open` (default) or
  `resolved`; `until` is a UTC date, inclusive, as for `mute`.
- **FR-002**: For each group matching an open, unexpired entry the package MUST keep a reference count in its own state
  (key with expiry `state.retention`, renewed on each sighting). The group MUST NOT become a candidate while
  `count < selection.growth_factor * reference`.
- **FR-003**: At or above that threshold the group MUST become a candidate with reason `known_grown`. The reference count is
  written once, at first sight, and MUST NOT be recomputed or raised afterwards (owner decision O5); it is dropped only by
  expiry (`state.retention` without a sighting) or by the Operator removing the entry.
- **FR-004**: A matching `resolved` entry, or an expired one, MUST NOT suppress anything; the finding carries the label of
  contracts/known-issues.md.
- **FR-005**: Known groups MUST be shown in one compact line (cap 10 entries plus `N more`) with ticket key, level and
  count; they MUST be counted in the JSON summary (`known`) and MUST remain in the snapshot so growth is comparable.
- **FR-006**: Suppression by a known entry MUST be code-decided from configuration and counts (Principle IV); the model
  never sees, selects or influences it. Ticket keys are configured text and MUST NOT enter any model input, any state value,
  any spec-013 event or any log line; they appear only in the known line, finding labels, drafts and the report's join.
- **FR-007**: Configuration errors (bad key pattern, bad fingerprint, unknown `status`, duplicate pair) MUST fail at start
  naming the key path and never the value.

**Drafts**

- **FR-010**: A draft MUST be built by code from the typed `Finding`, the group counters, the deploy release, the existing
  link builders and the known-issue key; layout fixed (contracts/ticket-draft.md); no model call.
- **FR-011**: A draft MUST contain only data already allowed in the digest. It MUST be sent as a `Message` to a notifier
  destination and MUST NOT cause any other network call.
- **FR-012**: Drafts are sent after the digest, once per `(service, window, fingerprint)` (marker with expiry, written
  after delivery), at most `drafts.max_per_run`, ordered deterministically.
- **FR-013**: A draft failure MUST be loud (a notice with counts and status class) and MUST NOT change the run outcome.
- **FR-014**: A new preformatted message section kind (`draft`) MUST be rendered by the Slack, Telegram and Discord
  formatters so that model text cannot end the block, open markup or ping anyone (extends spec 011 corpus).

**Routing**

- **FR-020**: `notify.routes` is an ordered list of `{name, severity, to}`; `to` has the shape of `notify.digest` and is
  validated by the same code. First matching route in configuration order wins. The last entry MUST be a catch-all (no
  `severity`; `to` optional, omitted = the default digest destination); without it start fails. Anything unmatched at runtime
  fails closed to the default digest destination with a notice (owner decision O3).
- **FR-021**: A route message carries the header and the routed finding sections only (no stats, cost, notices or footer);
  the default digest keeps everything else plus the `routed` line.
- **FR-022**: Delivery of each destination is independent; a delivered marker per `(service, window, route index)`
  prevents a repeat on retry. A failure of one destination MUST NOT stop the others and MUST produce a notice.
- **FR-023**: Route `name` is configured text used in the digest line; no destination value, token or chat id ever appears
  in a message, notice, JSON summary or `check` output (spec 011 FR-011 extended).
- **FR-024**: With no routes the code path and output MUST be byte-identical to the previous release.

**Weekly report**

- **FR-029**: **Dependency, not assumption**: the weekly report requires spec 013 (`FileSink`, `watcher.event.v1`, fields
  listed in contracts/weekly-report.md). Until 013 is implemented the report tasks are blocked and `report.weekly.enabled`
  is rejected at start with `report.weekly needs spec 013 (observe.file)`; known issues, drafts and routes do not depend on 013.
- **FR-030**: `watcher report --weekly [--week=YYYY-Www] [--dry-run] [--json]` reads the `observe.file` JSONL files (and rotations)
  and builds the report of contracts/weekly-report.md. It reads no log source and no state store except the sent marker.
- **FR-031**: Parsing MUST use a field allowlist per event type and the 013 patterns; unknown fields, text fields and
  invalid lines are dropped and counted, never passed through (Principle I).
- **FR-032**: The report MUST be sent at most once per ISO week and service@environment (marker with expiry 14 days), to
  `report.weekly.destination`, and MUST NOT be sent for a week without runs.
- **FR-033**: `report.weekly.enabled` defaults to **false**; enabling it requires `observe.file.enabled` and
  `observe.file.retention_days >= 8`, validated at start.
- **FR-034**: Everything in the report is derived from events; the join with `known_issues` uses configuration only.

**Boundaries**

- **FR-040**: v1 MUST NOT open a listening socket, accept inbound requests, create or change anything in a tracker, chat
  thread or monitored system, or hold a credential beyond the existing notifier destinations. Any such addition requires a
  ratified amendment first (D1, D2).
- **FR-041**: Each feature has its own flag per spec 012 (`known_issues.enabled`, `drafts.enabled`, `notify.routes_enabled`,
  `report.weekly.enabled`) and `WATCHER_*` variables; defaults are today's behaviour. The master switch `enabled` false
  makes `report` return `disabled` like the other commands, and the new flags are registered in the flags architecture test.
- **FR-042**: The privacy corpus is extended in the same change as the first outbound text (drafts, route messages, report).

### Key Entities

- **Known Issue** — fingerprint, ticket key, status, optional `until`; plus state `Known Reference` (count, last seen).
- **Known Group Line** — the compact digest line.
- **Ticket Draft** — rendered text for one finding.
- **Route** — name, severity set, destination.
- **Weekly Report** — aggregates of one ISO week for one service@environment.

## Success Criteria *(mandatory)*

- **SC-001**: A group matching an open entry and below threshold produces 0 triage and 0 analysis calls and at most one
  compact line per day; at the threshold it is reported within the same run (token-budget fixture: known fixture uses fewer
  tokens than the same input without entries; no scenario uses more).
- **SC-002**: Across the draft corpus 100% of drafts contain every element of contracts/ticket-draft.md that the finding
  has, and 0 seeded personal values, secrets or live markup/mentions.
- **SC-003**: With routes configured, every finding is delivered to exactly one destination; a retry never repeats a
  delivered destination; with no routes output is byte-identical (golden test).
- **SC-004**: The weekly report for the fixture week matches hand-computed figures (recurring, stopped, noisiest, cost) and
  a second run for the same week sends 0 messages.
- **SC-005**: Across the privacy test corpus drafts, route messages, known lines and the report contain 0 seeded personal
  values and 0 credentials; no destination value appears in any output (spec 011 corpus extended).
- **SC-006**: No test in this feature performs a network call other than to a faked notifier; no listening socket exists
  (architecture test pins the allowed IO).

## Assumptions

- Ticket keys are opaque (`ABC-123`, `#4521`); the package does not know any tracker.
- The Operator learns a fingerprint from the digest (12 hex characters) and records it in configuration; changing
  configuration is a deployment, which is accepted for v1 (that is the cost of having no control channel).
- Spec 011 is implemented (drivers). Spec 013 is a hard dependency of the weekly report only (FR-029); known issues, drafts and routes do
  not depend on it. Spec 012's flag pattern applies. Spec 014 (several runs per day) changes windows, not this feature:
  known and draft markers are keyed by window end, so they work per run.
- The notice destination already exists and is the default for drafts and the report.

## Deferred (not in v1)

- **D1 — Buttons, threads, an interaction endpoint** (acknowledge, mute, link a ticket from chat). Needs an amendment to
  Principle II (MINOR): "receiving authenticated interaction requests that change only the package's own state". It must
  also settle: a bot credential with write scope, a listening surface (endpoint, socket or queue), signature and age
  checks, allowed actors, idempotency, retention of thread ids, and a Principle I review of inbound text. See research.md R9.
- **D2 — Read-only tracker search** and any tracker call: needs a ratified rule for tracker credentials.
- **D3 — Ticket creation or comments in a tracker**: out of scope until an amendment adds them.
- **D4 — Mentions** in route messages: spec 011 sends Discord `allowed_mentions: {parse: []}` and neutralises Slack text, so
  a mention cannot work today; revisit with a configured-mention mechanism.
- **D5 — Operator draft templates** with placeholders.
- **D6 — Post-release run and on-demand "last N hours" run**: own spec after 014; roadmap row to be added by the owner.
- **D7 — Generic webhook and e-mail notifiers**: covered for machines by spec 013's `http` sink; e-mail unspecified.

## Owner decisions (2026-10-24)

Given by the integrator on the owner's behalf; they replace the assumed defaults of the first review.

- **O1** The weekly report is a separate command, `watcher report --weekly` (Laravel: `watcher:report --weekly`), not part of `run` (research R7).
- **O2** Ticket drafts are separate messages, never inside the digest (R5).
- **O3** Routes are first-match-wins in configuration order; a catch-all last route is required when routes are enabled; anything unmatched fails closed to the default digest destination (R6).
- **O4** A new `draft` section kind is accepted in the Slack, Telegram and Discord formatters, through the existing escapers (R5).
- **O5** The known-issue reference count is taken at first sight and is not recomputed (R2).
- **D6** Post-release and on-demand runs are not in 005; spec 014 owns schedules; the integrator adds the roadmap row.
