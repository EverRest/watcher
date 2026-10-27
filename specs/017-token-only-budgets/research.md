# Research: Token-Only Budgets and Reporting

Decisions taken while planning. Each: decision, why, rejected alternative. Checked 2026-10-27.

## R1. Units: tokens are the only budget unit

**Decision.** Every limit, reservation, alert and report is in tokens. `analysis.daily_token_limit` is the daily cap (existing key). `analysis.run_token_limit` is the per-run cap, null by default.

**Why.** Cost cannot be computed accurately: provider prices change, the package does not know them, and the operator's bill is the only truth. Tokens are reported exactly by the providers (spec 016), so they are the only unit the package can state as fact.

**Rejected.** Keep USD with prices in configuration (spec 016 position). It keeps a table the operator must maintain and a figure that is only an estimate.

## R2. Reservation rule

**Decision.** Unchanged in structure: a billable step reserves its worst case before the call (planned input tokens plus the output cap; a retry reserves the doubled cap, at most 8192). It runs only if the reservation fits the daily and the per-run limit. Only the unit changes.

**Why.** The rule already prevents overspend; changing it would be a second change.

**Rejected.** A reservation based on an average from past runs. It can under-reserve on a day with long inputs.

## R3. Expected token change

**Decision.** Zero input and output tokens for the same window and the same recorded replies.

**Why.** Prompts, payloads, schemas, routing and retries are the same code paths; only the accounting changes.

## R4. Stored day counter after upgrade

**Decision.** The stored spend key changes name (`spend:usd` to `spend:tokens`). The old key is not read. The counter starts at zero on the first day of 0.5.0.

**Why.** The old value is in currency and cannot be converted. Reading it as tokens would be silently wrong (Principle VII).

**Consequence.** On the first day after upgrade, a service may spend up to its full daily token limit again, including tokens already spent that day under 0.4.x. The changelog states this and recommends upgrading early in the day.

**Rejected.** Convert old USD spend with the last known prices. Prices are no longer in configuration, so the conversion would be made up.

## R5. Event schema and metrics

**Decision.** `watcher.event.v2` drops every cost field (`cost_usd`, `run_cost_usd`, `spent_today_usd`, `daily_budget_usd`, `usd_ratio`) and keeps the token fields. v1 is kept as a published file, marked retired. Metrics `watcher_*_usd` are deleted; the token gauges stay.

**Why.** A field kept at zero reports a false figure. The published v1 schema requires the cost fields, so v1 cannot be emitted once they are gone.

**Rejected.** Keep v1 with zeroed cost fields (dishonest), or rename metrics to `_tokens` (duplicates the token gauges that already exist).
