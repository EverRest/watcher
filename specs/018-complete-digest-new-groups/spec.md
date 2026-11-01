# Feature Specification: Complete Digest and Immediate New-Group Alerts

**Feature Branch**: `feat/018-digest-complete`

**Created**: 2026-11-01

**Status**: Draft

**Target release**: next MINOR after 0.14.0 (set at release time)

**Input**: User description: "Check that the daily digest always shows ALL errors of the previous day, and that a new error is sent out of turn at the same time, without waiting for the digest. Answer: both are not true today. Do both."

## Clarifications

### Session 2026-11-01

- Q: When the digest has more groups than fit into one message, what happens? → A: Split it into several messages. Every flagged group is listed; nothing is replaced by a count only.
- Q: Which new groups are sent out of turn? → A: Every new group at `selection.min_level` or higher (`error` by default), once per fingerprint.
- Q: When is the out-of-turn message sent? → A: In the same run that found the group, before the digest. It is not held for the digest, and it is not sent in a separate run.

## Current behaviour (the gap)

- The digest lists only the groups that reach `DigestRenderer::render`. It caps the list at `DigestRenderer::MAX_SECTIONS` (40 sections). Findings beyond the cap are moved to a `minor` count and not listed.
- Only the `selection.top_m` groups (default 10) are analysed. The rest appear as a count (`N group(s) flagged ... not analysed (top_m cut)`), without a line each.
- A new group has no message of its own. It is one line in the digest.
- `alerts.immediate` exists, but it only fires for configured log-line matches (`alerts.immediate` rules), is off by default, and is evaluated during the run that reads the logs.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Every flagged group is in the digest (Priority: P1)

An operator reads the daily digest and sees one line for every group that the selection flagged at `selection.min_level` or higher in the window, not only the top few. When the list does not fit into one message, the digest continues in the next message(s), each with a `part i/n` header.

**Why this priority**: The digest is the record of the day. A group that is missing from it looks like it did not happen.

**Independent Test**: A window with 60 flagged groups produces a digest of several messages whose group lines together contain all 60 fingerprints, each once.

**Acceptance Scenarios**:

1. **Given** 60 flagged groups and the 40-section limit, **When** the digest is sent, **Then** the messages together list all 60 groups, and the header of each message says `part i/n`.
2. **Given** a group that is not analysed (outside `top_m`), **When** the digest is sent, **Then** it still has its own line with counts and a log link, marked as not analysed. It is not folded into a count.
3. **Given** a group whose confidence is below the threshold, **When** the digest is sent, **Then** it is still listed (as a minor line with its counts). It is not dropped.

### User Story 2 - A new group is sent out of turn (Priority: P1)

When a group first appears in this window at `selection.min_level` or higher, an operator receives one message for it in the same run, before the digest. The group is announced once. The digest of the same run still lists it.

**Why this priority**: The user expects a new error to arrive when it happens in the watched window, not at the end of the day.

**Independent Test**: A run that sees one new `error` fingerprint sends one out-of-turn message for it, then the digest. A second run that still sees the same fingerprint sends no second out-of-turn message.

**Acceptance Scenarios**:

1. **Given** a new error group, **When** the run finishes, **Then** one out-of-turn message names the service, the environment, the fingerprint, the count and the log link, and it is sent before the digest.
2. **Given** a group that was already announced in an earlier run, **When** it is seen again, **Then** no out-of-turn message is sent for it.
3. **Given** a new `warning` group and `selection.min_level: error`, **When** the run finishes, **Then** no out-of-turn message is sent, and the group is not in the digest either (it is below min_level, as today).

## Requirements *(mandatory)*

- **FR-001**: The digest MUST list every group flagged at `selection.min_level` or higher in the window. It MUST NOT replace a flagged group with a count.
- **FR-002**: When the digest does not fit one message, the package MUST send it as several messages, each within `DigestRenderer::MAX_TEXT_CHARS` and `DigestRenderer::MAX_SECTIONS`, each with a `part i/n` header. Splitting MUST NOT cut a group line in two.
- **FR-003**: `selection.top_m` MUST keep limiting only the analysis. Groups outside it MUST still appear in the digest, marked as not analysed.
- **FR-004**: A group whose `group_trend` is `new` at `selection.min_level` or higher MUST produce one out-of-turn message in the run that first sees it.
- **FR-005**: The out-of-turn message MUST be sent before the digest of the same run.
- **FR-006**: Each new fingerprint MUST be announced out of turn at most once per service and environment. The announcement MUST be recorded in state only after the message is sent successfully: a failed send leaves no record, so the next run retries it, and a successful one is never repeated.
- **FR-007**: The out-of-turn message and the digest parts MUST pass through `DayGate` (`notify.days`) like today's digest and immediate alerts. A day outside `notify.days` sends nothing and stores nothing.
- **FR-008**: Out-of-turn messages MUST use the same escaping and redaction as the digest, and MUST NOT contain a log line or any value the digest does not already show.
- **FR-009**: A new config key `alerts.new_groups` (bool, default `true`) MUST switch FR-004 to FR-006 off. The digest (FR-001 to FR-003) has no switch.

## Success Criteria *(mandatory)*

- **SC-001**: A digest with 60 flagged groups lists 60 group lines across its parts, with no duplicate and no missing fingerprint.
- **SC-002**: A new error group produces its out-of-turn message in the run that first sees it, not in a later run.
- **SC-003**: No fingerprint is announced out of turn twice for the same service and environment.
- **SC-004**: `composer test`, `composer stan`, `composer style` and `composer length` exit 0.

## Edge Cases

- A send fails halfway through the digest parts: the parts already sent are not re-sent; the failed part is reported as a delivery failure, as today.
- The number of parts is very large (for example 500 groups): the parts are still all sent. A later spec may add a cap; this one does not.
- A group is new in a run that is outside `notify.days`: it is not announced and not stored, so the first allowed day announces it.
- A fingerprint is new, but its service or environment name does not fit the event pattern: it is announced as today's digest (name handled by the existing sanitizer); the name rule of spec 013 does not apply to the digest.

## Assumptions

- "New" means `group_trend = new`, as already computed by the selection. No new definition of "new" is introduced.
- The out-of-turn message uses the existing `Notifier` and `NoticeRenderer`; no new channel is added.
- The digest parts share the existing `thread_id` and destination.

## Open Questions

- None blocking. The message-count cap (Edge Cases) is deliberately not set; revisit with real data.
