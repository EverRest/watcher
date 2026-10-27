# Feature Specification: Token-Only Budgets and Reporting

**Feature Branch**: `feat/017-token-only-budgets`

**Created**: 2026-10-27

**Status**: Draft

**Target release**: 0.5.0 (minor bump under pre-1.0 semver, because configuration keys change)

**Input**: User description: "Spec 017: token-only budgets and reporting. Remove USD cost everywhere the package computes it: model prices, daily_budget_usd and run_budget_usd, budget alerts in currency, the digest cost line, the hygiene cost ledger and cost report, fleet budgets, cost metrics, and the MCP setup/check tools. Budgets become token limits only. The digest reports tokens in, out and cached per step and per run instead of cost. Keep the privacy pipeline, the per-step provider routing from spec 016, and the failure rules unchanged. Old USD keys fail at config load with a message naming the replacement key. Smoke test check 6 compares tokens with the provider console instead of cost."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Limit spend in tokens (Priority: P1)

An operator sets how many tokens a service may spend per day and per run, and the package stops calling the model when the next call would exceed that limit. No dollar figure is needed or shown.

**Why this priority**: It is the whole point of the change. Dollar caps need prices that drift and that the package cannot know.

**Independent Test**: With a daily token limit smaller than one analysis call, a run reserves nothing, makes no provider call, and sends one notice that names the token limit.

**Acceptance Scenarios**:

1. **Given** a daily token limit and no spend today, **When** a step's worst case fits, **Then** the step runs and its tokens are counted against the day.
2. **Given** a daily token limit that the next step's worst case would exceed, **When** the run reaches that step, **Then** the step is skipped, the digest says how many groups were skipped, and no provider call is made for it.
3. **Given** a per-run token limit, **When** the run has spent it, **Then** the run stops before the next billable step and says so.

---

### User Story 2 - Read what a run used, in tokens (Priority: P1)

An operator reads the digest and sees, for each step and for the run, the tokens sent, the tokens received and the tokens served from cache, with the share of the daily limit used. No cost line appears.

**Why this priority**: The digest is what operators read every day; it must still explain the spend.

**Independent Test**: A recorded run through each of the three providers produces a digest whose footer lists tokens in, out and cached per step, and contains no currency amount.

**Acceptance Scenarios**:

1. **Given** a run with triage and analysis, **When** the digest is built, **Then** the footer lists tokens in, out and cached for triage and for analysis, and the run total.
2. **Given** a daily token limit, **When** 75% of it is used, **Then** the alert notice reports the percentage in tokens.

---

### User Story 3 - Migrate an existing configuration (Priority: P2)

An operator with a configuration written for USD budgets and prices runs `watcher check` and is told, key by key, what to change. Nothing is silently ignored.

**Why this priority**: Breaking configuration changes must be loud (Principle VII), and the migration is what 0.5.0 users need first.

**Independent Test**: A configuration containing `analysis.daily_budget_usd` and `analysis.prices` fails at load; the message names `analysis.daily_token_limit` as the replacement for the first and states that prices are no longer used for the second.

**Acceptance Scenarios**:

1. **Given** a configuration with `daily_budget_usd`, **When** it loads, **Then** it fails before any external call, naming `analysis.daily_token_limit`.
2. **Given** a configuration with `analysis.prices`, **When** it loads, **Then** it fails naming the removal and stating that prices are not used in 0.5.0.
3. **Given** a configuration with neither, **When** it loads, **Then** it loads with no currency fields in its effective view.

---

### User Story 4 - Fleet and hygiene reports in tokens (Priority: P2)

A fleet operator sets fleet-wide token caps, and the hygiene report shows token use per day and per step instead of cost.

**Why this priority**: The fleet and hygiene features read the same budgets, so they must change with them.

**Independent Test**: A fleet with a token cap stops the service whose next step would exceed it, and the hygiene report lists tokens per day with no currency column.

**Acceptance Scenarios**:

1. **Given** a fleet token cap, **When** the fleet's next step would exceed it, **Then** the step is skipped and the fleet notice says so in tokens.
2. **Given** a hygiene run over the last 14 days, **When** the report is produced, **Then** it lists tokens per day and per step, and no currency amount appears.

---

### User Story 5 - Smoke test compares tokens (Priority: P3)

The first live smoke run compares the tokens the digest reports with the usage page of the provider console, instead of comparing cost.

**Why this priority**: Operators need a check that does not depend on the package's price table.

**Independent Test**: The smoke guide's check 6 names tokens, and its results template has no cost field.

### Edge Cases

- A USD key and a token key both present: the USD key still fails the load, naming the token key.
- A daily token limit of 0 or negative: rejected at load.
- A provider reply with a usage count missing: the run fails loudly as an invalid reply (unchanged).
- A budget already spent when the run starts: the triage-only day rule applies unchanged, counted in tokens.
- The MCP setup and check tools no longer ask for prices, and a stored price from an older run is ignored with a notice rather than used.
- Hygiene history written before 0.5.0 that holds cost figures: read as tokens where tokens were stored; otherwise the cost entries are reported as "pre-0.5.0, no tokens recorded" and not converted.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The package MUST NOT compute, store as a current figure, or display any monetary amount for model use.
- **FR-002**: Budgets MUST be expressed in tokens only: a daily token limit (`analysis.daily_token_limit`) and a per-run token limit that replaces `analysis.run_budget_usd`. A limit is null (unset) or a positive integer.
- **FR-003**: Each billable step MUST reserve its worst case in tokens before the call (planned input tokens plus the output cap, and for a retry the doubled cap), and run only if the reservation fits the daily and per-run limits. This is the existing reservation rule, counted in tokens.
- **FR-004**: The budget alert thresholds (`analysis.budget_alert_percents`) MUST apply to the daily token limit, and each alert MUST state the percentage and the tokens used.
- **FR-005**: The digest MUST report, for each step and for the run, the tokens in, tokens out and tokens served from cache, plus the share of the daily token limit used when a limit is set. It MUST NOT contain a currency amount.
- **FR-006**: The following keys MUST be removed and MUST fail at config load with a message that names the replacement (or states removal): `analysis.prices`, `analysis.daily_budget_usd`, `analysis.run_budget_usd`, `budget.daily_budget_usd` (fleet), and the `WATCHER_PRICE_*` and `WATCHER_*_BUDGET_USD` environment variables in the MCP setup catalog.
- **FR-007**: The hygiene cost ledger and cost report MUST record and display tokens per day and per step. Ledger entries written before 0.5.0 that hold tokens are read as tokens; entries that hold only cost MUST be reported as "no tokens recorded" and never converted.
- **FR-008**: Cost metrics and their Prometheus or event names MUST be replaced by token metrics of the same shape, and the old names MUST be listed in the changelog as removed.
- **FR-009**: The MCP setup and check tools MUST NOT ask for or report prices or currency.
- **FR-010**: The privacy pipeline, the per-step provider routing from spec 016, and the failure rules MUST remain unchanged. Any failure remains a visible notice or a non-zero exit.
- **FR-011**: The smoke guide's check 6 MUST compare the digest's tokens with the provider console's usage for the same window, within about 10%.
- **FR-012**: The changelog for 0.5.0 MUST list every removed key and environment variable, its replacement, and the upgrade steps.

### Key Entities *(include if feature involves data)*

- **Token limit**: a daily and a per-run cap on tokens, null or a positive integer.
- **Token usage**: per step and per run, tokens in, tokens out, tokens from cache, and the counts of groups sent, skipped or cut by a limit.
- **Ledger entry (tokens)**: per day and per step, the token usage above. Replaces the cost ledger entry.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A configuration with any removed key fails at load in 100% of cases, and the message names the replacement or the removal.
- **SC-002**: A run with a daily token limit never makes a provider call that the limit would have refused, in 100% of recorded scenarios.
- **SC-003**: No digest, hygiene report, fleet notice or MCP output contains a currency amount, in 100% of recorded scenarios across the three providers.
- **SC-004**: For each provider, the tokens shown in the digest equal the provider's reported usage for the same recorded replies, in 100% of fixtures.
- **SC-005**: An operator can switch an existing USD-configured service to token limits by following the changelog, without reading the source.

## Assumptions

- Version 0.5.0 is a minor release under pre-1.0 semver; the change is breaking for configuration and is documented as such. A 1.0.0 release is a separate decision.
- The per-run token limit has no default: the daily token limit remains the only default-off cap, as today. Per-run limits derived from the daily limit (the old USD rule) are dropped; a run that needs a per-run cap sets one explicitly.
- Token counts come from the providers' reported usage, as in spec 016. No token estimate is shown as a reported figure.
- Prices are no longer read anywhere. Operators who want a dollar figure compare the token counts with the provider's own pricing; the package does not convert.
- The hygiene cost ledger keeps its existing retention and expiry rules (Principle I, 30 days); only its units change.
- Prometheus and event sink consumers that read cost names must rename; the changelog lists the mapping.
- The privacy corpus is unchanged: this feature changes no data that leaves the service.
