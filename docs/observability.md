# Observability: see what watcher found without the chat

Watcher can emit a small, versioned stream of **events** (`watcher.event.v1`) and a Prometheus **textfile**. You choose where they go; the package never talks to a dashboard product. Grafana is one way to read them, not the only one: the same events work in Kibana/OpenSearch, Datadog, Metabase, Superset or a script of your own.

| Sink | Writes | Typical reader |
|---|---|---|
| `observe.log` | one JSON object per line to stderr (or stdout) | Alloy / Promtail / Vector / Filebeat / Datadog Agent reading container logs |
| `observe.file` | JSON lines in a file (0600, rotated, expiring) | Filebeat, a data team, `grep` / `jq` |
| `observe.http` | NDJSON `POST` to a URL you own (https) | Vector `http_server`, Logstash `http`, any receiver |
| `observe.prometheus` | a `.prom` textfile, replaced atomically | node_exporter textfile collector, Alloy |

Every sink is **off** until you switch it on. With none on, watcher behaves exactly as before. A sink can also be the **only** channel: set the digest driver to `none` and the chat stays silent while everything still reaches your dashboard.

## 1. Dashboard-only in five lines

```php
// config/watcher.php
'notify'  => ['digest' => ['driver' => 'none']],          // driver: none, chat is silent
'observe' => ['log' => ['enabled' => true]],               // events on stderr, your log shipper takes them
```

Environment variables (plain-PHP configs read them through `Watcher\Config\Env`): `WATCHER_DIGEST_DRIVER=none`, `WATCHER_OBSERVE_LOG_ENABLED=1`. `driver: none` needs at least one enabled sink, so a configuration that would be mute and invisible is refused at start.

Everything else is unchanged: the same logs are read, the same models are called, the same budget applies. Only the delivery differs.

## 2. What is in an event (and what never is)

Events carry **counts, outcome codes, severities, timestamps and fingerprints** (12 hex characters of a hash). They never carry log lines, message templates, field values, stack frames or filesystem paths. Free text of a finding (title, evidence, hypothesis, next step, check) exists only when a sink has `include_text` on, after the same scrub the chat message gets; it is **off by default for every sink**, `log` included (stderr is often forwarded by a shipper to someone you have not approved); switching it on is an explicit choice and the text stays redacted. An `http` sink with text on emits a `config_warning` event (`include_text_external`) so a dashboard shows that text leaves the host. There is no setting that turns the scrubbing off.

The contract is the JSON Schema in `docs/schema/watcher.event.v1.schema.json`. Inside `v1` fields are never removed, renamed or retyped; new optional fields may appear, so a consumer ignores fields it does not know. Delivery is at-least-once: de-duplicate on `event_id`.

| Event type | One per | Main fields |
|---|---|---|
| `run` | run | `outcome`, `reason`, `duration_ms`, `events_read`, `groups_total`, `candidates`, `findings_total`, `findings_by_severity`, `calls`, `tokens_in`, `tokens_out`, `cost_usd`, `truncated` |
| `finding` | finding | `fingerprint`, `kind`, `severity`, `confidence`, `group_trend`, `group_count`, `group_previous_count` (+ text with `include_text`) |
| `group` | top group (`observe.top_groups`, default 20) | `rank`, `fingerprint`, `level`, `trend`, `count`, `previous_count`, `has_finding` |
| `budget` | run that read spend | `state`, `spent_today_usd`, `usd_ratio`, `token_ratio`, `triage_only` |
| `heartbeat` | run | `state`, `last_ok_at`, `age_seconds`, `warnings` |
| `delivery_failure` | failed chat delivery | `channel`, `kind`, `status_class`, `http_status` (never the message or URL) |
| `config_warning` | warning | `code` (`analysis_partial`, `event_cap_hit`, `group_cap_hit`, `comparison_gap`, `include_text_external`, ...) |

Every event also has `schema`, `event_type`, `event_id`, `ts`, `service`, `environment` (`unset` when none), `run_id`, `version`, `window_start`, `window_end`.

## 3. Loki: the zero-dependency path

Ship the container's stderr (or the `file` sink's file) to Loki with any collector, labelled with something stable such as `app="watcher"`. Keep `service` and `environment` **inside the JSON**, not as labels you create from `fingerprint`, `run_id` or `event_id` (unbounded cardinality). Then every panel is a `| json` query:

- Runs by outcome: `sum by (outcome) (count_over_time({app="watcher"} | json | event_type="run" [1d]))`
- Failed runs in 24 h: `sum(count_over_time({app="watcher"} | json | event_type="run" | outcome="failed" [24h])) or vector(0)`
- Successful runs in 36 h (alert on `< 1`): `sum by (service) (count_over_time({app="watcher"} | json | event_type="run" | outcome=~"reported|quiet|baselined" [36h]))`
- Findings by severity: `sum by (severity) (count_over_time({app="watcher"} | json | event_type="finding" [1d]))`
- New critical findings: `sum(count_over_time({app="watcher"} | json | event_type="finding" | severity="critical" | group_trend="new" [1d]))`
- Cost per day: `sum(sum_over_time({app="watcher"} | json | event_type="run" | unwrap cost_usd [1d]))`
- Tokens: `sum(sum_over_time({app="watcher"} | json | event_type="run" | unwrap tokens_in [1d]))`
- Budget ratio: `max by (service) (max_over_time({app="watcher"} | json | event_type="budget" | unwrap usd_ratio [1d]))`
- Top groups: `{app="watcher"} | json | event_type="group" | rank <= 10`
- Findings table (needs `include_text` on the sink): `{app="watcher"} | json | event_type="finding" | line_format "{{.severity}} {{.title}} ({{.group_count}}x, {{.group_trend}})"`
- Warnings and failed deliveries: `{app="watcher"} | json | event_type=~"config_warning|delivery_failure"`

Loki's `json` parser flattens nested objects with `_`: `findings_by_severity_critical`, `calls_triage`.

Minimal Alloy / Promtail idea: read the container's stderr, keep lines containing `"schema":"watcher.event.v1"`, set the `app` label, and leave the rest in the line. Vector (`parse_json`), Filebeat (`decode_json_fields`) and the Datadog Agent (a log source with a date remapper on `ts`) do the same; in Elasticsearch parse under a namespace such as `watcher` so `service` does not collide with ECS.

## 4. Prometheus

`observe.prometheus` writes `<dir>/watcher_<service>[_<environment>].prom` (text format 0.0.4) once per run, replaced atomically. Point node_exporter's `--collector.textfile.directory` at the directory (on Kubernetes use a shared volume or a sidecar). The label set is closed (service, environment, and a few enumerations), so the series count is fixed whatever the findings: no fingerprint, title or release is ever a label.

Metrics you will use: `watcher_last_success_timestamp_seconds`, `watcher_last_run_outcome`, `watcher_runs_total`, `watcher_last_run_findings`, `watcher_last_run_new_findings`, `watcher_last_run_cost_usd`, `watcher_budget_ratio`, `watcher_heartbeat_age_seconds`, `watcher_sink_failures_total`, `watcher_delivery_failures_total`.

Alert examples (thresholds are examples; 36 h assumes a daily run):

- No successful run: `time() - watcher_last_success_timestamp_seconds > 129600`
- A run failed: `watcher_last_run_outcome{outcome="failed"} == 1`
- Budget above 90 %: `watcher_budget_ratio > 0.9`
- A new critical finding: `watcher_last_run_new_findings{severity="critical"} > 0`
- Delivery or sink trouble: `increase(watcher_delivery_failures_total[1d]) > 0 or increase(watcher_sink_failures_total[1d]) > 0`

Keep annotations of such alerts free of event text: do not copy finding text into a pager.

## 5. The shipped Grafana dashboard

`docs/dashboards/grafana-watcher.json` is generic on purpose:

- It carries **no datasource uid**. On import Grafana asks for your Loki (`DS_LOKI`) and Prometheus (`DS_PROMETHEUS`) datasources; the two rows work independently, so keep only the one you have.
- The Loki stream is a text variable, `stream` (default `app="watcher"`), so it fits whatever labels your collector sets; `service` and `environment` are regex variables.
- It pins `schemaVersion` 39 and has no dashboard uid or id, so importing it never overwrites another dashboard.

Import it from Dashboards, New, Import. The same panels are also split into `grafana-loki.json` (events only) and `grafana-prometheus.json` (metrics only); alert rules are in `prometheus-rules.yaml` and, for Loki-only setups, `grafana-alerts.yaml`; `mapping.md` maps the fields for Kibana, Datadog and Metabase (see `docs/dashboards/README.md`). Metrics and field names in it are checked against the metric catalog and the event schema by a test, so a rename cannot leave a panel empty unnoticed.

## 6. Sinks that never break the run

A sink failure of any kind (full disk, unreachable endpoint, revoked token, timeout) is caught, counted, logged by class name only, and **never changes the outcome, the exit code or the digest**. It is visible afterwards: `sink_failures` in the JSON summary, a `sink_failed:<sink>` warning in the next `heartbeat` event, `watcher_sink_failures_total`, and `watcher status`. The network sink is bounded by the run deadline: it gets at most what is left of it, and none when it is spent.

Security rules of the sinks:

- `observe.http` accepts `https` only. Plain `http` needs `allow_insecure: true` **and** a loopback, private-network or cluster-local host (`*.svc`, `*.cluster.local`) **and** `allow_private_network: true`. A ClusterIP in CGNAT (100.64.0.0/10) or unique-local IPv6 space cannot be configured even with the flag; use the service's `*.svc` name. The shared host classifier refuses link-local (cloud credentials), CGNAT, metadata, unique-local IPv6 and the reserved ranges in every spelling, whatever is allowed. Redirects are never followed, the response is read only for its status: a declared `Content-Length` over the limit aborts at the headers, and the request timeout (`observe.http.timeout`, at most what the run deadline leaves) bounds anything else; a chunked or compressed body without a declared length is therefore only time-bounded until the streaming cap of `ResponseLimit` lands (tracked, see CHANGELOG), the URL and token never appear in an error, a log line or `watcher check` output, and the URL may not point at the log source. Names are not resolved to check them (a documented limit).
- `observe.file` creates files 0600, refuses a symlink, a hard link, a non-regular file, a group- or world-writable directory (unless sticky) and a file with open permissions; rotation by size or day, `max_files` and `retention_days` bound what is kept.
- `watcher check` verifies each sink locally without a request; `--post-test` sends one `check_test` event to the log, file and http sinks (not to the Prometheus file, which a one-event probe would overwrite). With `--json` a log sink aimed at stdout writes to stderr so the summary stays one line; `--dry-run` prints the events after `== DRY RUN events ==` and writes nothing.

## 7. Other tools

The field table above is the column list for Metabase or Superset reading the JSONL file (`run` is the fact table; `finding` and `group` join on `run_id`). For Kibana the equivalent of the Loki failed-runs query is `watcher.event_type : "run" and watcher.outcome : "failed"`; for Datadog logs `@event_type:run @outcome:failed`.

## 8. The run summary line (zero dependencies)

If you cannot add a sink yet, `watcher run --config=<config> --json` already prints one JSON line on stdout (human text goes to stderr) for every run, failed ones included, with counts and codes only. Ship the job's stdout to Loki with a stable label such as `app="watcher"` and use:

- Runs by outcome: `sum by (outcome) (count_over_time({app="watcher"} | json | __error__="" [1d]))`
- Cost per day: `sum(sum_over_time({app="watcher"} | json | unwrap cost_usd [1d]))`
- Tokens: `sum(sum_over_time({app="watcher"} | json | unwrap tokens_in [1d]))`
- Findings per run: `{app="watcher"} | json | line_format "{{.service}} {{.outcome}} findings={{.findings}} cost={{.cost_usd}}"`

Alert on: no line with outcome in reported, quiet or baselined for 36 h; any line with outcome `failed`. The summary holds only counts, outcome codes and numbers. When a sink is enabled the same line also carries `run_id` and `sink_failures`, so a line can be matched to its events.
