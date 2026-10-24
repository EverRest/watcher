# Contract: `watcher.event.v1`

Machine form: [watcher.event.v1.schema.json](watcher.event.v1.schema.json) (draft; shipped as
`docs/schema/watcher.event.v1.schema.json`). This page is the human form and the compatibility rules. If the two
disagree, the schema is wrong or this page is: a test (`EventSchemaTest`) compares the field lists.

## Compatibility rules

- The name in `schema` is the contract. Inside `v1` fields are never removed, renamed or retyped, and enumerations
  only grow where marked **open**. New optional fields may appear in a MINOR package release: consumers MUST ignore
  unknown fields. Producers (this package) emit nothing outside the schema.
- A breaking change is `watcher.event.v2`, announced one MINOR release ahead, with both emitted for one release
  (`observe.schema_versions` is not provided until then).
- Field names are `snake_case`; enumeration values are lowercase; booleans are JSON booleans.
- Timestamps: `YYYY-MM-DDTHH:MM:SSZ`, UTC, second precision. Durations: integers (`_ms`, `_seconds`). Money: `_usd`
  floats. Ratios: floats where `1.0` is 100 %, or `null` when the limit is unset.
- Names avoid words that common backends reserve: `trend` (not `status`: Datadog reserves `status` for the log
  severity), `environment` (mapped to `env` by the Datadog remapper, see docs/dashboards/mapping.md), `ts` (not
  `@timestamp`/`timestamp`: remapped in the shipper). In Elasticsearch/OpenSearch parse the line under a namespace
  (`watcher.*`) so `service` does not collide with the ECS `service` object.
- One event per line in line-oriented sinks; `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; keys in the order
  of the tables below (envelope first, `schema` first of all, so `|= "\"schema\":\"watcher.event.v1\""` is a cheap filter).
- Delivery is at-least-once: dedupe on `event_id`.

## Envelope (all event types)

| Field | Type | Meaning |
|---|---|---|
| `schema` | const `"watcher.event.v1"` | |
| `event_type` | enum | `run`, `finding`, `group`, `budget`, `heartbeat`, `delivery_failure`, `config_warning` (**open**) |
| `event_id` | string | `<run_id>-<n>`, `n` 1-based per run |
| `ts` | timestamp | host clock when the batch was built |
| `service` | string | `service` config value |
| `environment` | string | `environment` config value, or `"unset"` |
| `run_id` | 16 hex | random per run; a retry of the same window gets a new one |
| `version` | string | package version (`JsonSummary::packageVersion`) |
| `window_start`, `window_end` | timestamp or null | the analysed window; null when no window exists |

`environment` is `"unset"` rather than null so that a group-by never has a missing key.

## `run` (exactly one per run)

| Field | Type | Source |
|---|---|---|
| `outcome` | enum | `RunOutcome`: reported, quiet, baselined, failed, skipped_done, skipped_locked |
| `reason` | string or null | `JsonSummary::reason()` (short code, never prose with data) |
| `dry_run` | bool | |
| `release` | string or null | deploy info, only when it matches `[A-Za-z0-9._+-]{1,64}` |
| `duration_ms`, `events_read`, `groups_total`, `candidates`, `findings_total` | int or null | `RunStats` |
| `findings_by_severity` | `{info,warn,error,critical}` ints | |
| `groups_by_trend` | `{new,grown,unchanged,expected}` ints | all groups of the run, not only the top |
| `calls` | `{triage,analyze,escalate}` ints | |
| `tokens_in`, `tokens_out`, `tokens_cached`, `cost_usd` | numbers | |
| `escalations`, `reused`, `not_analysed`, `muted` | ints | `RouterResult`, digest data |
| `truncated` | bool | `event_cap` or `group_cap` was hit |

## `finding` (one per finding, `finding_index` in digest order)

| Field | Type | Notes |
|---|---|---|
| `fingerprint` | 12 hex | first fingerprint, as in the digest |
| `fingerprints` | list of 12 hex, at most 5 | |
| `kind` | enum | bug, regression, anomaly, noise |
| `severity` | enum | info, warn, error, critical |
| `confidence` | 0..1 | |
| `escalated` | bool | |
| `group_trend`, `group_count`, `group_previous_count` | enum/int, nullable | of the first fingerprint's group |
| `after_release` | bool | the group is new after the deployed release |
| **text** `title`, `evidence`, `hypothesis`, `next_step`, `check`, `related` | strings (caps as the analyzer schema: 80/240/240/200/200, 3 x 80) | only with `include_text`; `hypothesis` = `suspected_cause`, `next_step` = `suggested_action`, `check` = `how_to_check` |

## `group` (top `observe.top_groups` by count, `rank` 1-based)

`fingerprint`, `level` (lowercase `Level` name), `trend`, `count`, `previous_count` (null = unknown), `first_seen`,
`last_seen`, `has_finding`, `watch_rules` (names from configuration, at most 10). **Text**: `error_type` (class name,
after the redactor's second scan). Never: template, frames, fields, labels, raw lines.

## `budget` (one per run that read spend)

| Field | Notes |
|---|---|
| `state` | `ok` (< 90 %), `high` (>= 90 % and < 100 %), `exhausted` (>= 100 % or triage-only day) of the larger of the two ratios |
| `run_cost_usd`, `spent_today_usd`, `daily_budget_usd`, `usd_ratio` | `usd_ratio` null when no cap |
| `tokens_today`, `daily_token_limit`, `token_ratio` | null when no limit |
| `threshold_pct` | highest configured `budget_alerts` percent reached today, `0` if none, `100` exhausted |
| `triage_only` | the triage-only day of spec 001 |

## `heartbeat` (one per run when `heartbeat.enabled`)

`state` (the `HeartbeatStatus` state: ok, stale, failed, unknown), `last_outcome`, `last_ok_at`, `age_seconds`,
`stale_after_seconds` (config), `warnings` (list of `sink_failed:<sink>`; **open** pattern). Sink failures of the run in
progress are not in its own heartbeat (the batch is built before the flush); they are in the next run's (research R9).

## `delivery_failure`

`channel` (slack, telegram, discord), `kind` (digest, notice, weekly, other), `status_class` (http_4xx, http_429,
http_5xx, network, timeout, other), `http_status` (int or null). No message, URL, token or body: the status class is
derived from `DeliveryFailed`'s code, never its text.

## `config_warning`

| `code` | Emitted when |
|---|---|
| `include_text_external` | an `http` sink has `include_text: true` (`key` = `observe.http.include_text`) |
| `http_insecure_allowed` | `observe.http.allow_insecure` is true |
| `event_cap_hit` | `source.event_cap` reached (`count` = events read) |
| `group_cap_hit` | `source.group_cap` reached (`count` = overflowed events) |
| `comparison_gap` | the comparison snapshot is older than one window (`count` = days) |
| `analysis_partial` | `not_analysed` or `notTriaged` > 0 (`count`) |
| `check_test` | `watcher check --post-test` probe |

## Chat fact -> event counterpart (FR-032)

| Chat message | Event |
|---|---|
| Digest finding block | `finding` (+ `group` for the counts and trend) |
| Digest header (service, environment, window, release) | envelope + `run` |
| Digest footer (tokens, cost, models) | `run` (tokens, cost; model ids are config, not an event field) |
| `Muted` / `not analysed` / `truncated` lines | `run.muted`, `run.not_analysed`, `run.truncated`, `config_warning` |
| Failure notice | `run` with `outcome: failed` and `reason` |
| Baseline notice | `run` with `outcome: baselined` |
| Budget 25/50/75/90 % and exhausted notices | `budget` |
| Weekly summary | derived by the dashboard from `run` events (counts per outcome per ISO week) |
| Digest delivery failure | `delivery_failure` |
