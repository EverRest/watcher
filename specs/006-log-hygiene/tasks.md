# Tasks: Log Hygiene Reports

Test-first: each task names its test, which must fail before the code. Tick `[x]` in the same commit as the code. One commit per user story phase, after the three gates exit 0 (`composer test; echo $?`, `composer stan`, `composer style`); `make ci` once at the end. No push, no `--no-verify`, no co-author trailer.

Spec: [spec.md](spec.md) · Plan: [plan.md](plan.md) · Decisions: [research.md](research.md)

## Phase 1: Foundation (corpus first, config, state)

- [x] T001 [P1] Privacy corpus extended first (Principle I; fixture and seeded values committed with phase 1, the test went green with T018/T021): `tests/Fixtures/privacy/hygiene.log` (three known call sites writing e-mails, tokens, names, IPs, a card-like and a phone-like number, in message and in context; one clean site) and their values in `seeded-values.php`; `tests/EndToEnd/HygienePrivacyTest` (red: needs `Hygiene`), later asserting no seeded value in any report text, JSON, notice or stored record (SC-001)
- [x] T002 `hygiene` config section and flag (default false) — `tests/Unit/Config/HygieneConfigTest` (defaults, strict types, unknown key, ranges, env), then `WatchConfig` hunk, `FeatureFlagsConfigTest` case, `config/watcher.example.php`
- [x] T003 State: `Keys` additions and `HygieneHistory` (day records, site registry, declines, TTL = retention, bad record ignored) — `tests/Unit/Hygiene/HygieneHistoryTest` against `InMemoryStateStore` and `FailingStateStore`

## Phase 2: US1 — privacy-hygiene report (P1)

- [x] T004 `Category` + `PiiScan`: normalised text to category counts, table test over every placeholder family, phone-like shape, `<ID>`/`<N>` not counted — `PiiScanTest`
- [x] T005 `SiteId`: file+line keys, first frame, template fallback, Redactor masking, dropped frame falls through, line move keeps `siteKey` — `SiteIdTest`
- [x] T006 `HygieneScanner` privacy measurement: per site, level, category, share of lines affected; field values scanned, keys ignored; clean site absent; bounded sites; sampling flag; no value reachable (reflection test on stored shape) — `HygieneScannerPrivacyTest`
- [x] T007 `VariablePositions` from reduced groups (distinct messages under one template, threshold, withheld templates skipped, no values) — `VariablePositionsTest`
- [x] T008 `Trend` + `SiteRegistry` (new / growing / stable / gone, weekly suppression, moved line not new) and `PrivacyReport` (text + schema 1 JSON, key set allowlisted, empty report) — `TrendTest`, `PrivacyReportTest`

## Phase 3: US2 — log-shape report (P2)

- [x] T009 Shape measures in `HygieneScanner`: volume share, long lines, trace in message, format mix, level/content mismatch — `HygieneScannerShapeTest` (one of each habit counted once; clean stream empty)
- [x] T010 `ShapeReport`: one entry per habit with measure and fixed-text proposal, nothing sent when empty, no message text beyond masked templates — `ShapeReportTest`

## Phase 4: US4 — cost report (P2)

- [x] T011 `CostLedger` + `LedgerAnalyzer` decorator (calls per step, tokens, bounded family table with even attribution), wired in `Factory::analyzer` only when `hygiene.enabled` — `CostLedgerTest`, `FactoryTest` case
- [x] T012 `CostReader`: per service and day from run records (sum equals recorded runs, SC-006), cap shares, spike flag at k× median of previous days, causes from snapshots and ledger — `CostReaderTest`
- [x] T013 `CostReport`: text + schema 1 JSON, top spenders by fingerprint and masked template, `calls` null without ledger — `CostReportTest`

## Phase 5: US3 — rule proposals (P2)

- [x] T014 `Proposals` mute: 14-day gate with `days_needed`, stable/high-volume/never-severe, severity matrix incl. same template at another fingerprint (SC-004), applied detection — `ProposalsMuteTest`
- [x] T015 `Proposals` watch: precursor detection, rule regex from masked template matches through `WatchRules` — `ProposalsWatchTest`
- [x] T016 Replay through a real `MuteList`, expected saving within 5 % of replay (SC-003), declines for 90 days, config never modified — `ReplayTest`, `DeclineTest`
- [x] T017 `ProposalReport`: fragments as ready-to-paste text, evidence, replay, JSON — `ProposalReportTest`

## Phase 6: Command, delivery, adapters

- [x] T018 `Hygiene` orchestration: read source → reduce → scan → store day record → reports; `--notify` to the notice destination, empty not sent, suppression and `sentAt`; failures loud (fixed reasons), sampling stated, disabled behaviour — `tests/EndToEnd/HygieneTest` with fakes
- [x] T019 CLI `watcher hygiene` in `Application` (hunks) and `bin/watcher`: options, exit codes 0/1/2, `--json` on stdout, `--decline`, disabled hints — `CliTest` cases
- [x] T020 Laravel `HygieneCommand` + provider binding (hunks) — `tests/Unit/Laravel` case
- [x] T021 Guards: `HygienePrivacyTest` completed (every output and stored record, with a control run proving the test sees leaks), architecture test (no model client, no `Watcher` dependency in `src/Hygiene` except the ledger decorator), SC-004 matrix, SC-005 (no analyzer built by the command) — `ArchitectureTest` additions

## Phase 7: Docs and hand-off

- [x] T022 Docs: `docs/privacy.md` (what hygiene reads, stores, emits), `configuration.md`, `operations.md`, README section, `CHANGELOG.md` Unreleased, `docs/roadmap.md` status, example config
- [x] T023 Final gates: `make ci > /tmp/ci.log 2>&1; echo $?`, review of the diff against the constitution, hand-off report (shared-file hunks listed for the integrator)

## Phase 8: review fixes (independent review of lane B)

- [x] T024 Notices: escaping of log-derived text in all three channels, caps, mark-only-what-was-shown, resend interval, disabled destination (`HygieneNoticeTest`); site ids limited to source files, integer values, `unscannable`, separate clean-site table; proposals on complete days only, severe families kept, "gone" after two complete passes; ledger shadow in dry run and per fleet service; lock, time budget, memoisation; `state unavailable` and the JSON `failure`

## Dependencies

T001 → everything that emits. T002, T003 → T006, T008. T004, T005 → T006. T011 → T012 → T013. T006, T008, T009 → T018. T013, T017 → T018. T018 → T019, T020, T021.

## Independent test per story

US1: T001 + T004–T008. US2: T009–T010. US4: T011–T013. US3: T014–T017 (needs T003 history and T011 tokens).
