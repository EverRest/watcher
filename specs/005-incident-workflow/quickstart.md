# Quickstart: Incident Workflow (v1)

Hand scenarios and the tests they map to. All tests use fakes and fixtures; none touches the network.

## S1. Known issue (US1, SC-001)

1. Run once, note a recurring group's 12-character fingerprint in the digest.
2. Add `known_issues => [['fingerprint' => '3fa9c1d2e4b7', 'ticket' => 'ABC-123']]` (or `WATCHER_KNOWN_ISSUES_ENABLED=1`).
3. Next run: the group is one line `known: ABC-123`; `watcher run --json` shows `known: 1` and no model call for it.
4. Fake a 3x larger count: the finding reads `known, grown · ABC-123`. Set `'status' => 'resolved'`: `regression of ABC-123`.

Tests: `tests/Unit/Incident/KnownIssuesTest`, `tests/EndToEnd/KnownIssuesTest::{testKnownGroupMakesNoModelCall,testGrownKnownIsReported,testResolvedIsRegression,testExpiredEntryNote,testMuteWins}`,
token fixture `tests/EndToEnd/CostTest::testKnownIssuesUseFewerTokens`.

## S2. Ticket drafts (US2, SC-002)

1. `drafts => ['enabled' => true, 'destination' => <driver block>]`.
2. Run with `--dry-run`: after `== DRY RUN drafts ==` one block per error-or-higher finding.
3. Real run: the destination gets one message per finding; re-run the same window: none.

Tests: `tests/Unit/Incident/TicketDraftTest`, `DraftPlanTest`, `tests/Unit/Notify/DraftSectionTest` (three drivers),
`tests/EndToEnd/DraftsTest::{testDraftsAfterDigest,testNoDuplicateOnRetry,testMaxPerRun,testFailureNoticeKeepsOutcome,testDryRunPrintsOnly}`.

## S3. Routing (US3, SC-003)

1. `notify.routes => [['name' => 'oncall', 'severity' => ['critical','error'], 'to' => <driver block>]]`.
2. Run with one critical and two warn findings: `oncall` gets one message, default digest shows the warns and
   `1 finding routed to oncall`.

Tests: `tests/Unit/Incident/RouteTableTest`, `tests/Unit/Config/RoutesConfigTest`,
`tests/EndToEnd/RoutingTest::{testEachFindingOnce,testEmptyRouteSendsNothing,testRetrySkipsDelivered,testNoRoutesByteIdentical,testRouteFailureOthersContinue}`.

## S4. Weekly report (US4, SC-004)

1. 013 file sink on, `retention_days >= 8`, `report => ['weekly' => ['enabled' => true]]`.
2. Cron: `0 7 * * 1 php vendor/bin/watcher report`. Try `--week=2026-W43 --dry-run` first.
3. Run twice: second prints `report 2026-W43 already sent` and posts nothing.

Tests: `tests/Unit/Report/EventLogTest`, `WeeklyReportTest` (fixture `tests/Fixtures/report/week-2026-W43.jsonl`),
`tests/EndToEnd/ReportTest::{testSentOncePerWeek,testNoRunsSendsNothing,testMissingFileFailsLoud,testTextFieldsIgnored}`.

## S5. Privacy and boundaries (SC-005, SC-006)

Tests: `tests/Unit/PrivacyCorpusTest` extended (drafts, route messages, known line, report), `tests/Unit/ArchitectureTest`
(no listening socket, no HTTP client outside `Notify` and sources, report reads only the configured file path).
