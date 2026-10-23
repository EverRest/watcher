# Configuration reference

One PHP array per service. Plain PHP passes it to `Watcher::fromConfig()` (or returns it from the file given to
`bin/watcher run --config=`); Lumen/Laravel reads `config/watcher.php`. Complete working examples:
[`config/watcher.example.php`](../config/watcher.example.php) (plain PHP, every key with its default),
[`config/watcher.php`](../config/watcher.php) (Laravel/Lumen, reads `env()`), and the minimal
[`docs/examples/smoke.config.php`](examples/smoke.config.php).

The configuration is validated once, before any external call. A problem stops the run with exit code 2 and one line
that names the key (never the value): `invalid config: analysis.prices (no price for models.triage)`. **Unknown
top-level keys are rejected**, so a typo cannot silently disable a setting. Nested keys are not checked for typos.

Secrets and model ids are never defaults in code: they come from the environment (or your secret store).

## Required keys

`service`, `schedule.run_at`, `source.driver` with that driver's credentials, `analysis.api_key`,
`analysis.models.{triage,analyze,escalate}`, `analysis.prices` for each of those models, `state.driver` with its
settings, `notify.digest.webhook`.

## Keys

### `service`, `schedule`

| Key | Default | Notes |
|---|---|---|
| `service` | required | Prefix for every state key. **Changing it starts from scratch** (a new baseline). |
| `schedule.run_at` | required | `HH:MM`. The run is due from `run_at` for one hour, in `schedule.timezone`. |
| `schedule.timezone` | `UTC` | IANA zone. Daily budget and alert days follow this zone. |
| `schedule.window` | `PT24H` | ISO-8601 lookback. The window ends at the local `run_at`. |
| `schedule.lock_ttl` | `900` | Seconds the per-window run lock lives (it is released at the end of every run). |

### `source`

| Key | Default | Notes |
|---|---|---|
| `source.driver` | required | `loki` or `datadog`. |
| `source.loki.url` | required for loki | Loki base URL, or Grafana's data-source proxy `https://<grafana>/api/datasources/proxy/uid/<uid>`. Requests append `/loki/api/v1/...`. |
| `source.loki.auth` | none | `['type' => 'bearer', 'token' => ...]` or `['type' => 'basic', 'user' => ..., 'password' => ...]`. A **read-only** credential. |
| `source.loki.tenant` | null | Sent as `X-Scope-OrgID` for multi-tenant Loki. |
| `source.datadog.site` | `datadoghq.com` | e.g. `datadoghq.eu`; requests go to `https://api.<site>`. |
| `source.datadog.api_key`, `source.datadog.app_key` | required for datadog | The application key needs only the `logs_read_data` scope. |
| `source.stream_tags` | `[]` | Labels that select this service's logs, e.g. `['app' => 'orders-api', 'env' => 'prod']`. Loki selector `{app="..",env=".."}`, Datadog query `app:.. env:..`. |
| `source.read_min_level` | `warning` | Levels below this are only counted in totals (and read in a second pass if their total jumps). |
| `source.anomaly_cap` | `20000` | Event cap of that second pass. |
| `source.sample_size` | `3` | Sample lines kept per group while reducing (in memory only). |
| `source.event_cap` | `200000` | Hard cap on events read per run; the digest says "truncated" when it is hit. |

### Selection and noise control

| Key | Default | Notes |
|---|---|---|
| `selection.min_level` | `error` | Lowest level that makes a group a candidate by itself. |
| `selection.growth_factor` | `3.0` | A group "grew" when its count is at least this many times the previous window's (`> 1`). Also triggers the level-anomaly pass and dependency line reads. |
| `selection.top_n` | `50` | Candidates kept per run. |
| `selection.top_m` | `10` | Groups deep-analysed per run (`<= top_n`). |
| `watch_rules` | `[]` | `name => matcher`. A matching group is a candidate whatever its level. Only the **name** leaves the service. |
| `mute` | `[]` | List of `['fingerprint' => '...']` or a matcher, each with optional `'until' => 'YYYY-MM-DD'` (inclusive, UTC; `null` = forever). Muted groups never reach a model. |

A **matcher** has exactly one of `template` (regex on the masked template), `error_type` (regex), or `field` with exactly
one of `equals` / `matches` (evaluated locally on log fields: field values never leave the service). A group whose message
text is withheld never matches a `template` matcher. Regexes need PCRE delimiters.

### `analysis` (models, budget)

| Key | Default | Notes |
|---|---|---|
| `analysis.provider` | `anthropic` | The only one. |
| `analysis.api_key` | required | |
| `analysis.models.{triage,analyze,escalate}` | required | One id per tier. Cheapest model for triage, strongest for escalation. |
| `analysis.prices` | required | `model id => [input, output, cache_read, cache_write]`, **USD per million tokens**, for every model above. Drives the cost line, the daily cap and the alerts: keep it current. |
| `analysis.max_input_tokens` | triage 20000, analyze 30000, escalate 15000 | Per-step input budget; lowest-priority candidates are dropped first, with a note in the digest. |
| `analysis.max_output_tokens` | triage 1500, analyze 4000, escalate 2000 | |
| `analysis.confidence_threshold` | `0.6` | Findings of severity error or higher below this are re-analysed once by the strong model; warn/info below it collapse into the "minor" line. `0 < x < 1`. |
| `analysis.escalation_cap` | `3` | Escalations per run. |
| `analysis.daily_budget_usd` | `0.50` | Hard daily cap per service. When spent, runs do triage only and send one notice a day. |
| `analysis.daily_token_limit` | null | Optional daily cap on tokens (input + output + cache). |
| `analysis.budget_alert_percents` | `[25, 50, 75, 90]` | One notice per threshold and counter per day (the highest newly crossed one). Integers 1-99, ascending. |

### `redaction`

| Key | Default | Notes |
|---|---|---|
| `redaction.min_group_events_for_text` | `3` | A group with fewer events in its cluster sends **no message text** to the model (only level, error type, frames, counts). Raise it for stricter privacy, lower it never below what your DPO accepts. |
| `redaction.extra_patterns` | `[]` | `NAME => regex`. Matches are replaced with `<NAME>` in everything that leaves the service. `NAME` may not be `N`, `EMAIL`, `URL`, `HOST`, `IP`, `ID`, `TOKEN`, `PAN` or `STR` (reserved). |

### `state`

| Key | Default | Notes |
|---|---|---|
| `state.driver` | required | `redis`, `pdo` or `file`. **`file` is valid for a single runner only.** |
| `state.retention` | `2592000` | Seconds every stored item lives (30 days). Fingerprints, counters, verdicts and findings; never raw logs. |
| `state.redis.{host,port,password,database}` | `127.0.0.1`, `6379`, null, `0` | Or inject a connected `\Redis` as `state.redis.instance` (it wins). Every key carries a TTL. |
| `state.pdo.{dsn,username,password,table}` | dsn required; table `watcher_state` | MySQL, PostgreSQL or SQLite. The table is **never created implicitly**: create it with `Watcher\State\PdoStateStore::createTableSql('mysql'\|'pgsql'\|'sqlite')`. |
| `state.file.path` | required for file | Writable directory; created (mode 0700) on first use. File names are hashes of the keys. |

### `notify`

| Key | Default | Notes |
|---|---|---|
| `notify.digest.webhook` | required | Slack incoming webhook for the digest. |
| `notify.notice` | null | Separate destination (same shape) for failures, budget notices and baseline messages; null uses the digest destination. |

### Context (all optional)

| Key | Default | Notes |
|---|---|---|
| `service_context` | `''` | Up to 1000 characters: what the service does and what is critical. Sent to the model on every request after the redaction scan. Never put personal data in it. |
| `dependencies` | `[]` | `name => ['stream_tags' => [...]]`. Their error totals are read each run; lines only when a candidate references the host or the dependency's errors grew. A per-dependency `source` override is not available yet (rejected). |
| `dependency_read_cap` | `5000` | Events read per triggered dependency. |
| `dependency_domains` | `['svc.cluster.local']` | Hosts in log text are kept (as `<URL:host>`) only if single-label, a declared dependency, or ending in one of these; every other host is masked. |
| `deploy.release`, `deploy.commit` | null | Usually from CI. A new group after a release change is marked "first seen after <release>". |
| `deploy.version_label` | null | Log label that carries the running version, if your source has one (gives the deploy moment). |
| `links.grafana.{base_url,datasource_uid}` / `links.datadog.app_url` | none | Builds a log-query link per finding from redacted data only. |
| `repo.{provider,web_url,path_map}` | none | `provider` is `gitlab` or `github`; `path_map` maps container path prefixes to repository paths for stack-frame links (needs `deploy.commit`). Vendor paths get no link. |

## Environment variables used by the shipped configs

`config/watcher.php` (Laravel/Lumen) reads these through `env()`. `config/watcher.example.php` reads the subset marked
with an asterisk through `getenv()`; the rest are set in the array.

| Variable | Config key |
|---|---|
| `WATCHER_SERVICE` | `service` |
| `WATCHER_RUN_AT`, `WATCHER_TIMEZONE` | `schedule.run_at`, `schedule.timezone` |
| `WATCHER_SOURCE` | `source.driver` |
| `WATCHER_LOKI_URL*`, `WATCHER_LOKI_TOKEN*`, `WATCHER_LOKI_TENANT` | `source.loki.*` |
| `WATCHER_DD_SITE`, `WATCHER_DD_API_KEY*`, `WATCHER_DD_APP_KEY*` | `source.datadog.*` |
| `WATCHER_ANTHROPIC_KEY*` | `analysis.api_key` |
| `WATCHER_MODEL_TRIAGE*`, `WATCHER_MODEL_ANALYZE*`, `WATCHER_MODEL_ESCALATE*` | `analysis.models.*` |
| `WATCHER_PRICE_{TRIAGE,ANALYZE,ESCALATE}_{IN,OUT}*` | `analysis.prices` (example config only) |
| `WATCHER_STATE`, `WATCHER_STATE_PATH` | `state.driver`, `state.file.path` |
| `WATCHER_REDIS_{HOST,PORT,PASSWORD,DATABASE}` | `state.redis.*` |
| `WATCHER_DB_{DSN*,USERNAME,PASSWORD}` | `state.pdo.*` |
| `WATCHER_SLACK_WEBHOOK*` | `notify.digest.webhook` |
| `WATCHER_SERVICE_CONTEXT` | `service_context` |
| `WATCHER_RELEASE*`, `WATCHER_COMMIT*` | `deploy.release`, `deploy.commit` |
| `WATCHER_GRAFANA_URL`, `WATCHER_LOKI_DS_UID` | `links.grafana.*` |
| `WATCHER_REPO_URL` | `repo.web_url` |

`config/watcher.php` ships `'prices' => []` and has no price variables: it fails validation (`analysis.prices (no price for models.triage)`) until you fill the array in.
