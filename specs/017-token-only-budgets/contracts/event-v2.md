# Contract: watcher.event.v2

Replaces `watcher.event.v1`, which stays as a published file marked retired.

## Removed from v1

`cost_usd` (run), `run_cost_usd`, `spent_today_usd`, `daily_budget_usd`, `usd_ratio` (budget).

## Kept (unchanged names)

`tokens_in`, `tokens_out`, `tokens_cached`, `calls`, `escalations`, `reused`, `not_analysed`, `muted`, `truncated` (run); `state`, `tokens_today`, `daily_token_limit`, `token_ratio`, `threshold_pct`, `triage_only` (budget).

## Added

`run_token_limit` and `run_token_ratio` (budget), null when unset.

## Compatibility

- `schema` is `watcher.event.v2`; consumers select on it.
- Metric names: the USD gauges are deleted; token gauges are unchanged.
- The v2 schema file is `docs/schema/watcher.event.v2.schema.json`; the v1 file keeps its content and gains a `retired: 0.5.0` note in its description.
