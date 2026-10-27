# Data Model: Token-Only Budgets and Reporting

No new persisted entity. The state store keeps one counter per service per day, in tokens. Everything else is configuration or in-memory per run.

## Token limit (configuration)

| Field | Type | Default | Rule |
|---|---|---|---|
| `analysis.daily_token_limit` | int > 0 or null | null | per service per day; reserved before each billable step |
| `analysis.run_token_limit` | int > 0 or null | null | per run; reserved the same way |
| `budget.daily_token_limit` | int > 0 or null | null | fleet-wide cap on top of each service's own |
| `analysis.budget_alert_percents` | ascending ints 1..99 | `[25,50,75,90]` | applied to the daily token limit |

## Token usage (per call, in memory)

| Field | Type | Source |
|---|---|---|
| `inputTokens` | int >= 0 | provider reply (uncached input) |
| `outputTokens` | int >= 0 | provider reply (includes thinking tokens where reported) |
| `cacheReadTokens` | int >= 0 | provider reply |
| `cacheWriteTokens` | int >= 0 | provider reply, 0 where not reported |

## Day counter (state store)

| Key | Value | Notes |
|---|---|---|
| `spend:tokens:<service>:<day>` | int tokens in, out and cache summed | replaces `spend:usd`; not read from the old key |

## Usage ledger entry (hygiene, per day and step)

| Field | Type |
|---|---|
| `day` | date |
| `step` | triage, analyze or escalate |
| `calls`, `tokens_in`, `tokens_out`, `tokens_cached` | int |

An entry that holds only cost from 0.4.x is reported as "no tokens recorded"; it is never converted.

## Validation rules

- A limit is null or a positive integer; zero or negative fails at load.
- A removed key (see `contracts/config-migration.md`) fails at load, naming its replacement.
- Each billable step's reservation must fit the daily limit and the run limit when set.
