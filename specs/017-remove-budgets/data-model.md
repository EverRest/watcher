# Data Model: Remove Budgets and Token and Cost Accounting

## Removed entities

- **Budget** (daily and per-run, USD and tokens): configuration only; removed.
- **Price table** (per model, four rates): configuration only; removed.
- **Spend counter** (per service per day): state store; removed from the code, not deleted from the store (R4).
- **Reservation** (worst case per call): in-memory and store-atomic; removed.
- **Usage ledger entry** (per day and step, cost): hygiene store; removed.

## Kept entities

- **Run findings**: findings and outcome of one analysis, unchanged.
- **Run outcome and counts**: outcome, groups, candidates, findings by severity, calls per step, escalations, reused, muted, truncated. Unchanged, minus any spend or token field.
- **Call count per step**: number of model calls made for each step in a run, shown in the digest. No unit.

## Removed fields (event v1 to v2, metrics, digest)

| Where | Removed |
|---|---|
| Run record | `cost_usd`, `tokens_in`, `tokens_out`, `tokens_cached` |
| Budget record | `run_cost_usd`, `spent_today_usd`, `daily_budget_usd`, `usd_ratio`, `tokens_today`, `daily_token_limit`, `token_ratio`, `triage_only` |
| Metrics | every name containing `usd`, `budget`, `spend`, `cost`, `tokens_today` |
| Digest | the cost line, the budget line, the token footer |
| Hygiene report | the usage and cost section |

## Validation rules

- A removed configuration key fails at load (contracts/config-migration.md).
- A reply without a usage object, or with a negative count, is an invalid reply (R2).
- The event v2 record has no removed field (contracts/event-v2.md).
