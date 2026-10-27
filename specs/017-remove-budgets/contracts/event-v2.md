# Contract: watcher.event.v2

Replaces `watcher.event.v1`, which stays as a published file marked `retired: 0.5.0`.

## Removed from v1

`cost_usd`, `tokens_in`, `tokens_out`, `tokens_cached` (run record); the whole budget record (`run_cost_usd`, `spent_today_usd`, `daily_budget_usd`, `usd_ratio`, `tokens_today`, `daily_token_limit`, `token_ratio`, `triage_only`).

## Kept

Common: `schema`, `event_type`, `event_id`, `ts`, `service`, `environment`, `run_id`, `version`, `window_start`, `window_end`.

Run record: `outcome`, `reason`, `dry_run`, `release`, `duration_ms`, `events_read`, `groups_total`, `candidates`, `findings_total`, `findings_by_severity`, `groups_by_trend`, `calls`, `escalations`, `reused`, `not_analysed`, `muted`, `truncated`.

## Compatibility

- `schema` is `watcher.event.v2`; consumers select on it.
- Consumers ignore unknown properties (the additive rule of the v1 schema).
- Metrics named in the removed list are deleted; no alias.
- The v2 schema file is `docs/schema/watcher.event.v2.schema.json`; the v1 file keeps its content and gains `retired: 0.5.0`.
