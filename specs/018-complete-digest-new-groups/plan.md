# Implementation Plan: Complete Digest and Immediate New-Group Alerts

**Spec**: `specs/018-complete-digest-new-groups/spec.md`

## Facts from the code (checked 2026-11-01, develop 8604d9c)

- `Selector::select` makes a candidate of every group at `selection.min_level` or higher, whatever `top_n` says. `top_n` only ranks the `TopVolume` reason. So the flagged set is already complete in `SelectionResult::candidates`.
- `ModelRouter` analyses at most `selection.top_m` candidates. The rest are counted in `RouterResult::notAnalysed` and never named. This is the gap behind US1.
- `DigestRenderer::render` returns one `Message` and caps the sections at `MAX_SECTIONS` (40). Overflow moves to a `minor` count.
- `Watcher::buildDigest` builds the digest; `Delivery::run` sends it through `$this->dayGated`. The digest is one message.
- `ImmediateAlerts::deliver` sends during the read, before the digest, through `$this->dayGated->send`. It keys its state with `Keys::immediateAlert` and reads it with `$state->get`.
- `DayGate::send` drops a message outside `notify.days` without telling the caller. FR-007 needs the caller to know, so `DayGate` gets a public `allowsNow()`.
- `GroupStatus::New` is set before selection, so new groups can be found in the same place the selection runs.

## Design

### US1: the digest lists every flagged group

1. `DigestData` gains `unanalysedGroups`: a list of `{fingerprint, level, count, links}` for each candidate that the router did not analyse. It is built in `Watcher::buildDigest` from `$selection->candidates` minus the analysed fingerprints. Nothing is cut here.
2. `DigestRenderer::renderParts(DigestData, threshold): list<Message>` splits the sections into parts. Each part has `MAX_SECTIONS` sections at most and `MAX_TEXT_CHARS` characters at most. A group line is never split: `DigestSections` already caps a finding line at `MAX_SECTION_CHARS`, so a part boundary falls between sections. Each part starts with a header `part i/n` (only when n > 1).
3. `render()` stays as the one-part case for the existing callers (fleet, incident) and calls `renderParts` internally; its existing tests keep passing.
4. `Delivery::run` sends each part in order. A failed part is reported through the existing delivery-failure path and the rest are still sent (spec Edge Cases).

### US2: a new group is sent out of turn, once

1. New `Notify/NewGroupAlerts` (or in `Alert/`): takes the candidates with `CandidateReason::New` at `selection.min_level` or higher, and for each fingerprint that has no state record it sends one message, then records the fingerprint.
2. It runs in `Watcher::run` right after `Tier::select`, before the digest. It uses `$this->dayGated` only after `allowsNow()` returns true. On a day outside `notify.days` it sends nothing and writes nothing (FR-007).
3. State key: `Keys::newGroupAlert($stateKey, $fingerprint)`, TTL long enough to outlive the group's presence in the window (a fixed `NEW_GROUP_TTL`, reused from the existing retention setting). The record is written after the send succeeds (FR-006).
4. Config: `alerts.new_groups` (bool, default `true`) read in `AlertsSection`. It is added to `CoreKeys` (catalog), and `docs/configuration.md` is updated.
5. Message text: `NoticeRenderer::newGroup(service, env, fingerprint, level, count, link)`. It uses the digest's escaping and carries no log line (FR-008).

### Gates and release

- `composer test`, `composer stan`, `composer style`, `composer length` must exit 0. The file-length gate on `WatchConfig.php` and `Watcher.php` was already failing on develop, so it is not a gate for this feature. New code goes into new files where it can.
- `VERSION` and `CHANGELOG.md` are not changed until a release is asked for.

## Risks

- `Watcher.php` is 1596 lines, already over the length limit. New logic goes into a new class, and `Watcher` only calls it (2 to 3 lines each).
- The number of digest parts is unbounded (approved). A very large window means many messages. Measure with a fixture of 500 groups before release.
- Out-of-turn messages depend on the schedule: they arrive in the run that reads the logs (documented, not changed here).
