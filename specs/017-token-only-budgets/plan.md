# Implementation Plan: Token-Only Budgets and Reporting

**Branch**: `feat/017-token-only-budgets` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/017-token-only-budgets/spec.md`

## Summary

Remove every USD figure the package computes or displays. Budgets become token limits: `analysis.daily_token_limit` (already present) and a new per-run token limit that defaults to null. Prices leave the configuration and the MCP setup. The digest, the hygiene report, the fleet notices, the metrics and the event stream report tokens in, out and cached per step and per run. Old USD keys fail at config load, naming the replacement. The release is 0.5.0.

The reservation rule (worst case before each billable step) and the privacy, routing and failure rules stay as they are. Only the unit changes: the reservation counts tokens instead of cost.

## Technical Context

**Language/Version**: PHP 8.2 (CI runs 8.2 to 8.5)

**Primary Dependencies**: none new. The Anthropic SDK and the Guzzle adapters from spec 016 are unchanged.

**Storage**: the state store keeps daily spend. Today it stores USD and tokens. After this change it stores tokens only. Existing stored USD values are not converted (see research R4).

**Testing**: PHPUnit through ParaTest, recorded fixtures, no network. Shared contract suites stay as they are.

**Target Platform**: unchanged (Kubernetes CronJob, local development, Laravel and Lumen adapters).

**Project Type**: library (PHP package).

**Performance Goals**: unchanged. The change removes arithmetic and adds none.

**Constraints**: Principles I (privacy), II (read-only), III (no network in tests), V (token budgets, expected change zero), VI (configuration, not code, for every limit), VII (loud failures), VIII (no new dependency).

**Scale/Scope**: 50 source files, about 120 test files, 3 documentation sets (configuration, operations, smoke test), 1 published event schema, 1 Grafana dashboard set, the CHANGELOG. Full inventory in the "Files touched" section.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How the plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass | Nothing leaves the service that did not before. Price tables leave the configuration, not a payload. The privacy corpus runs unchanged. |
| II. Read-Only by Design | Pass | No write or admin action is added or removed. |
| III. Test-First | Pass with a larger test change | Tests for each removed key, the token reservation and the digest come first (tasks phase). Tests asserting USD values are rewritten, not deleted. |
| IV. Code Decides, the Model Judges | Pass | Selection and reservation are still code. |
| V. Token and Cost Discipline | Pass | Expected change: **zero** tokens for the same window (the same prompts and replies). The per-run token limit can only lower spend. |
| VI. Configuration over Hard-Coding | Pass, and stronger | Removing prices from configuration removes a per-model table the operator had to keep current. No price is in code. |
| VII. Failures Are Loud | Pass | Removed keys fail at load (FR-006). A budget stop still produces a notice. |
| VIII. Simplicity | Pass | Removes a concept (currency conversion, price tables, cost calculator) and adds none. Contracts: the existing `Budget*` and `Spend` names keep one meaning (tokens). |

Gate result: **pass**. Three decisions below need the owner's confirmation before tasks (see "Decisions for the owner").

## Decisions for the owner

These follow from the spec and the code survey. Each has a recommended answer. The plan records the recommendation; implementation waits for the answer.

1. **Event schema.** `watcher.event.v1` is published (`docs/schema/watcher.event.v1.schema.json`). Its `run` and `budget` records require `cost_usd`, `run_cost_usd`, `spent_today_usd`, `daily_budget_usd`, `usd_ratio`. Removing them changes the contract. **Recommended:** publish `watcher.event.v2` without those fields and document v1 as retired in 0.5.0. The alternative (keep v1 and emit zeros) would be a lie, so it is rejected.
2. **Prometheus names.** The `watcher_*_usd` gauges are deleted, not renamed. Token gauges already exist (`tokens_today`, `daily_token_limit`) and cover the same need. Dashboards and alert rules that read the USD gauges are rewritten against the token gauges in the same change. No alias is kept. **Decided 2026-10-27: delete.**
3. **Stored spend.** The state store holds USD spend for the day. **Decided 2026-10-27: drop it.** The 0.5.0 day counter starts at zero in tokens. The old USD value is not converted, because cost cannot be computed accurately without the old prices. A run on the first day after upgrade may spend more than the old cap allowed; the changelog says so.

**Principle of this decision (2026-10-27, owner):** cost is not computed anywhere, so nothing keeps a cost name, a cost field or a cost-shaped placeholder. Where a v1 record field held cost, v2 drops it; it is not kept as zero.

## Files touched

The inventory comes from a search of `src/`, `tests/` and `docs/` for `usd`, `Usd`, `USD`, `CostCalculator`, `UnknownModelPrice`, `prices` and `costUsd`. The search found 50 source files and 121 test files. Groups:

### Analyze (`src/Analyze/`)

| File | Change |
|---|---|
| `CostCalculator.php` | **Delete** (no currency left). |
| `UnknownModelPrice.php` | **Delete**. |
| `Usage.php` | Drop `costUsd`; keep the four token counts. |
| `Spend.php` | Track tokens per day and per run only. |
| `BudgetReservation.php` | Reserve tokens (planned input + output cap, retry doubled as before). Per-run token limit added. |
| `BudgetAlerts.php` | Thresholds apply to the daily token limit; messages in tokens. |
| `Alert.php` | Alert text in tokens. |
| `AnthropicAnalyzer.php`, `ModelRouter.php` | Remove the cost argument from the reservation calls. Routing unchanged (spec 016). |
| `OutputValidator.php`, `GeminiProvider.php`, `OpenAiProvider.php`, `AnthropicSdkProvider.php` | Remove any price or cost reference; token fields unchanged. |

### Config (`src/Config/`)

| File | Change |
|---|---|
| `WatchConfig.php` | Remove `prices`, `daily_budget_usd`, `run_budget_usd`; add `daily_token_limit` checks (positive or null) and `run_token_limit`; fail with replacement messages (FR-006). |
| `KeyCatalog.php` | Remove the removed keys; add `analysis.run_token_limit`; `analysis.daily_token_limit` summary in tokens. |
| `Factory.php` | Build the reservation and alerts with token limits only; drop `CostCalculator`. |

### Fleet (`src/Fleet/`)

| File | Change |
|---|---|
| `FleetConfig.php`, `FleetBudget.php`, `FleetSeam.php`, `FleetRunner.php`, `FleetResult.php` | Fleet caps become `budget.daily_token_limit` only. Remove `budget.daily_budget_usd`. Fleet notices in tokens. |

### Hygiene (`src/Hygiene/`)

| File | Change |
|---|---|
| `Cost/CostLedger.php` | Ledger entries store tokens per day and per step. Rename to `UsageLedger` in the same change (the name "cost" is removed). |
| `Cost/CostReader.php` | Reads token entries; entries holding only cost are reported "no tokens recorded" (spec Clarifications). |
| `Cost/CostReport.php` | Report in tokens; no currency column. |
| `Cost/LedgerAnalyzer.php` | Records tokens per call. |
| `Hygiene.php`, `HygieneOptions.php`, `HygieneResult.php`, `Report.php`, `ShapeReport.php` | Remove cost fields from options, results and the report shape. |

### Observe (`src/Observe/`)

| File | Change |
|---|---|
| `EventSchema.php`, `EventFactory.php` | Emit `watcher.event.v2` (Decision 1). Remove `cost_usd`, `run_cost_usd`, `spent_today_usd`, `daily_budget_usd`, `usd_ratio`. |
| `MetricCatalog.php`, `PrometheusSink.php` | Delete the `watcher_*_usd` gauges (Decision 2). Token gauges stay. |
| `RunObservation.php` | Carry tokens only. |

### Notify (`src/Notify/`)

| File | Change |
|---|---|
| `DigestData.php`, `DigestRenderer.php` | Footer: tokens in, out and cached per step and per run; share of the daily token limit when set. No currency. |
| `NoticeRenderer.php` | Budget notices in tokens. |

### Operate, state, run (`src/Operate/`, `src/State/`, root)

| File | Change |
|---|---|
| `Doctor.php` | The `prices` check is removed. The `models` check stays (spec 016). |
| `Heartbeat.php`, `HeartbeatStatus.php`, `JsonSummary.php`, `RunStats.php`, `RunSummaryData.php` | Cost fields replaced by tokens. |
| `State/Keys.php`, `State/ReservableCounters.php`, `State/RedisStateStore.php` | The counter that held USD now holds tokens. The key name changes (`spend:usd` to `spend:tokens`), so the old key is simply unread after upgrade (research R4). |
| `OutcomeRegistry.php`, `RunLedger.php`, `RunResult.php`, `Watcher.php`, `Analysis.php` | Remove currency from outcomes, results and the run pipeline; token budgets only. |

### MCP (`src/Mcp/`)

| File | Change |
|---|---|
| `SetupCatalog.php` | Remove the six `WATCHER_PRICE_*` entries and the price names from `COMMON_VARS`. |
| `ConfigGenerator.php` | Stop writing price placeholders (spec 016 wrote `[null, null, null, null]`); no price block at all. Budget keys in tokens. |
| `SetupPlanner.php`, `PromptCatalog.php`, `McpCommand.php`, `DryRunGuard.php`, `DryRunPipeline.php` | Remove price and cost wording. |
| `Tools/CheckTool.php`, `Tools/StatusTool.php`, `Tools/DryRunTool.php`, `Tools/GenerateConfigTool.php` | Remove currency from outputs; tool schemas change (ToolSchemaDriftTest updates). |

### Other source

| File | Change |
|---|---|
| `Analysis.php`, `RunResult.php`, `Events/EventsRun.php`, `Issues/SentryMerge.php`, `Source/*`, `Reduce/TemplateMiner.php` | Only the words that match the search (`cost` in comments or unrelated names). Checked one by one; most are unrelated. |
| `Laravel/CheckCommand.php`, `Laravel/FleetCommand.php`, `Laravel/HygieneCommand.php`, `Laravel/RunCommand.php`, `Cli/Application.php` | Output text in tokens. |

### Documentation and release

| File | Change |
|---|---|
| `docs/configuration.md` | Remove `analysis.prices`, `daily_budget_usd`, `run_budget_usd`, `budget.daily_budget_usd`; add `run_token_limit`. Migration table. |
| `docs/operations.md`, `docs/observability.md`, `docs/privacy.md` | Cost wording to tokens. Metric names. |
| `docs/smoke-test.md` | Check 6 compares tokens with the console (FR-011). Remove price variables from the env block. |
| `docs/examples/*.php`, `docs/examples/metrics-queries.md` | Remove price arrays and budget USD keys. |
| `docs/schema/watcher.event.v1.schema.json` | Add `watcher.event.v2.schema.json`; keep v1 file, marked retired (Decision 1). |
| `docs/dashboards/*.json`, `grafana-alerts.yaml`, `prometheus-rules.yaml`, `mapping.md` | Metric names and panel units to tokens (Decision 2). |
| `README.md` | Feature list and the limits example. |
| `CHANGELOG.md` | 0.5.0 section: removed keys and their replacements, removed metrics and their replacements, event v2, state reset (research R4), upgrade steps (FR-012). |
| `VERSION` | `0.5.0`. |

### Tests

About 121 test files reference the removed names. The change is: rewrite tests that assert USD values to assert token values; delete tests that exist only for the cost calculator and the price table; add the new tests in the list below.

- Delete: `CostCalculatorTest.php`, `ProviderCostTest.php` (spec 016 cost test; replaced by token test), the price cases in `WatchConfigProvidersTest.php`, and the price cases in `WatchConfigTest.php`.
- Rewrite (USD to tokens): `BudgetTest.php`, `BudgetAlertsTest.php`, `BudgetReservationTest.php`, `BudgetReservationRunCapTest.php`, `BudgetFitDependenciesTest.php`, `SpendTest.php`, `SpendRetrySafetyTest.php`, `SpendDayBoundaryTest.php`, `ModelRouterTest.php`, `ModelRouterFailClosedTest.php`, `AnthropicAnalyzerTest.php`, `AnthropicAnalyzerTriageTest.php`, the fleet budget tests, the hygiene cost tests, the digest and notice tests, the metrics and event tests, the MCP tool tests (`ToolSchemaDriftTest.php`, `SetupToolsTest.php`, `ConfigFileValidatorTest.php`, `CheckToolTest.php`, `DryRunGuardTest.php`), `SecretDumpTest.php` (the price-free config), and the end-to-end suites that check cost lines (`CostTest.php`, `DigestTest.php`, `FleetBudgetTest.php`, `HostileVolumeBudgetTest.php`, `ReservationTest.php`, `RunCapTest.php`, `ProviderRunTest.php`).
- Add: a repository-wide guard test that fails if `usd`, `Usd`, `USD`, `CostCalculator` or `cost_usd` appears in `src/`, `docs/` or the shipped examples, except in the CHANGELOG and this spec family (so cost cannot creep back in).
- Add: migration tests (each removed key fails at load with its replacement named; `analysis.client` rule unchanged), per-run token limit tests (default null; a run that reaches it stops before the next billable step), token-only digest footer test (no currency string in any recorded digest across three providers), event v2 schema test (every emitted run record validates against v2 and v1 fields are absent), state-reset test (a stored USD value is ignored, not read as tokens).
- Privacy corpus: unchanged. No new outbound data.

## Migration path

Operators on 0.4.x upgrade in this order:

1. Run `watcher check` on the new version with the old configuration. It fails at load and names each removed key with its replacement:
   - `analysis.daily_budget_usd` → `analysis.daily_token_limit` (token count; the old USD value has no direct equivalent, so the operator picks a number from the console's usage page);
   - `analysis.run_budget_usd` → `analysis.run_token_limit` (optional; default unset);
   - `analysis.prices` → removed; the message says prices are no longer used and tokens replace them;
   - `budget.daily_budget_usd` (fleet) → `budget.daily_token_limit`;
   - `WATCHER_PRICE_*` variables → no longer read.
2. Replace the limits with token numbers, delete the price block, run `watcher check` until it passes.
3. Update dashboards and alert rules to the `_tokens` metric names (mapping in the CHANGELOG). Update consumers of event v1 to v2.
4. Expect the first day's spend counter to start at zero (research R4).

The configuration migration is documented as a table in `docs/configuration.md` and in the CHANGELOG. `analysis.client` rules from spec 016 are unchanged.

## Expected token change

**Zero** input and output tokens for the same window, the same configuration (apart from removed prices) and the same recorded replies. The prompts, the payload, the schemas and the routing do not change. The new per-run limit is off by default (null), so it cannot change a run that has no limit set. The token-budget fixtures run unchanged for all three providers, and the expected output is the same token numbers in the digest instead of the cost line.

## Project Structure

### Documentation (this feature)

```text
specs/017-token-only-budgets/
├── plan.md              # this file
├── research.md          # R1 to R5
├── data-model.md        # tokens-only entities and their fields
├── quickstart.md        # how to validate 0.5.0 offline
├── contracts/
│   ├── config-migration.md   # removed keys and replacements (load-time contract)
│   └── event-v2.md           # watcher.event.v2 record shapes
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks), not created here
```

### Source code

The file inventory above is the source structure for this change. No new directory is created. Two files are renamed (`Cost/CostLedger.php` to `Cost/UsageLedger.php`, `Cost/CostReader.php` to `Cost/UsageReader.php`, `Cost/CostReport.php` to `Cost/UsageReport.php`) and two are deleted (`CostCalculator.php`, `UnknownModelPrice.php`).

**Structure Decision**: rename the hygiene cost classes to usage classes, because the word "cost" in a class name would keep the removed concept alive. Keep the namespace `Watcher\Hygiene`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Event schema v2 alongside v1 retired | The v1 schema is published and consumers read it; removing required fields in place would break them silently | Keeping v1 with zeroed cost fields would report a figure that means nothing |
| Metric rename `_usd` to `_tokens` | Dashboards and alerts use the names | Keeping the names with token values would mislabel units |

## Open items for tasks

- Confirm the three decisions above before tasks start.
- Verify the state store key rename in Redis and PDO, and the file store, with the shared contract suite (principle III).
- The smoke test check 6 (FR-011) needs a provider console reading; it is a manual check, not a test.
