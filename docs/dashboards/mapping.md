# Mapping the watcher event stream to other tools

The stream is `watcher.event.v2` (see [observability.md](../observability.md) and `docs/schema/watcher.event.v2.schema.json`). One JSON object per line; parse it under a namespace (`watcher`) so `service` does not collide with ECS or Datadog reserved attributes. Inside `v1` fields are never renamed, removed or retyped; ignore fields you do not know. Delivery is at-least-once: de-duplicate on `event_id`.

## Fields

Backend types are suggestions. In Datadog, make low-cardinality fields facets and numbers measures; keep `event_id`, `run_id` and `fingerprint` out of facets that become tags. Nested counts arrive as objects (`findings_by_severity`, `groups_by_trend`, `calls`); Loki flattens them with `_`.

| Field | Event types | Elasticsearch / OpenSearch | Datadog | Meaning |
|---|---|---|---|---|
| `schema` | all | keyword | facet | always `watcher.event.v2` |
| `event_type` | all | keyword | facet | one of the seven types |
| `event_id` | all | keyword | facet | `<run_id>-<n>`; de-duplicate on it |
| `ts` | all | date | date remapper | when the batch was built (UTC) |
| `service` | all | keyword | service remapper | configured service name |
| `environment` | all | keyword | facet | configured environment, `unset` when none |
| `run_id` | all | keyword | facet | 16 hex, random per run |
| `version` | all | keyword | facet | package version |
| `window_start` | all | date | facet | start of the analysed window |
| `window_end` | all | date | facet | end of the analysed window |
| `outcome` | run | keyword | facet |  |
| `reason` | run | keyword | facet |  |
| `dry_run` | run | boolean | facet |  |
| `release` | run | keyword | facet |  |
| `duration_ms` | run | long | measure |  |
| `events_read` | run | long | measure |  |
| `groups_total` | run | long | measure |  |
| `candidates` | run | long | measure |  |
| `findings_total` | run | long | measure |  |
| `findings_by_severity` | run | object | facet |  |
| `groups_by_trend` | run | object | facet |  |
| `calls` | run | object | facet |  |
| `tokens_in` | run | long | measure |  |
| `tokens_out` | run | long | measure |  |
| `tokens_cached` | run | long | measure |  |
| `escalations` | run | long | measure |  |
| `reused` | run | long | measure |  |
| `not_analysed` | run | long | measure |  |
| `muted` | run | long | measure |  |
| `truncated` | run | boolean | facet |  |
| `finding_index` | finding | long | measure |  |
| `fingerprint` | finding, group | keyword | facet |  |
| `fingerprints` | finding | keyword (array) | facet |  |
| `kind` | finding, delivery_failure | keyword | facet |  |
| `severity` | finding | keyword | facet |  |
| `confidence` | finding | double | measure |  |
| `escalated` | finding | boolean | facet |  |
| `group_trend` | finding | keyword | facet |  |
| `group_count` | finding | long | measure |  |
| `group_previous_count` | finding | long | measure |  |
| `after_release` | finding | boolean | facet |  |
| `title` | finding | text | facet | text, only with `include_text` |
| `evidence` | finding | text | facet | text, only with `include_text` |
| `hypothesis` | finding | text | facet | text, only with `include_text` |
| `next_step` | finding | text | facet | text, only with `include_text` |
| `check` | finding | text | facet | text, only with `include_text` |
| `related` | finding | text (array) | facet | text, only with `include_text` |
| `rank` | group | long | measure |  |
| `level` | group | keyword | facet |  |
| `trend` | group | keyword | facet |  |
| `count` | group, config_warning | long | measure |  |
| `previous_count` | group | long | measure |  |
| `first_seen` | group | date | facet |  |
| `last_seen` | group | date | facet |  |
| `has_finding` | group | boolean | facet |  |
| `watch_rules` | group | keyword (array) | facet |  |
| `error_type` | group | text | facet | text, only with `include_text` |
| `state` | budget, heartbeat | keyword | facet |  |
| `token_ratio` | budget | double | measure |  |
| `threshold_pct` | budget | long | measure |  |
| `triage_only` | budget | boolean | facet |  |
| `last_outcome` | heartbeat | keyword | facet |  |
| `last_ok_at` | heartbeat | date | facet |  |
| `age_seconds` | heartbeat | long | measure |  |
| `stale_after_seconds` | heartbeat | long | measure |  |
| `warnings` | heartbeat | keyword (array) | facet |  |
| `channel` | delivery_failure | keyword | facet |  |
| `status_class` | delivery_failure | keyword | facet |  |
| `http_status` | delivery_failure | long | measure |  |
| `code` | config_warning | keyword | facet |  |
| `key` | config_warning | keyword | facet |  |

## Collecting

- **Alloy / Promtail (Loki):** read the container stderr or the `file` sink's file, keep lines containing `"schema":"watcher.event.v2"`, set a stable `app="watcher"` label. Do not promote `fingerprint`, `run_id`, `event_id` or `title` to labels.
- **Filebeat:** `decode_json_fields` with `target: watcher` and `process_array: false`, and a `timestamp` processor on `watcher.ts` (layout `2006-01-02T15:04:05Z`).
- **Vector:** a `file` or `docker_logs` source, a `remap` transform with `. = parse_json!(.message)`, `.timestamp = parse_timestamp!(.ts, "%+")`.
- **Datadog Agent:** a logs source with `source: watcher`, a date remapper on `ts`, a service remapper on `service` and an attribute remapper `environment` to `env`.
- **Generic HTTP receiver:** point `observe.http` at it; the body is NDJSON with `Content-Type: application/x-ndjson`.

## Example queries

| Question | Kibana (KQL) | Datadog logs |
|---|---|---|
| Failed runs | `watcher.event_type : "run" and watcher.outcome : "failed"` | `@event_type:run @outcome:failed` |
| New critical findings | `watcher.event_type : "finding" and watcher.severity : "critical" and watcher.group_trend : "new"` | `@event_type:finding @severity:critical @group_trend:new` |
| No run in 36 h | log threshold rule on `watcher.event_type : "run"`, alert on no data | monitor: count of `@event_type:run -@outcome:skipped*` below 1 over 36 h, notify on no data |

## Metabase, Superset and other SQL tools

Load the JSONL file (the `file` sink) or an Elasticsearch/Postgres import of the stream. The field table above is the column list. `run` is the fact table; `finding` and `group` join on `run_id`; `budget` and `heartbeat` join on `run_id` too. Finding text exists only for sinks with `include_text`.

## Prometheus

Metrics come from the `prometheus` sink, not from this stream; the catalog is in [observability.md](../observability.md).
