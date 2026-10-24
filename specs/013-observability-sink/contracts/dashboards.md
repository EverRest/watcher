# Contract: metrics, queries, dashboards and alert rules

What ships under `docs/dashboards/` and the names it may use. A test (`DashboardsDocsTest`) extracts every metric name
and every event field from those files and fails if one is not in the catalogs below.

## Files

| File | Content |
|---|---|
| `docs/dashboards/grafana-loki.json` | Grafana dashboard (schemaVersion 39+, datasource variable `${DS_LOKI}`) on the event stream; works for the `log`, `file` and `http` paths as long as events reach Loki |
| `docs/dashboards/grafana-prometheus.json` | Grafana dashboard on the textfile metrics (`${DS_PROMETHEUS}`) |
| `docs/dashboards/prometheus-rules.yaml` | Prometheus alerting rules (group `watcher`) |
| `docs/dashboards/grafana-alerts.yaml` | Grafana-managed alert rules in file-provisioning format, on Loki (for operators with no Prometheus) |
| `docs/dashboards/mapping.md` | Field table, shipper snippets (Promtail/Alloy, Filebeat/Vector, Datadog Agent), example queries for Kibana/OpenSearch and Datadog; Metabase/Superset notes |
| `docs/schema/watcher.event.v1.schema.json` | the JSON Schema |

Both dashboards use template variables `service` and `environment` (multi, include all). Both have the same top row:
last outcome, time since last success, last run cost, today's budget ratio.

## Loki path

**Collection.** The shipper reads the `log` sink's stream (container stderr) or the `file` sink's file, keeps only lines
containing `"schema":"watcher.event.v1"`, and promotes `service` and `environment` to labels (both are bounded by the
number of services). Everything else stays in the JSON line and is read with `| json`. Never promote `fingerprint`,
`title`, `run_id` or `event_id` to labels (unbounded cardinality in Loki). Alloy/Promtail snippet in mapping.md:

```
{job="watcher"} | json | event_type = "run"
```

**Panels and queries** (`$svc` = `service=~"$service"`, `$env` = `environment=~"$environment"`):

| Panel | Query |
|---|---|
| Failed runs in 24 h (stat, red above 0) | `sum(count_over_time({job="watcher",$svc,$env} \| json \| event_type="run" \| outcome="failed" [24h])) or vector(0)` |
| Runs by outcome (bars) | `sum by (outcome) (count_over_time({job="watcher",$svc,$env} \| json \| event_type="run" [$__interval]))` |
| Successful runs in 36 h (stat, alert source) | `sum(count_over_time({job="watcher",$svc,$env} \| json \| event_type="run" \| outcome=~"reported\|quiet\|baselined" [36h])) or vector(0)` |
| Findings by severity (stacked bars) | `sum by (severity) (count_over_time({job="watcher",$svc,$env} \| json \| event_type="finding" [$__interval]))` |
| New critical findings (stat) | `sum(count_over_time({job="watcher",$svc,$env} \| json \| event_type="finding" \| severity="critical" \| group_trend="new" [$__range]))` |
| Findings (table; needs `include_text`) | logs panel, `{job="watcher",$svc,$env} \| json \| event_type="finding" \| line_format "{{.severity}} {{.title}} ({{.group_count}}x, {{.group_trend}}) check: {{.check}}"` |
| Top groups (table) | `{job="watcher",$svc,$env} \| json \| event_type="group" \| rank <= 10` with columns fingerprint, level, trend, count, previous_count |
| Events read / groups per run | `sum by (service) (sum_over_time({job="watcher",$svc,$env} \| json \| event_type="run" \| unwrap events_read [$__interval]))`, same for `groups_total` |
| Cost per run (USD) | `sum by (service) (sum_over_time({job="watcher",$svc,$env} \| json \| event_type="run" \| unwrap cost_usd [$__interval]))` |
| Tokens (in/out/cached) | same with `unwrap tokens_in`, `tokens_out`, `tokens_cached` |
| Budget ratio | `max by (service) (max_over_time({job="watcher",$svc,$env} \| json \| event_type="budget" \| unwrap usd_ratio [$__interval]))`, threshold lines at 0.9 and 1.0 |
| Heartbeat age (s) | `max by (service) (max_over_time({job="watcher",$svc,$env} \| json \| event_type="heartbeat" \| unwrap age_seconds [$__interval]))` |
| Warnings | `{job="watcher",$svc,$env} \| json \| event_type=~"config_warning\|delivery_failure"` (logs panel) |
| Run log (logs) | `{job="watcher",$svc,$env} \| json \| event_type="run" \| line_format "{{.outcome}} {{.reason}} findings={{.findings_total}} cost={{.cost_usd}}"` |

Loki's `json` parser flattens objects with `_` (`findings_by_severity_critical`, `calls_triage`); the dashboards use those
names.

## Prometheus path: metric catalog (closed)

Written by the `prometheus` sink as text format 0.0.4 with `# HELP` and `# TYPE`. All metrics carry labels `service` and
`environment` (`environment="unset"` when none). Per-service series count in the last column; **total 48 per service**.

| Metric | Type | Extra label (values) | Series | Meaning |
|---|---|---|---|---|
| `watcher_info` | gauge | `version` | 1 | always 1; carries the package version |
| `watcher_last_run_timestamp_seconds` | gauge | | 1 | unix seconds when the last run event was built |
| `watcher_last_success_timestamp_seconds` | gauge | | 1 | last `reported`/`quiet`/`baselined`; omitted until the first success (alert uses `absent`) |
| `watcher_last_run_duration_seconds` | gauge | | 1 | |
| `watcher_metrics_written_timestamp_seconds` | gauge | | 1 | when the file was written |
| `watcher_last_run_outcome` | gauge | `outcome` (reported, quiet, baselined, failed, skipped_done, skipped_locked) | 6 | 1 for the last outcome, 0 for the rest |
| `watcher_runs_total` | counter | `outcome` (same 6) | 6 | persisted counter (state store, expiry `state.retention`) |
| `watcher_last_run_findings` | gauge | `severity` (info, warn, error, critical) | 4 | findings of the last run |
| `watcher_last_run_new_findings` | gauge | `severity` (same 4) | 4 | of those, whose first group is `new` |
| `watcher_last_run_groups` | gauge | `trend` (new, grown, unchanged, expected) | 4 | |
| `watcher_last_run_events_read` | gauge | | 1 | |
| `watcher_last_run_groups_total` | gauge | | 1 | |
| `watcher_last_run_candidates` | gauge | | 1 | |
| `watcher_last_run_truncated` | gauge | | 1 | 0/1 |
| `watcher_last_run_tokens` | gauge | `direction` (in, out, cached) | 3 | |
| `watcher_last_run_cost_usd` | gauge | | 1 | |
| `watcher_budget_spent_usd` | gauge | | 1 | today's spend; omitted when unknown |
| `watcher_budget_limit_usd` | gauge | | 1 | omitted when no cap |
| `watcher_budget_ratio` | gauge | | 1 | the larger of the USD and token ratios; omitted when no cap |
| `watcher_heartbeat_age_seconds` | gauge | | 1 | at the time of the run |
| `watcher_sink_failures_total` | counter | `sink` (log, file, http, prometheus) | 4 | persisted |
| `watcher_delivery_failures_total` | counter | `channel` (slack, telegram, discord) | 3 | persisted |

Series per service: 1+1+1+1+1+6+6+4+4+4+1+1+1+1+3+1+1+1+1+1+4+3 = 48 (omitted ones lower the count, never raise it).
Label values come only from the enumerations above and from `service`/`environment`. Metric names never change inside
`v1`; a rename is a new metric with the old kept for one MINOR release.

Why gauges "of the last run" and not per-run counters: the package runs once a day and exits. A textfile cannot hold
history, Prometheus does (the scrape keeps every value), and `last_over_time` / `changes()` give the per-run view.
Only the three failure/run counters need state because they must keep growing across processes.

**Panels** (`grafana-prometheus.json`):

| Panel | PromQL |
|---|---|
| Time since last success (stat) | `time() - watcher_last_success_timestamp_seconds{service=~"$service",environment=~"$environment"}` |
| Last outcome | `watcher_last_run_outcome{service=~"$service",environment=~"$environment"} == 1` (value mapping on `outcome`) |
| Runs by outcome | `sum by (outcome) (increase(watcher_runs_total{service=~"$service",environment=~"$environment"}[1d]))` |
| Findings by severity | `sum by (severity) (watcher_last_run_findings{...})` |
| New findings by severity | `sum by (severity) (watcher_last_run_new_findings{...})` |
| Groups by trend | `sum by (trend) (watcher_last_run_groups{...})` |
| Events read / groups / candidates | `watcher_last_run_events_read`, `watcher_last_run_groups_total`, `watcher_last_run_candidates` |
| Cost and tokens | `watcher_last_run_cost_usd`, `sum by (direction) (watcher_last_run_tokens)` |
| Budget | `watcher_budget_ratio` with thresholds 0.9 and 1; `watcher_budget_spent_usd` / `watcher_budget_limit_usd` |
| Duration | `watcher_last_run_duration_seconds` |
| Sink and delivery failures | `sum by (sink) (increase(watcher_sink_failures_total[1d]))`, `sum by (channel) (increase(watcher_delivery_failures_total[1d]))` |

The Prometheus dashboard shows no finding text (metrics carry none). The Loki dashboard is the one with the findings
table; a team that wants both points both datasources at the same Grafana.

## Alert rules (examples, both languages)

| Alert | Prometheus (`prometheus-rules.yaml`) | Grafana-managed on Loki (`grafana-alerts.yaml`) |
|---|---|---|
| `WatcherNoSuccessfulRun36h` (critical) | `time() - watcher_last_success_timestamp_seconds > 129600` and `absent(watcher_last_success_timestamp_seconds{service="<name>"})` for a named service | `sum by (service) (count_over_time({job="watcher"} \| json \| event_type="run" \| outcome=~"reported\|quiet\|baselined" [36h])) < 1`, no-data = Alerting |
| `WatcherRunFailed` (warning) | `watcher_last_run_outcome{outcome="failed"} == 1` | `sum by (service) (count_over_time({job="watcher"} \| json \| event_type="run" \| outcome="failed" [1d])) > 0` |
| `WatcherBudgetHigh` (warning) | `watcher_budget_ratio > 0.9` | `max by (service) (max_over_time({job="watcher"} \| json \| event_type="budget" \| unwrap usd_ratio [1d])) > 0.9` |
| `WatcherNewCriticalFinding` (warning) | `watcher_last_run_new_findings{severity="critical"} > 0` | `sum by (service) (count_over_time({job="watcher"} \| json \| event_type="finding" \| severity="critical" \| group_trend="new" [1d])) > 0` |
| `WatcherDeliveryFailures` (warning) | `increase(watcher_delivery_failures_total[1d]) > 0 or increase(watcher_sink_failures_total[1d]) > 0` | `sum by (service) (count_over_time({job="watcher"} \| json \| event_type="delivery_failure" [1d])) > 0` |

Each rule carries `summary` and `description` annotations without any event text (the alert must not copy finding text
into a pager), a `runbook_url` placeholder, and labels `severity` and `team` placeholders. Thresholds are examples: 36 h
assumes a daily run; the Operator sets `stale_after` and the alert together.

## Mapping to other tools

`docs/dashboards/mapping.md` holds, for each event type, the field table of contracts/event-schema.v1.md with the
backend type (Elasticsearch/OpenSearch: `keyword` for enumerations and ids, `date` for `ts`/`window_*`/`first_seen`/
`last_seen`, `long`, `double`, `boolean`, `text` for the finding text fields; Datadog: facets vs measures), the shipper
snippet (Filebeat `decode_json_fields` with `target: watcher`, Vector `parse_json`, Datadog Agent `source`/`service`
tags with a date remapper on `ts` and a service remapper on `service`) and these example queries:

| Question | Kibana (KQL) | Datadog logs |
|---|---|---|
| Failed runs | `watcher.event_type : "run" and watcher.outcome : "failed"` | `@event_type:run @outcome:failed` |
| New critical findings | `watcher.event_type : "finding" and watcher.severity : "critical" and watcher.group_trend : "new"` | `@event_type:finding @severity:critical @group_trend:new` |
| Cost by service | Lens: sum of `watcher.cost_usd` for `event_type : "run"` by `watcher.service` | measure `@cost_usd`, filter `@event_type:run`, group by `service` |
| No run in 36 h | Kibana alerting: log threshold on `watcher.event_type : "run"` with "no data" -> alert | Monitor: log count `@event_type:run -@outcome:skipped*` below 1 over 36 h, "notify on no data" |

Metabase and Superset read the JSONL file or an Elasticsearch/Postgres import: the field table is the column list;
`run` is the fact table, `finding` and `group` join on `run_id`.
