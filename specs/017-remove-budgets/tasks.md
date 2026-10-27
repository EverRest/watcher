# Tasks: Remove Budgets and Token and Cost Accounting

**Input**: `specs/017-remove-budgets/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Target**: release 0.5.0 (breaking configuration; constitution 2.0.0)

**TDD order**: Phase 2 writes the failing tests that define the change. Each user-story phase then removes or replaces code until its tests are green. A subagent that owns a group of files follows red, then green, then refactor.

**Gates** (every commit stage, read exit codes): `composer test`, `composer stan`, `composer style` all exit 0 before the stage is committed. Commits carry no `Co-Authored-By`. No push without a separate request.

**Live smoke**: not an executable task here. It needs the owner's explicit permission.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallel-safe (no shared file with an open task)
- **[Story]**: US1 to US4 for story phases only

---

## Phase 1: Setup

- [X] T001 Confirm branch `feat/017-remove-budgets-and-reports` is current (`git branch --show-current`) and the working tree is clean (`git status --short` prints nothing).
- [X] T002 Record the gate baseline: `composer test; echo "test=$?"`, `composer stan; echo "stan=$?"`, `composer style; echo "style=$?"`. All must be 0; otherwise stop and report.
- [ ] T003 Record the baseline file list: `grep -rlIE "budget|usd|Usd|USD|[Cc]ost|price|[Pp]rices|[Ss]pend|token_limit|tokenLimit|max_runs_per_day|WATCHER_PRICE" src docs docs/examples > specs/017-remove-budgets/baseline-files.txt`. Deleted in T070.

**Checkpoint**: baseline green; the baseline list exists.

---

## Phase 2: Foundational (red tests that define 0.5.0)

**Purpose**: write every test that must fail before the code changes. Each test file is committed together with the stage that turns it green (no red-only commit).

- [X] T004 [P] Write `tests/Unit/Config/RemovedKeysTest.php` (red): each key in `specs/017-remove-budgets/contracts/config-migration.md` (`analysis.daily_budget_usd`, `analysis.run_budget_usd`, `analysis.prices`, `analysis.daily_token_limit`, `analysis.budget_alert_percents`, `analysis.max_runs_per_day`, and the whole `budget` section) fails `WatchConfig::fromArray` with the exact message from that contract. Include: a removed key present together with other valid keys still fails.
- [X] T005 [P] Write `tests/Unit/NoBudgetNamesTest.php` (red): the guard from `quickstart.md` section 3. It scans `src/`, `docs/`, `docs/examples/` for `usd`, `Usd`, `USD`, `CostCalculator`, `UnknownModelPrice`, `cost_usd`, `budget`, `daily_token_limit`, `tokens_today`, excluding `CHANGELOG.md`, `specs/`, `docs/audit-*`, `docs/review-*`, `docs/brief.md`, `docs/roadmap.md`. Fails until T070.
- [X] T006 [P] Write `tests/Unit/Observe/EventV2Test.php` (red): an emitted run record validates against `docs/schema/watcher.event.v2.schema.json` (file created in T050) and has none of: `cost_usd`, `tokens_in`, `tokens_out`, `tokens_cached`; an emitted record has no budget record at all.
- [X] T007 [P] Write `tests/EndToEnd/SameFindingsTest.php` (red): for each of the three providers (Anthropic SDK, OpenAI, Gemini), a recorded window run produces the same findings list (titles, severities, fingerprints) as the baseline recorded in `tests/Fixtures/findings-baseline-017.json` (record it from the current code before any change, in T008).
- [X] T008 Record `tests/Fixtures/findings-baseline-017.json` from the current code: run the recorded-window scenario through `tests/EndToEnd/ProviderRunTest.php` setup and dump the digest findings for each provider. Commit the fixture with the Phase 2 stage.
- [X] T009 [P] Write `tests/Unit/State/NoSpendCountersTest.php` (red): the `StateStore` implementations (`FileStateStore`, `PdoStateStore`, `RedisStateStore`) expose no reservation method and the `Keys` class exposes no spend key; a stored `spend:usd:*` or `spend:tokens:*` value is never read (assert `get` is not called with those keys by a run).
- [X] T010 [P] Write `tests/Unit/Analyze/ReplyShapeTest.php` (red): a provider reply whose usage object is missing or has a negative count is an invalid reply (`AnalyzerFailed` with `invalid model output` or `provider unavailable (invalid reply)`, as in spec 016); a valid reply is accepted and its counts are not returned in the `ModelReply` (assert the reply has no count property).

**Checkpoint**: `vendor/bin/phpunit --filter 'RemovedKeys|NoBudgetNames|EventV2|SameFindings|NoSpendCounters|ReplyShape'` fails for the expected reasons only (keys still accepted, guard finds names, v1 fields present, counters present).

---

## Phase 3: User Story 1 - Run without any budget (Priority: P1)

**Goal**: analysis runs to completion with no budget, reservation or spend check; the digest lists findings; the state store keeps no counters.

**Independent test**: `SameFindingsTest` green for all three providers; a configuration with no budget key runs the recorded window to completion.

### Analyze: remove budgets, reservations, spend and prices

- [X] T011 [US1] Delete `src/Analyze/BudgetReservation.php`, `src/Analyze/BudgetAlerts.php`, `src/Analyze/Spend.php`, `src/Analyze/CostCalculator.php`, `src/Analyze/UnknownModelPrice.php` with `git rm`; delete their tests `tests/Unit/Analyze/BudgetReservationTest.php`, `BudgetReservationRunCapTest.php`, `BudgetFitDependenciesTest.php`, `BudgetAlertsTest.php`, `SpendTest.php`, `SpendRetrySafetyTest.php`, `SpendDayBoundaryTest.php`, `CostCalculatorTest.php`, `tests/Unit/Analyze/ProviderCostTest.php`, `tests/Unit/Analyze/BudgetTest.php`.
- [X] T012 [US1] Edit `src/Analyze/Usage.php`: remove `costUsd` and the `plus` cost sum; keep the class only if the analysis still needs a per-call holder, otherwise delete it (decide by grep of `new Usage(`; expected: delete).
- [X] T013 [US1] Edit `src/Analyze/ModelReply.php`: remove the four count fields (`inputTokens`, `outputTokens`, `cacheReadTokens`, `cacheWriteTokens`) from the constructor; keep `text`, `stopReason`, `model`.
- [X] T014 [US1] Edit `src/Analyze/AnthropicSdkProvider.php`, `src/Analyze/OpenAiProvider.php`, `src/Analyze/GeminiProvider.php`: keep the reply-shape check (usage present, counts non-negative, per research R2) but do not carry the values into `ModelReply`. Make `tests/Unit/Analyze/ReplyShapeTest.php` and the provider tests green.
- [X] T015 [US1] Edit `src/Analyze/AnthropicAnalyzer.php` and `src/Analyze/ModelRouter.php`: remove the reservation, the spend booking, the cost argument and the `Usage` plumbing; routing (per step) and failure rules unchanged.
- [X] T016 [US1] Edit `src/Analyze/RouterSettings.php`, `src/Analyze/RouterResult.php`, `src/Analyze/AnalysisResult.php`, `src/Analyze/TriageResult.php`, `src/Analyze/AnalyzerFailed.php`, `src/Analyze/Alert.php`: remove budget and cost fields and messages.
- [X] T017 [US1] Edit `src/Analyze/Budget.php` only if it contains more than the endpoint note; if it does, remove the budget part and keep `ENDPOINT_NOTE` and the prompt builder.
- [X] T018 [US1] Edit `src/Analyze/Alert.php` (if not deleted in T016) and `src/Analyze/` remaining references: `grep -rn "budget\|Budget\|Spend\|CostCalculator\|costUsd" src/Analyze` must print nothing.

### State store: no counters, no reservations

- [X] T019 [US1] Delete `src/State/ReservableCounters.php`; remove the reservation method from `src/State/StateStore.php` interface only if it is declared there; remove the spend keys and reservation from `src/State/Keys.php`.
- [X] T020 [US1] Edit `src/State/FileStateStore.php`, `src/State/RedisStateStore.php`, `src/State/StateCodec.php`, `src/State/StateValueTooLarge.php`: remove the reservation Lua script and the spend increment paths; keep the generic get, set, increment-free store. Make `tests/Unit/State/NoSpendCountersTest.php` green.
- [X] T021 [US1] Edit `src/Operate/DryRunStateStore.php` and `src/Operate/EstimatingAnalyzer.php`: remove the spend and cost estimation (the dry-run estimate of cost is removed; it may still estimate call counts).
- [X] T022 [US1] Update the state-store contract suites `tests/Contract/StateStoreContractTest.php`, `tests/Contract/RedisStateStoreTest.php`, `tests/Contract/RedisReservationTest.php`, `tests/Contract/FileStateStoreTest.php`, `tests/Contract/PdoStateStoreTest.php` (and any `*ReservationTest*`): remove reservation and spend cases; keep the cases that test get, set, TTL and locking.

### Pipeline: remove budget and cost from the run

- [X] T023 [US1] Edit `src/Watcher.php`: remove the reservation construction, the metered wrapper that books cost, the daily and run budget checks, the `costUsd` plumbing, the budget outcome branch. Keep the analysis call path and the findings path.
- [X] T024 [US1] Edit `src/Analysis.php`: remove the `costUsd` and usage fields from the value object; keep findings and call counts.
- [X] T025 [US1] Edit `src/RunResult.php`, `src/RunLedger.php`, `src/OutcomeRegistry.php`: remove the cost line and the budget fields; the outcome `budget_exhausted` (or equivalent) is removed from the registry, and any run that would have stopped for budget reasons now runs to completion.
- [X] T026 [US1] Edit `src/Config/Factory.php`: remove the cost calculator and the reservation wiring; build the analyzer with the provider registry only.

### Tests for US1

- [X] T027 [US1] Rewrite `tests/Unit/Analyze/ModelRouterTest.php`, `ModelRouterFailClosedTest.php`, `AnthropicAnalyzerTest.php`, `AnthropicAnalyzerTriageTest.php`, `AnthropicSdkProviderTest.php`, `OpenAiProviderTest.php`, `GeminiProviderTest.php`, `ProviderOutgoingBodyPrivacyTest.php`, `ServiceContextTest.php`: remove assertions on cost and counts; keep routing, failure and privacy assertions.
- [X] T028 [US1] Rewrite `tests/EndToEnd/ReservationTest.php`, `RunCapTest.php`, `HostileVolumeBudgetTest.php`, `CostTest.php`, `ProviderRunTest.php` for the budget-free behaviour: a run that previously stopped at a budget now completes; assert findings.
- [X] T029 [US1] Run `vendor/bin/phpunit --filter 'SameFindings|ModelRouter|AnthropicAnalyzer|Provider|StateStore|Watcher'` and the gates; when green, commit `feat(017): analysis without budgets or spend counters`.

**Checkpoint US1**: `SameFindingsTest` green for all three providers; `NoSpendCountersTest` green; gates green; commit.

---

## Phase 4: User Story 2 - Removed keys fail loudly (Priority: P1)

**Goal**: every removed configuration key fails at load with the exact message.

**Independent test**: `RemovedKeysTest` green.

- [X] T030 [US2] Edit `src/Config/WatchConfig.php`: remove the parsing and validation of the removed keys; add the explicit removed-key check with the exact messages from `contracts/config-migration.md`, applied before any other validation for those keys; the `budget` section is removed as a whole.
- [X] T031 [US2] Edit `src/Config/KeyCatalog.php`: remove the entries for every removed key and for `WATCHER_PRICE_*`; keep `KeyCatalogDriftTest` green.
- [X] T032 [US2] Edit `src/Config/SecureUrl.php` only if it matches on the removed words (check with grep); otherwise leave it unchanged.
- [X] T033 [US2] Rewrite `tests/Unit/Config/WatchConfigTest.php`, `WatchConfigProvidersTest.php`, `AnalysisClientTest.php`, `SecretDumpTest.php`, `FactoryTest.php`, `FactoryApiContextTest.php`, `FactoryKubernetesTest.php`, `FactoryMetricsSentryTest.php`: remove price and budget cases; add nothing else; keep the rest.
- [X] T034 [US2] Run `vendor/bin/phpunit --filter 'RemovedKeys|WatchConfig|KeyCatalog|Factory|SecretDump'` and the gates; commit `feat(017): removed keys fail at load with a fixed message`.

**Checkpoint US2**: `RemovedKeysTest` green.

---

## Phase 5: User Story 3 - Fleet, hygiene and metrics without spend (Priority: P2)

**Goal**: fleet notices, the hygiene report, the Prometheus metrics and the event stream carry findings and outcomes only.

**Independent test**: `EventV2Test` green; a fleet run and a hygiene run have no budget, token or cost text.

### Event stream

- [X] T035 [US3] Create `docs/schema/watcher.event.v2.schema.json` per `contracts/event-v2.md` (no removed field; `schema` const `watcher.event.v2`).
- [X] T036 [US3] Edit `docs/schema/watcher.event.v1.schema.json`: add `"retired": "0.5.0"` to the description; keep its content.
- [X] T037 [US3] Edit `src/Observe/EventSchema.php` and `src/Observe/EventFactory.php`: emit `watcher.event.v2`; remove `cost_usd`, `tokens_in`, `tokens_out`, `tokens_cached` from the run record and the whole budget record.
- [X] T038 [US3] Edit `src/Observe/RunObservation.php`, `src/Observe/RunPublisher.php`, `src/Observe/SinkDispatcher.php`, `src/Observe/HttpSink.php`, `src/Observe/ObserveCounters.php`, `src/Observe/ObserveSettings.php`: remove the budget and cost carry-through; keep counts.
- [X] T039 [US3] Edit `src/Observe/MetricCatalog.php` and `src/Observe/PrometheusSink.php`: delete every metric whose name contains `usd`, `budget`, `spend`, `cost`, `tokens_today`; keep run, finding and outcome metrics.

### Hygiene: remove the usage and cost ledger

- [X] T040 [US3] Delete `src/Hygiene/Cost/CostLedger.php`, `src/Hygiene/Cost/CostReader.php`, `src/Hygiene/Cost/CostReport.php`, `src/Hygiene/Cost/LedgerAnalyzer.php` with `git rm`; delete their tests under `tests/Unit/Hygiene/` that test cost.
- [X] T041 [US3] Edit `src/Hygiene/Hygiene.php`, `src/Hygiene/HygieneOptions.php`, `src/Hygiene/HygieneResult.php`, `src/Hygiene/Report.php`, `src/Hygiene/ShapeReport.php`, `src/Hygiene/DayRecord.php`: remove the cost ledger, the usage section, and cost fields.
- [X] T042 [US3] Edit `src/Laravel/HygieneCommand.php` and `src/Cli/Application.php` (hygiene entry only): remove cost output.

### Fleet

- [X] T043 [US3] Delete `src/Fleet/FleetBudget.php`; edit `src/Fleet/FleetConfig.php`, `src/Fleet/FleetRunner.php`, `src/Fleet/FleetResult.php`, `src/Fleet/FleetSeam.php`, `src/Fleet/FleetCheck.php`, `src/Fleet/CollectingNotifier.php`: remove the fleet budget, its checks and notices.
- [X] T044 [US3] Edit `src/Laravel/FleetCommand.php`: remove budget output.

### Digest and notices (findings only)

- [X] T045 [US3] Edit `src/Notify/DigestData.php`, `src/Notify/DigestRenderer.php`, `src/Notify/NoticeRenderer.php`, `src/Notify/Notifier.php`: remove the cost line, the budget line and the token footer; add the per-step call count line (research R6); no currency or token figure.
- [X] T046 [US3] Rewrite the digest and notice tests under `tests/Unit/Notify/` and `tests/EndToEnd/DigestTest.php`, `FleetBudgetTest.php`, `FleetTest.php`, `FleetFlagsTest.php`, `FleetMetricsSentryTest.php`, `MetricsSentryTest.php`, `ObserveTest.php`, `ObservePrivacyTest.php` for the new output; remove cost and budget assertions.
- [X] T047 [US3] Run `vendor/bin/phpunit --filter 'EventV2|Fleet|Hygiene|Observe|Digest|Notice|Metric'` and the gates; commit `feat(017): fleet, hygiene, digest and events without spend`.

**Checkpoint US3**: `EventV2Test` green; no budget or cost in fleet, hygiene, digest or metric output.

---

## Phase 6: User Story 4 - MCP without budgets (Priority: P3)

**Goal**: MCP tools and setup prompts never ask for or report prices, budgets or spend.

**Independent test**: `NoBudgetNamesTest` green over `src/`; MCP tool lists have no budget or price parameter.

- [X] T048 [US4] Edit `src/Mcp/SetupCatalog.php`: remove every `WATCHER_PRICE_*` and budget entry and their names from `COMMON_VARS`.
- [X] T049 [US4] Edit `src/Mcp/ConfigGenerator.php`: stop writing the `prices` block, `daily_budget_usd`, and any budget key; generate no budget section.
- [X] T050 [US4] Edit `src/Mcp/Tools/GenerateConfigTool.php`, `src/Mcp/Tools/StatusTool.php`, `src/Mcp/Tools/CheckTool.php`, `src/Mcp/Tools/DryRunTool.php`, `src/Mcp/Tools/FirstDayTool.php`: remove budget and cost parameters, outputs and the `prices` check name.
- [X] T051 [US4] Edit `src/Mcp/McpCommand.php`, `src/Mcp/PromptCatalog.php` (remove the cost-too-high prompt text), `src/Mcp/SetupPlanner.php`, `src/Mcp/DryRunGuard.php`, `src/Mcp/DryRunPipeline.php`, `src/Mcp/Envelope.php`, `src/Mcp/ToolError.php`: remove budget and cost wording and fields.
- [X] T052 [US4] Edit `src/Operate/Doctor.php`: remove the `prices` check and its name; keep `models`; edit `src/Operate/Heartbeat.php`, `src/Operate/HeartbeatStatus.php`, `src/Operate/JsonSummary.php`, `src/Operate/RunStats.php`, `src/Operate/RunSummaryData.php`: remove cost and budget fields.
- [X] T053 [US4] Rewrite `tests/Unit/Mcp/*` (ToolSchemaDriftTest, SetupToolsTest, ConfigFileValidatorTest, CheckToolTest, DryRunGuardTest, McpProcessTest, McpCommandTest, RolloutInspectorTest) and `tests/Unit/Operate/*` for the new outputs.
- [X] T054 [US4] Run `vendor/bin/phpunit --filter 'Mcp|Doctor|Operate|ToolSchema'` and the gates; commit `feat(017): MCP and operate without budgets or prices`.

**Checkpoint US4**: MCP output and setup contain no budget or price term.

---

## Phase 7: Pipeline neighbours, CLI and remaining matches

- [X] T055 Edit `src/Laravel/CheckCommand.php`, `src/Laravel/RunCommand.php`, `src/Cli/Application.php` (remaining lines): remove budget and cost output text.
- [X] T056 Edit the files that matched on words only (`src/Issues/IssueGroupBuilder.php`, `src/Issues/SentryIssueSource.php`, `src/Issues/SentryMerge.php`, `src/ApiContext/ApiContextReader.php`, `src/ApiContext/EndpointContext.php`, `src/Reduce/EventCollector.php`, `src/Reduce/MiningStats.php`, `src/Reduce/TemplateMiner.php`, `src/Select/CandidateReason.php`, `src/Select/Tier.php`, `src/Source/*.php`, `src/Schedule/RunGuard.php`, `src/Events/EventsRun.php`, `src/Metrics/PrometheusSource.php`): check each match; change only real budget, cost, price or spend references; leave authentication tokens and unrelated words untouched.
- [X] T057 Run `grep -rnIE "budget|usd|Usd|USD|CostCalculator|UnknownModelPrice|costUsd|cost_usd|token_limit|tokens_today|WATCHER_PRICE" src` and make it print nothing except authentication-token and unrelated matches (list them in the commit message).
- [X] T058 Run `vendor/bin/phpunit --filter 'NoBudgetNames|RemovedKeys|EventV2|SameFindings|NoSpendCounters|ReplyShape'` (every test green) and the gates; commit `feat(017): remaining source matches removed`.

---

## Final Phase: Documentation, release and code review

- [X] T059 [P] Edit `docs/configuration.md`: remove the budget, price and cost keys and the `WATCHER_PRICE_*` row; add the removed-keys table from `contracts/config-migration.md`.
- [X] T060 [P] Edit `docs/operations.md`, `docs/observability.md`, `docs/privacy.md`, `docs/architecture.md`, `docs/kubernetes.md`, `README.md`: remove cost and budget text and metric names.
- [X] T061 [P] Edit `docs/smoke-test.md`: remove the price variables and check 6 (cost comparison); replace check 6 with the finding-count check. Smoke run itself is not executable here.
- [X] T062 [P] Edit `docs/examples/smoke.config.php`, `docs/examples/orders-api.loki.config.php`, `docs/examples/watcher-fleet.php`, `docs/examples/metrics-queries.md`: remove the price arrays and budget keys.
- [X] T063 [P] Edit `docs/dashboards/grafana-watcher.json`, `grafana-loki.json`, `grafana-prometheus.json`, `mapping.md`, `docs/dashboards/grafana-alerts.yaml`, `docs/dashboards/prometheus-rules.yaml`: delete panels and alert rules that read removed metrics; keep panels that read run and finding metrics. Validate each JSON with `php -r 'json_decode(file_get_contents("<file>"), true, 512, JSON_THROW_ON_ERROR);'`.
- [X] T064 Edit `CHANGELOG.md`: add `## 0.5.0 — YYYY-MM-DD — Remove budgets and token and cost accounting` above `## 0.4.0`, with the removed keys, removed metrics, event v2 and v1 retired, the list of removed command outputs, the statement that nothing is converted, and the upgrade steps from `plan.md` (Migration path).
- [X] T065 [P] Set `VERSION` to `0.5.0` (must equal the CHANGELOG heading).
- [ ] T066 Run `composer validate --strict`, `composer audit --locked`, `make release-guards-test`; record exit codes.
- [X] T067 Run the full gates (`composer test`, `composer stan`, `composer style`), read exit codes, and commit `release(017): 0.5.0 prepared (not tagged)`.
- [ ] T068 Final code review: launch the `pr-review-toolkit:code-reviewer` agent over `git diff develop...HEAD` with the spec and plan as context. Fix every verified finding in a new commit per finding group; re-run gates before each commit.
- [ ] T069 Remove `specs/017-remove-budgets/baseline-files.txt` (created in T003).
- [X] T070 Confirm `vendor/bin/phpunit --filter NoBudgetNames` is green (the guard in T005) and that the only matches left are in the CHANGELOG and historical specs.
- [ ] T-SMOKE Live smoke against a real provider (quickstart section 6). Not executable without the owner's explicit permission.

---

## Dependencies & execution order

- Phase 1 → Phase 2 → Phase 3 (US1) → Phase 4 (US2) → Phase 5 (US3) → Phase 6 (US4) → Phase 7 → Final.
- Phase 2 tests T004–T010 can be written in parallel (different test files); T008 depends on the current code only.
- Within Phase 3: T011 must precede T012–T018 (deletions first); T019–T022 (state store) can run in parallel with T011–T018 because they touch different files; T023–T026 depend on Analyze and state; T027–T028 depend on T023–T026.
- Phase 5 groups are parallel: event stream (T035–T039), hygiene (T040–T042), fleet (T043–T044), digest (T045–T046).
- Phase 6 groups are parallel to Phase 5 once Phase 3 is green (MCP and Operate do not touch Observe or Hygiene), except `src/Operate/Heartbeat*` which also appears in Phase 5 via observe: serialise those two.

## Parallel execution with subagents

Subagents take disjoint file groups and run in parallel; each follows red, then green, then refactor, and commits its stage only when the gates exit 0.

- **Subagent A** (Phase 3 Analyze): T011–T018, T027.
- **Subagent B** (Phase 3 State): T019–T022.
- **Subagent C** (Phase 4 Config): T030–T034.
- **Subagent D** (Phase 5 Events): T035–T039.
- **Subagent E** (Phase 5 Hygiene and Fleet): T040–T044.
- **Subagent F** (Phase 5 Digest): T045–T046.
- **Subagent G** (Phase 6 MCP): T048–T053.
- **Subagent H** (Phase 7 neighbours): T055–T057.
- **Subagent I** (Final docs): T059–T063, T065.

Pipeline and state integration (T023–T026, T028) run after A and B finish, in one agent.

## Implementation strategy

- **MVP**: Phases 1–4 (removed keys fail, analysis without budgets). The digest still has a footer until Phase 5, so the MVP is not releasable alone.
- **Release**: after the final phase and the final code review (T068). Tag and merge need the owner's go-ahead.

## Notes

- No task adds a budget, price, spend or cost name anywhere (T005 enforces it).
- Authentication tokens (Loki, Datadog, Sentry, webhooks) are not budget tokens and are never touched.
- Model ids and prices never appear in code or fixtures (Principle VI).
