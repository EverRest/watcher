# Feature Specification: Remove Budgets and Token and Cost Accounting

**Feature Branch**: `feat/017-remove-budgets-and-reports`

**Created**: 2026-10-27

**Status**: Draft

**Target release**: 0.5.0 (breaking: configuration keys removed; constitution 2.0.0 applies)

**Input**: User description: "Spec 017: remove budgets and all token and cost accounting from the package (constitution 2.0.0, Principle V). Remove daily and per-run budgets and caps, budget alerts and reservations, the spend counters in the state store, the digest token and cost footer, the hygiene usage and cost ledger and report, fleet budget notices, token and cost metrics and event fields, the MCP budget and cost tools and setup prompts, and the model prices table. Keep the privacy pipeline, per-step provider routing (spec 016), the failure rules, the analysis itself, and the digest findings. The package reports findings only; no spend figure of any unit appears anywhere. Removed keys fail at config load with a message naming that they were removed in 0.5.0 and that no replacement exists. Document the migration: operators delete the keys; nothing is converted."

## Clarifications

### Session 2026-10-27

- Q: Is the package allowed to keep any token count for operators? → A: Only inside the analysis itself, where the provider returns a reply; no count is stored, summed, reported, exported or compared against a limit.
- Q: Does the digest show how many model calls a run made? → A: Yes, as a count of calls per step, not a currency or token figure.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Run without any budget (Priority: P1)

An operator configures a service without any budget, price or cost key. The package analyses the logs, posts findings, and never stops for a spend reason.

**Why this priority**: It is the new default and the reason for the change.

**Independent Test**: A configuration with no budget, price or cost key runs a recorded window end to end and posts the same findings as before, with no spend line and no budget notice.

**Acceptance Scenarios**:

1. **Given** a configuration without any budget or price key, **When** a run analyses a window with findings, **Then** the digest lists the findings and contains no amount of money and no token figure.
2. **Given** a run that would previously have stopped at a daily cap, **When** it runs now, **Then** it runs to completion, because no cap exists.

---

### User Story 2 - Removed keys fail loudly (Priority: P1)

An operator with a 0.4.x configuration upgrades and runs `watcher check`. Every removed key fails at load, and the message says the key was removed in 0.5.0 and that nothing replaces it.

**Why this priority**: Silent acceptance would leave an operator believing a cap still applies when it does not.

**Independent Test**: A configuration that still contains any removed key fails before any external call, naming that key.

**Acceptance Scenarios**:

1. **Given** a configuration with `analysis.daily_budget_usd`, **When** it loads, **Then** it fails naming that key and stating that it was removed in 0.5.0 with no replacement.
2. **Given** a configuration with `analysis.prices`, **When** it loads, **Then** it fails naming the key, with the same statement.
3. **Given** a configuration with `budget.*` or `analysis.run_budget_usd` or `analysis.daily_token_limit`, **When** it loads, **Then** it fails naming the key, with the same statement.

---

### User Story 3 - Fleet, hygiene and metrics without spend (Priority: P2)

A fleet operator and a platform engineer see no spend anywhere: fleet notices, the hygiene report, the Prometheus metrics and the event stream carry findings and run outcomes only.

**Why this priority**: These are the other places spend appeared; the change is incomplete without them.

**Independent Test**: A fleet run and a hygiene run produce reports and metric scrapes that contain no budget, token or cost name.

**Acceptance Scenarios**:

1. **Given** a fleet with several services, **When** a run completes, **Then** no fleet notice mentions a budget, a cap or a spend figure.
2. **Given** a hygiene run, **When** the report is produced, **Then** it lists findings and outcomes and no usage or cost section.
3. **Given** a metrics scrape, **When** it is read, **Then** no metric name or label refers to budget, spend, token usage or cost.

---

### User Story 4 - MCP without budgets (Priority: P3)

An assistant using the MCP tools is not asked about prices, budgets or spend, and the setup and status tools report run outcomes only.

**Why this priority**: The tools must not offer a concept the package no longer has.

**Independent Test**: The MCP tool list and the setup prompts contain no budget, price or cost parameter.

**Acceptance Scenarios**:

1. **Given** the MCP setup flow, **When** it generates a configuration, **Then** the file contains no price, budget or cost key.
2. **Given** the status tool, **When** it is called, **Then** its output has no spend field.

### Edge Cases

- A configuration with a removed key and no other budget setting: fails at load (not silently ignored).
- A state store that still holds spend counters from 0.4.x: the counters are never read and never deleted by this release; the package does not touch them.
- A digest with zero findings: unchanged from today (no spend line in either version).
- A model that a removed price table once listed: no longer matters; the package makes the call and reports findings only.
- A run that previously stopped at a budget: now runs to completion; the operator sees this in the changelog.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The package MUST NOT compute, store, compare, report or export any budget, spend, price, cost or token-usage figure.
- **FR-002**: The configuration keys `analysis.daily_budget_usd`, `analysis.run_budget_usd`, `analysis.prices`, `analysis.daily_token_limit`, `analysis.budget_alert_percents`, `analysis.max_runs_per_day`, and every `budget.*` key MUST fail at config load with a message naming the key, stating it was removed in 0.5.0, and stating that no replacement exists.
- **FR-003**: The `WATCHER_PRICE_*` environment variables MUST NOT be read or documented.
- **FR-004**: The state store MUST NOT hold a spend counter. Previously stored counters MUST NOT be read or deleted by this release.
- **FR-005**: The digest MUST NOT contain a spend line, a budget notice, or any currency or token figure. It MAY state the number of model calls per step.
- **FR-006**: The hygiene usage and cost ledger and its report MUST be removed. The hygiene command MUST report findings and outcomes only.
- **FR-007**: Fleet budget notices and fleet budget settings MUST be removed.
- **FR-008**: Metrics and event records MUST NOT contain any budget, spend, price, cost or token-usage field or name. Event records keep run outcome and finding counts.
- **FR-009**: The MCP tools and setup prompts MUST NOT ask for or report prices, budgets or spend.
- **FR-010**: The privacy pipeline, the per-step provider routing from spec 016, the failure rules, and the analysis MUST remain unchanged in behaviour.
- **FR-011**: The changelog for 0.5.0 MUST list every removed key, metric, event field and command output, and MUST state that nothing is converted and that operators delete the keys.
- **FR-012**: The repository MUST contain a guard that fails if a budget, price or spend name appears in the shipped source, documentation or examples, except in the changelog and in historical specifications.

### Key Entities *(include if feature involves data)*

- **Run findings**: the findings and outcome of one analysis, unchanged.
- **Call count**: the number of model calls per step in a run, reported in the digest. It carries no unit of money or tokens.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of removed keys fail at config load, with the message required by FR-002, in the contract tests.
- **SC-002**: 0 occurrences of a budget, price, spend or cost name in shipped source, documentation and examples, verified by the guard (FR-012).
- **SC-003**: For a recorded window, the findings listed in the digest are identical before and after this change, for each of the three providers.
- **SC-004**: An operator with a 0.4.x configuration reaches a passing `watcher check` by deleting the removed keys, following the changelog, without reading the source.

## Assumptions

- Version 0.5.0 is a minor bump under pre-1.0 semver; the configuration break is documented in the changelog.
- Constitution 2.0.0 (Principle V) is in force; this spec implements it.
- Operators already accept that the package cannot stop a run for cost; spend control is theirs at the provider's console.
- The state store's old spend counters stay in place until an operator clears them; the package neither reads nor writes them after upgrade.
- Analysis still calls the model and still uses the provider's reply; only accounting and budgets are removed.
- Per-step provider routing from spec 016 and the privacy corpus are unchanged.
