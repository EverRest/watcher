# Implementation Plan: Remove Budgets and Token and Cost Accounting

**Branch**: `feat/017-remove-budgets-and-reports` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/017-remove-budgets/spec.md`

## Summary

Constitution 2.0.0 (Principle V) removes budgets and every token and cost accounting from the package. This plan removes the budget and reservation machinery, the spend counters, the price table, the digest footer, the hygiene cost ledger, the fleet budgets, the cost and token-usage fields of the event stream and of the metrics, and the MCP budget and price tools. Analysis, privacy, per-step routing (spec 016) and failure rules stay. Removed configuration keys fail at load with a fixed message and no replacement. Nothing is converted; operators delete the keys.

Expected change to findings for the same window: **none**. Calls, prompts, payloads, routing and retries are unchanged.

## Technical Context

**Language/Version**: PHP 8.2 (CI runs 8.2 to 8.5)

**Primary Dependencies**: none added. Removes no dependency (the Anthropic SDK and the Guzzle adapters stay).

**Storage**: the state store loses its spend counters and its reservation interface (`ReservableCounters`). Old counters are neither read nor deleted (research R4).

**Testing**: PHPUnit through ParaTest; recorded fixtures; no network. Contract suites for the state stores change (the reservation contract goes).

**Target Platform**: unchanged.

**Project Type**: library (PHP package), Laravel and Lumen adapters.

**Performance Goals**: unchanged. Fewer state-store writes per call.

**Constraints**: Principles I, II, III, IV, VI, VII, VIII of constitution 2.0.0. Principle V no longer carries budgets.

**Scale/Scope**: 108 source files matched, 171 test files, 24 documentation and dashboard files (see Files touched).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How the plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Nothing new leaves the service. The privacy corpus runs unchanged. Removing counters removes state that held no personal data. |
| II. Read-Only by Design | Pass | No write or admin action added. Removing the spend counters removes a write. |
| III. Test-First | Pass | Red tests first (tasks Phase 2): removed-key failures, the no-currency guard, event v2 shape, identical findings. |
| IV. Code Decides, the Model Judges | Pass | Selection and routing unchanged. |
| V. Lean Requests (2.0.0) | Pass | The package keeps no budget and no spend or token report, as the amended principle requires. |
| VI. Configuration and Contracts | Pass | Removing keys removes configuration. No price moves into code. |
| VII. Failures Are Loud | Pass | Removed keys fail at load (FR-002). Failure notices unchanged. |
| VIII. Simplicity | Pass, and simpler | Removes a reservation contract, a cost calculator, a price table and a ledger. No new contract. |

Gate result: **pass**. No complexity violation.

## Decisions

1. **Budgets and reservations.** Removed entirely. The reservation exists only to enforce budgets, so it goes with them. No step is refused for spend.
2. **Token values inside the analysis.** A reply is still checked for shape (the usage object and its counts must be present and non-negative), because that check is part of the failure rule for invalid replies (spec 016). The counts are read for that check and then discarded. No count is stored, summed, reported, exported, or compared with a limit. This follows the spec Clarification and constitution 2.0.0.
3. **State store.** The file, PDO and Redis stores lose the reservation method and the spend keys. Old spend counters are neither read nor deleted by this release (research R4).
4. **Event stream.** `watcher.event.v2` drops every spend and token-usage field. `watcher.event.v1` is retired and kept as a file marked `retired: 0.5.0` (contracts/event-v2.md).
5. **Digest.** Shows findings and, per step, the number of model calls. No currency, no token figure.
6. **Hygiene.** The usage and cost ledger and its report are removed. The hygiene command reports findings and outcomes only.
7. **Metrics.** Every metric that names budget, spend, price, cost or token usage is removed. Metrics that count runs, findings and outcomes stay.
8. **MCP.** The price prompts, the budget and cost fields and the spend tools are removed. The setup no longer writes a `prices` or budget block.
9. **Migration.** Operators delete the removed keys. Nothing is converted. `watcher check` names every removed key that is still present (contracts/config-migration.md).

## Files touched

Source files matched by the narrowed search (budget, currency, price, spend, token-limit and token-usage names), grouped by module. Each file is either **removed** (a file whose only purpose was budgets, reservations, prices or the cost ledger), **edited** (it keeps a role and loses the budget, cost or usage parts), or **checked** (the match is only a word in a comment or an unrelated name; tasks confirm and leave it unless it is a real budget or cost reference).

Files removed outright (expected): `src/Analyze/BudgetAlerts.php`, `src/Analyze/BudgetReservation.php`, `src/Analyze/CostCalculator.php`, `src/Analyze/Spend.php`, `src/Analyze/UnknownModelPrice.php`, `src/Analyze/Budget.php` (check: it may hold only the endpoint prompt note; keep if so), `src/Fleet/FleetBudget.php`, `src/Hygiene/Cost/CostLedger.php`, `src/Hygiene/Cost/CostReader.php`, `src/Hygiene/Cost/CostReport.php`, `src/Hygiene/Cost/LedgerAnalyzer.php`, `src/Hygiene/DayRecord.php` (check), `src/Operate/EstimatingAnalyzer.php` (budget estimate; check), `src/State/ReservableCounters.php`.

Groups (every matched file; the list is the input to tasks):

### Pipeline (Analysis value) — 1 file(s)

- `src/Analysis.php`

### Analyze (budgets, reservation, spend, prices, usage) — 17 file(s)

- `src/Analyze/Alert.php`
- `src/Analyze/AnalysisResult.php`
- `src/Analyze/AnalyzerFailed.php`
- `src/Analyze/AnthropicAnalyzer.php`
- `src/Analyze/AnthropicSdkProvider.php`
- `src/Analyze/BudgetAlerts.php`
- `src/Analyze/BudgetReservation.php`
- `src/Analyze/CostCalculator.php`
- `src/Analyze/GeminiProvider.php`
- `src/Analyze/ModelReply.php`
- `src/Analyze/ModelRouter.php`
- `src/Analyze/RouterResult.php`
- `src/Analyze/RouterSettings.php`
- `src/Analyze/Spend.php`
- `src/Analyze/TriageResult.php`
- `src/Analyze/UnknownModelPrice.php`
- `src/Analyze/Usage.php`

### ApiContext (match on the word only; check) — 2 file(s)

- `src/ApiContext/ApiContextReader.php`
- `src/ApiContext/EndpointContext.php`

### CLI (output text) — 1 file(s)

- `src/Cli/Application.php`

### Config (keys, validation, catalog) — 3 file(s)

- `src/Config/Factory.php`
- `src/Config/KeyCatalog.php`
- `src/Config/WatchConfig.php`

### Events (run record) — 1 file(s)

- `src/Events/EventsRun.php`

### Fleet (budgets, notices) — 7 file(s)

- `src/Fleet/CollectingNotifier.php`
- `src/Fleet/FleetBudget.php`
- `src/Fleet/FleetCheck.php`
- `src/Fleet/FleetConfig.php`
- `src/Fleet/FleetResult.php`
- `src/Fleet/FleetRunner.php`
- `src/Fleet/FleetSeam.php`

### Hygiene (cost ledger, report) — 10 file(s)

- `src/Hygiene/Cost/CostLedger.php`
- `src/Hygiene/Cost/CostReader.php`
- `src/Hygiene/Cost/CostReport.php`
- `src/Hygiene/Cost/LedgerAnalyzer.php`
- `src/Hygiene/DayRecord.php`
- `src/Hygiene/Hygiene.php`
- `src/Hygiene/HygieneOptions.php`
- `src/Hygiene/HygieneResult.php`
- `src/Hygiene/Report.php`
- `src/Hygiene/ShapeReport.php`

### Issues (match on the word only; check) — 3 file(s)

- `src/Issues/IssueGroupBuilder.php`
- `src/Issues/SentryIssueSource.php`
- `src/Issues/SentryMerge.php`

### Laravel commands (output text) — 4 file(s)

- `src/Laravel/CheckCommand.php`
- `src/Laravel/FleetCommand.php`
- `src/Laravel/HygieneCommand.php`
- `src/Laravel/RunCommand.php`

### MCP (prices, budgets, cost in tools and setup) — 14 file(s)

- `src/Mcp/ConfigGenerator.php`
- `src/Mcp/DryRunGuard.php`
- `src/Mcp/DryRunPipeline.php`
- `src/Mcp/Envelope.php`
- `src/Mcp/McpCommand.php`
- `src/Mcp/PromptCatalog.php`
- `src/Mcp/SetupCatalog.php`
- `src/Mcp/SetupPlanner.php`
- `src/Mcp/ToolError.php`
- `src/Mcp/Tools/CheckTool.php`
- `src/Mcp/Tools/DryRunTool.php`
- `src/Mcp/Tools/FirstDayTool.php`
- `src/Mcp/Tools/GenerateConfigTool.php`
- `src/Mcp/Tools/StatusTool.php`

### Metrics (source, not sink) — 1 file(s)

- `src/Metrics/PrometheusSource.php`

### Notify (digest footer, notices) — 4 file(s)

- `src/Notify/DigestData.php`
- `src/Notify/DigestRenderer.php`
- `src/Notify/NoticeRenderer.php`
- `src/Notify/Notifier.php`

### Observe (event v2, metrics, sinks) — 6 file(s)

- `src/Observe/EventFactory.php`
- `src/Observe/EventSchema.php`
- `src/Observe/HttpSink.php`
- `src/Observe/MetricCatalog.php`
- `src/Observe/PrometheusSink.php`
- `src/Observe/RunObservation.php`

### Operate (doctor, heartbeat, summaries) — 8 file(s)

- `src/Operate/Doctor.php`
- `src/Operate/DryRunStateStore.php`
- `src/Operate/EstimatingAnalyzer.php`
- `src/Operate/Heartbeat.php`
- `src/Operate/HeartbeatStatus.php`
- `src/Operate/JsonSummary.php`
- `src/Operate/RunStats.php`
- `src/Operate/RunSummaryData.php`

### Pipeline (outcomes) — 1 file(s)

- `src/OutcomeRegistry.php`

### Reduce (match on the word only; check) — 3 file(s)

- `src/Reduce/EventCollector.php`
- `src/Reduce/MiningStats.php`
- `src/Reduce/TemplateMiner.php`

### Pipeline (RunLedger) — 1 file(s)

- `src/RunLedger.php`

### Pipeline (RunResult) — 1 file(s)

- `src/RunResult.php`

### Schedule (run guard; check) — 1 file(s)

- `src/Schedule/RunGuard.php`

### Select (match on the word only; check) — 2 file(s)

- `src/Select/CandidateReason.php`
- `src/Select/Tier.php`

### Source (match on the word only; check) — 9 file(s)

- `src/Source/CappedSink.php`
- `src/Source/DatadogSource.php`
- `src/Source/GuardedRequest.php`
- `src/Source/Kubernetes/EventLedger.php`
- `src/Source/Kubernetes/EventMasker.php`
- `src/Source/Kubernetes/KubeApi.php`
- `src/Source/LokiSource.php`
- `src/Source/ResponseLimit.php`
- `src/Source/Retry.php`

### State (spend counters, reservable store) — 7 file(s)

- `src/State/FileStateStore.php`
- `src/State/Keys.php`
- `src/State/RedisStateStore.php`
- `src/State/ReservableCounters.php`
- `src/State/StateCodec.php`
- `src/State/StateStore.php`
- `src/State/StateValueTooLarge.php`

### Pipeline (Watcher.php) — 1 file(s)

- `src/Watcher.php`


### Tests

171 test files match the same names. The change per test is one of: delete (tests of a removed class or behaviour), rewrite (assertions on a removed field become assertions on its absence, or on findings), keep (the match was a word in a fixture or an unrelated name). Tasks list them per file.

### Documentation, dashboards and examples

24 documentation and dashboard files: `README.md`, `docs/architecture.md`, `docs/configuration.md`, `docs/observability.md`, `docs/operations.md`, `docs/privacy.md`, `docs/smoke-test.md`, `docs/kubernetes.md`, `docs/roadmap.md`, `docs/dashboards/*` (Grafana JSON, alert rules, Prometheus rules, mapping), `docs/schema/watcher.event.v1.schema.json` (marked retired), a new `docs/schema/watcher.event.v2.schema.json`, `docs/examples/*.php`. The historical reviews and audits (`docs/audit-2026-10-23.md`, `docs/review-2026-10-23.md`, `docs/review-fixes-2026-10-25.md`, `docs/brief.md`) are historical records and stay unchanged.

`CHANGELOG.md`: a 0.5.0 section. `VERSION`: `0.5.0` at release.

## Migration path

1. Run `watcher check` on 0.5.0 with a 0.4.x configuration. It fails at load and names each removed key still present (contracts/config-migration.md).
2. Delete the removed keys and the `WATCHER_PRICE_*` variables from the environment.
3. Re-run `watcher check` until it passes.
4. Update dashboards and alert rules that read removed metrics (CHANGELOG lists them).
5. Consumers of `watcher.event.v1` switch to v2.
6. Old spend counters in the state store are not read; the package leaves them untouched.

## Expected change

**None** to findings for the same window and configuration (minus the removed keys). Calls, prompts, payloads, schemas and routing are unchanged. The digest loses only the spend line and the budget notice.

## Test changes (summary)

- **New**: removed-key failures with exact messages; no-currency guard over `src/`, `docs/`, `docs/examples/`; event v2 shape; identical findings across the three providers' recorded fixtures.
- **Delete**: budget, reservation, spend, cost-calculator, price, ledger and reservable-store tests.
- **Rewrite**: digest, notice, fleet, hygiene, metrics, event, MCP and status tests to assert findings and the absence of spend fields.
- **Keep**: privacy corpus, provider contract suite, routing tests, failure tests.

## Project Structure

### Documentation (this feature)

```text
specs/017-remove-budgets/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── config-migration.md
│   └── event-v2.md
├── checklists/requirements.md
└── tasks.md           # /speckit-tasks, not created here
```

## Complexity Tracking

No violations.

## Open items for tasks

- The state-store contract suite must still pass after removing the reservation method (run the three store contract tests).
- Confirm the matches marked **check** before editing them.
- Keep the Anthropic SDK's usage parsing where the reply-shape check needs it (Decision 2).
