# Tasks: Complete Digest and Immediate New-Group Alerts

Order is TDD: each red test is written and seen to fail before its code. Gates run at the end of each phase.

## Phase 1: the digest lists every flagged group (spec US1)

- [ ] T001 Red: `DigestRendererTest` — 60 unanalysed candidates become 60 group lines across the parts, no duplicate, no missing fingerprint (SC-001).
- [ ] T002 Red: a part that would exceed `MAX_SECTIONS` or `MAX_TEXT_CHARS` is split at a section boundary; every part is within both limits; a single group line is never cut (FR-002).
- [ ] T003 Red: a single part gets no `part i/n` header; n > 1 gets `part 1/2`, `part 2/2`, … (FR-002).
- [ ] T004 Red: a group below the confidence threshold is still listed as a minor line with its counts (User Story 1, scenario 3).
- [ ] T005 Green: add `DigestData::unanalysedGroups`; `DigestSections` renders one line per entry, with count and log link, marked as not analysed (FR-001, FR-003).
- [ ] T006 Green: `DigestRenderer::renderParts()`; `render()` delegates for the one-part case.
- [ ] T007 Red: `Watcher` builds `unanalysedGroups` from `Selection::candidates` minus analysed fingerprints (test in the end-to-end suite with a 60-group window).
- [ ] T008 Green: `Watcher::buildDigest` fills `unanalysedGroups`.
- [ ] T009 Red: `Delivery` sends each part in order; a failed part is reported and the next parts still go out (Edge Cases).
- [ ] T010 Green: `Delivery::run` loops over parts.
- [ ] T011 Gates: test, stan, style, length exit 0; commit.

## Phase 2: a new group is sent out of turn, once (spec US2)

- [ ] T012 Red: `DayGate::allowsNow()` is true on an allowed day and false outside `notify.days`; `send` still drops as before.
- [ ] T013 Green: add `DayGate::allowsNow()`; `send` uses it.
- [ ] T014 Red: `NewGroupAlerts` sends one message for a new `error` group, and none for a new `warning` group when `min_level` is `error` (US2 scenario 3).
- [ ] T015 Red: the same fingerprint seen in a second run sends nothing (SC-003).
- [ ] T016 Red: a failed send writes no record, so the next run retries it (FR-006).
- [ ] T017 Red: outside `notify.days` nothing is sent and nothing is written (FR-007).
- [ ] T018 Red: with `alerts.new_groups: false` nothing is sent (FR-009).
- [ ] T019 Green: `Keys::newGroupAlert()`, `NewGroupAlerts` class, `NoticeRenderer::newGroup()` (escaping as the digest, no log line; FR-008).
- [ ] T020 Red: config key `alerts.new_groups` loads, defaults to `true`, rejects non-bool (catalog test).
- [ ] T021 Green: `AlertsSection` and `CoreKeys` entry.
- [ ] T022 Green: `Watcher::run` calls `NewGroupAlerts` after `Tier::select` and before the digest.
- [ ] T023 Red: end-to-end — one run sends the out-of-turn message before the digest (order check via the recording notifier) (FR-004, FR-005, SC-002).
- [ ] T024 Gates: test, stan, style, length exit 0; commit.

## Phase 3: docs

- [ ] T025 `docs/configuration.md`: `alerts.new_groups`, and the digest parts behaviour.
- [ ] T026 `docs/operations.md` or the digest docs: out-of-turn messages arrive in the run that reads the logs; faster needs `schedule.every`.
- [ ] T027 Gates; commit.

## Not in this feature

- No version bump, no CHANGELOG release entry, no tag. Done when a release is asked for.
- No cap on the number of digest parts (approved).
- No change to schedule cadence; no separate run for new groups.
