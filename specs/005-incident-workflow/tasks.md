---

description: "Task list for Incident Workflow v1 (everrest/watcher)"
---

# Tasks: Incident Workflow (v1)

**Input**: `specs/005-incident-workflow/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Tests**: REQUIRED (Constitution III). In each task the test is written first and must fail first; the implementation
turns it green. No test touches the network (faked notifiers, fake analyzer, temp files, fixture event file).

**Format**: `[ID] [P?] [Story] Description`. Paths relative to the repository root. `[P]` = different files, no unfinished
dependency, safe in parallel with other `[P]` tasks of the same phase. **HOT** = shared file that other specs and agents
also edit: run these tasks one at a time, in the order below, re-reading the file just before editing.

**HOT FILES, SEQUENTIAL** (in landing order; 013 and 014 may land first, rebase on them):

| Order | Task | Hot file(s) touched |
|---|---|---|
| 1 | T004 | **`src/Config/WatchConfig.php`** (known_issues) |
| 2 | T008 | **`src/Watcher.php`**, `src/Select/{Selector,CandidateReason,Candidate}.php`, **`src/Notify/{DigestData,DigestRenderer}.php`**, **`src/State/Keys.php`** (via T006) |
| 3 | T010 | **`src/Operate/{JsonSummary,RunSummaryData,Doctor}.php`** |
| 4 | T012 | **`src/Config/WatchConfig.php`**, **`src/Config/Factory.php`** (routes) |
| 5 | T014 | **`src/Watcher.php`** (delivery block), **`src/Notify/{DigestData,DigestRenderer}.php`**, **`src/State/Keys.php`** |
| 6 | T016 | **`src/Notify/{SlackText,TelegramText,DiscordText,MessageSplitter}.php`**, `src/Notify/Message.php` docs |
| 7 | T018 | **`src/Config/WatchConfig.php`**, **`src/Config/Factory.php`**, **`src/Watcher.php`**, `src/State/Keys.php` (drafts) |
| 8 | T023 | **`src/Config/WatchConfig.php`**, **`src/Cli/Application.php`**, `src/State/Keys.php` (report) |
| 9 | T025 | **`src/Fleet/{FleetConfig,FleetRunner}.php`**, **`src/Cli/Application.php`** |

Nothing else may edit `src/Watcher.php`, `src/Config/*`, `src/Notify/*` or `src/State/Keys.php` in this spec. `src/Incident/*`
and `src/Report/*` are new and owned by this spec.

**Gate command**: `composer test; echo $?` then `composer stan; echo $?` then `composer style; echo $?`. Read the exit codes,
not the last line of output.

**No commits, pushes or tags** unless the owner asks. Each phase may ship as its own MINOR release.

---

## Phase 0: Gate

- [ ] T001 Run the three gates on the current tree and record exit codes. Re-read the hot files above (13/14 may have
  changed them). Create empty `tests/Unit/Incident/`, `tests/Unit/Report/`, `tests/Fixtures/report/`. No code. (FR-040)

## Phase 1: Known issues (US1, P1) — MVP

- [ ] T002 [P] [US1] `tests/Unit/Incident/KnownIssuesTest.php` (red) table tests: below threshold -> known line, not kept;
  at `growth_factor` -> kept with `known_grown`; first sight stores `ref = count`; resolved -> kept with regression label;
  `until` past -> kept with expired label; absent `ref` after expiry restarts; two keys for one fingerprint; cap of 10 lines
  plus `N more`; ordering count desc then fingerprint; disabled/empty -> input returned unchanged and zero state calls;
  state value of the wrong shape is "no reference", never trusted or thrown on. Then `src/Incident/{KnownIssue,KnownIssues,KnownResult,KnownReferences}.php`.
  Needs a `StateStore` fake only. (FR-001..FR-006)
- [ ] T003 [P] [US1] `tests/Unit/Select/KnownGrownReasonTest.php` (red): a group with reason `known_grown` is selected even
  when its snapshot trend is `unchanged`; ordering relative to `new`/`grown` pinned by a table. Then `CandidateReason::KnownGrown`
  and the selector branch. (FR-003, Principle IV)
- [ ] T004 [US1] **HOT `src/Config/WatchConfig.php`.** `tests/Unit/Config/IncidentConfigTest.php` (red, known part): every key
  and message of contracts/config.md for `known_issues` (pattern, status, until, duplicates, max 200, strict booleans,
  `WATCHER_KNOWN_ISSUES_ENABLED`), key path in every message, value never; fingerprint `^[0-9a-f]{12,64}$` with starts-with matching. Register `known_issues.enabled` in `tests/Unit/FeatureFlagsArchitectureTest.php` and the env table of `docs/configuration.md`. Then `WatchConfig` builds `KnownIssue[]`. (FR-001, FR-007, FR-041)
- [ ] T005 [P] [US1] `tests/Unit/Notify/KnownLineTest.php` (red): contracts/known-issues.md line format, cap, escaping of the
  ticket key by driver, nothing but key/level/count. Then the renderer helper (file edited in T008). Test file only here.
- [ ] T006 [US1] `tests/Unit/State/KnownKeysTest.php` (red; runs in the shared state-store contract suite): `Keys::KNOWN`
  with `state.retention` expiry renewed per sighting, URL-encoded components. Then `src/State/Keys.php` (**HOT**, re-read first). (FR-002, Principle I expiry)
- [ ] T007 [US1] `tests/EndToEnd/KnownIssuesTest.php` (red, written before touching `Watcher`): `testKnownGroupMakesNoModelCall`
  (fake analyzer records 0 calls for the group), `testGrownKnownIsReported` (label; `ref` never changes afterwards, still reported on the next run: `testRefNeverRecomputed`),
  `testResolvedIsRegression`, `testExpiredEntryNote`, `testMuteWins`, `testSnapshotKeepsKnownGroups`, `testDisabledIsByteIdentical`
  (golden vs previous output), `testFirstSightRefNotWrittenWhenDeliveryFails`, `testTicketKeyNeverInModelInputStateOrEvents` (scan `CompactPayload`, system prompt, every `RecordingStateStore` write, JSON summary), `testPrefixMatchOf12Chars`. Plus `tests/EndToEnd/CostTest::testKnownIssuesUseFewerTokens`
  (token-budget fixture, Principle V). (SC-001)
- [ ] T008 [US1] **HOT `src/Watcher.php`, `src/Notify/{DigestData,DigestRenderer}.php`, `src/Select/*`.** Wire after
  `$mute->filter(...)`: partition, pass `known_grown` reasons to the selector, add known lines and labels to `DigestData`,
  write references through `$buffer`. One hunk in `attempt()`; rebase on the current file first. Turns T002, T003, T005,
  T007 green. (FR-002..FR-005)
- [ ] T009 [US1] Privacy corpus: extend `tests/Unit/PrivacyCorpusTest.php` so a known entry with seeded text around its
  fingerprint produces a line with the key only; no seeded value in line, label, JSON summary, state values. Fix in T008 code. (FR-042, SC-005)
- [ ] T010 [US1] **HOT `src/Operate/{JsonSummary,RunSummaryData,Doctor}.php`.** `tests/Unit/Operate/KnownSummaryTest.php`
  (red): `known`, `known_grown` keys additive and absent when the feature has no entries; `check` line and stale-entry info
  line (contracts/known-issues.md). Then the three classes.
- [ ] T011 [US1] Docs: `docs/configuration.md` (keys, env vars, example), `docs/operations.md` (how to take a fingerprint from
  the digest, what resolved means), `docs/privacy.md` (what the line contains). Docs drift test if one exists for config keys.

**Checkpoint**: US1 independently shippable (MINOR).

## Phase 2: Routing by severity (US3, P2)

- [ ] T012 [US3] **HOT `WatchConfig.php`, `Factory.php`.** `tests/Unit/Config/IncidentConfigTest.php` routes part (red):
  `routes` and `routes_enabled` accepted by `notify()` (currently `only(['digest','notice'])`), `digest: combined` + routes rejected, inert when `notify.digest.enabled` is false, flag registered in `FeatureFlagsArchitectureTest`, shape, name pattern/uniqueness, overlap of severities, `to` validated by the digest destination code, `must differ from
  notify.digest`, max 5, secrets never in messages; `tests/Unit/WatcherFromConfigTest.php` additions: routes build
  `Destination`s through the existing `RoutingNotifier`. Then both files. (FR-020, FR-023, FR-041)
- [ ] T013 [P] [US3] `tests/Unit/Incident/RouteTableTest.php` (red): first match wins in configuration order, catch-all last (`to` omitted = default), config without catch-all rejected, defensive unmatched -> default digest with a notice, each finding
  once, stable order, empty route absent from the result. Then `src/Incident/{Route,RouteTable}.php`. (FR-020)
- [ ] T014 [US3] **HOT `Watcher.php` delivery block, `DigestData`, `DigestRenderer`, `Keys.php`.**
  `tests/EndToEnd/RoutingTest.php` (red first): `testEachFindingOnce`, `testEmptyRouteSendsNothing`,
  `testDefaultShowsRoutedLine`, `testRouteMessageHasNoStatsOrFooter`, `testRetrySkipsDeliveredDestination`,
  `testRouteFailureOthersContinueAndNotice`, `testNoRoutesByteIdentical` (golden), `testRoutesFirstThenDefault` (order
  recorded by the fake), `testNoDestinationValueInAnyOutput` (spec 011 corpus). Then: route marker key
  (`sent:...:r<i>`), `DigestData` copy for a route, `routed` notice line, delivery loop with per-destination catch. (FR-021..FR-024, SC-003)
- [ ] T015 [US3] Docs and `check`: `Doctor` lists routes by name (never destination values), `--post-test` posts one labelled
  message per distinct destination including routes (spec 011 FR-012 rule). Tests in `tests/Unit/Operate/DoctorRoutesTest.php` first.

**Checkpoint**: US3 shippable.

## Phase 3: Ticket drafts (US2, P1; after routing because both touch the delivery block)

- [ ] T016 [US2] **HOT `src/Notify/{SlackText,TelegramText,DiscordText,MessageSplitter}.php`.**
  `tests/Unit/Notify/DraftSectionTest.php` (red, all three drivers): section kind `draft` renders a code block; model text
  containing the closing fence, `<b>`, `&`, `**`, `@everyone`, `<@1>`, `http://a.example` stays inert and cannot close the
  block; Telegram `<pre>` is valid HTML after a cut; length limits and split rules of spec 011 contracts/message-limits.md;
  Discord `allowed_mentions.parse` stays `[]`. Then the formatters. (FR-014, SC-005)
- [ ] T017 [P] [US2] `tests/Unit/Incident/TicketDraftTest.php` and `DraftPlanTest.php` (red): every element of
  contracts/ticket-draft.md present when the finding has it; absent value -> line omitted; `Update on KEY` for
  `known_grown`; hypothesis always labelled unconfirmed; total cap with visible marker; ordering (severity, count,
  fingerprint) and `max_per_run`; skipped-marker input respected; no data outside the digest allowlist (seeded values absent).
  Then `src/Incident/{TicketDraft,DraftPlan}.php`. (FR-010..FR-012)
- [ ] T018 [US2] **HOT `WatchConfig.php`, `Factory.php`, `Watcher.php`, `Keys.php`.** `IncidentConfigTest` drafts part
  (red: keys, severity set, range 1..20, destination rule `drafts.destination required`, env vars) and
  `tests/EndToEnd/DraftsTest.php` (red): `testDraftsAfterDigest`, `testOnlyAtOrAboveMinSeverity`, `testNoDuplicateOnRetry`
  (marker after delivery), `testMaxPerRunAndDigestLine`, `testFailureNoticeKeepsOutcome`, `testDryRunPrintsOnly`
  (`== DRY RUN drafts ==`, nothing sent or stored), `testDisabledIsByteIdentical`, `testNoNetworkOtherThanNotifier`. Then
  config, `Keys::DRAFT`, and the post-digest block in `Watcher`. (FR-010..FR-013, FR-041, SC-002)
- [ ] T019 [US2] Privacy corpus: drafts across the seeded corpus on all three drivers; zero seeded personal values,
  credentials or live markup/mentions in any message, notice or output. (FR-042, SC-002, SC-005)
- [ ] T020 [US2] Docs: `docs/operations.md` (paste workflow), `docs/privacy.md` (draft contents), `docs/configuration.md`.

**Checkpoint**: US2 shippable.

## Phase 4: Weekly report (US4, P3; gated on spec 013 `FileSink` and schema)

- [ ] T020a [US4] **Gate**: confirm spec 013 has landed (`src/Observe/FileSink.php`, `docs/schema/watcher.event.v1.schema.json`); if not, stop this phase. Add `tests/Unit/Report/EventFieldsDriftTest.php` (red): the fields `EventLog` reads exist in 013's `EventSchema` for `run`, `group`, `finding`. (FR-029)
- [ ] T021 [P] [US4] `tests/Unit/Report/EventLogTest.php` (red) with `tests/Fixtures/report/week-2026-W43.jsonl` and bad-line
  fixtures: allowlist parse; `dry_run: true` and skipped outcomes ignored; retried window: spend summed, groups taken from the latest `ts` only; text fields ignored even when present; wrong schema/type/pattern/other service skipped and
  counted; rotated files read in order; line length cap; missing/unreadable file -> exception naming the setting; read-only
  (file untouched). Then `src/Report/EventLog.php`. (FR-030, FR-031)
- [ ] T022 [P] [US4] `tests/Unit/Report/WeeklyReportTest.php` (red): recurring (distinct days, tie-break), stopped after a
  release (needs 2 later runs), noisiest (summed count), spend sums, muted sum, known join from configuration, ISO week
  boundaries (year change, DST week), `top` length, no-runs week returns null, figures equal the hand-computed fixture
  (SC-004); renderer output equals contracts/weekly-report.md, counts and identifiers only. Then
  `src/Report/{WeeklyReport,ReportData,ReportRenderer}.php`. (FR-030..FR-034)
- [ ] T023 [US4] **HOT `WatchConfig.php`, `Cli/Application.php`, `Keys.php`.** `IncidentConfigTest` report part (red:
  `report.weekly needs observe.file enabled with retention_days >= 8`, ranges, env) and `tests/EndToEnd/ReportTest.php`
  (red): `testSentOncePerWeek`, `testNoRunsSendsNothing`, `testMissingFileFailsLoud` (non-zero, nothing sent),
  `testFailedDeliveryDoesNotMarkSent`, `testDryRunPrintsOnly`, `testJsonHasNoText`, `testTextFieldsIgnored`,
  `testWeekOption`, `testBareReportFails`, `testMasterSwitchOffReturnsDisabled` (extend `tests/Unit/DisabledCommandsTest.php`), `testRejectedWithoutSpec013`. Then config, the command table in `Cli/Application.php` (options `--week=`, `--dry-run`, `--json`), `Keys::REPORTSENT`, command `report --weekly` (`--week`, `--dry-run`, `--json`; bare `report` fails) and `src/Laravel/ReportCommand.php` (`watcher:report`) registered in `WatcherServiceProvider` with a test beside the existing Laravel command tests. (FR-030..FR-034, SC-004)
- [ ] T024 [US4] Docs and dashboards link: `docs/operations.md` (cron line, why 8 days retention, coverage caveat),
  `docs/privacy.md`, `docs/configuration.md`.
- [ ] T025 [US4] **HOT `src/Fleet/{FleetConfig,FleetRunner}.php`, `Cli/Application.php`.** `tests/Unit/Fleet/FleetReportTest.php`
  (red): per-service blocks replace shared ones as a whole (a shared route list and a service route list are NOT merged
  index by index, the default `array_replace_recursive` would); `digest: combined` with `notify.routes` rejected
  (`notify.routes is not supported with digest combined`); `fleet:report` loops entries; one message per service;
  error prefixes (`services[1] (bff-a): ...`); a failing service does not stop the rest and the exit code is non-zero. Then the
  two classes plus `fleet:report`. Also covers fleet use of `known_issues`, `drafts`, `notify.routes` (per-service blocks).

## Phase 5: Boundaries and release

- [ ] T026 [US1..US4] `tests/Unit/ArchitectureTest.php` (red then green): no listening socket or server class in `src/`;
  `src/Incident` and `src/Report` perform no HTTP; `EventLog` reads only the configured path; the only file write in
  `src/Report` is none (marker goes through `StateStore`); allowed outbound IO unchanged. (FR-040, SC-006)
- [ ] T027 Update `config/watcher.php`, `config/watcher.example.php`, `docs/examples/*.php` with commented, default-off blocks
  and `WATCHER_*` variables; `docs/roadmap.md` row 005 (status "v1 implemented; buttons deferred (D1)", D6 row suggestion).
- [ ] T028 Release: `CHANGELOG.md` entry (MINOR, "upgrade: none required"), version per repository practice. Full gate command,
  exit codes read. Live check (T114-style, by the owner): one real digest with a known entry and a route.

---

## Dependencies and order

- Phase 1 -> Phase 2 -> Phase 3 (delivery block) -> Phase 4 (independent in code, gated on 013). Phases 1 and 2 can swap if
  routing is wanted first; T003/T013/T017/T021/T022 and the other `[P]` test-and-class pairs have no hot file.
- T016 must land before T018 (draft messages need the section kind).
- Anything that edits a HOT file waits for the previous HOT task in the table and is rebased on the then-current file.
- Deferred (not tasks): buttons/threads/endpoint (D1), tracker search (D2), ticket creation (D3), mentions (D4),
  templates (D5), post-release/on-demand runs (D6).
