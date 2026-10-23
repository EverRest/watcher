# Configuration reference

One PHP array per service. Plain PHP passes it to `Watcher::fromConfig()` (or returns it from the file given to
`bin/watcher run --config=`); Lumen/Laravel reads `config/watcher.php`. Complete working examples:
[`config/watcher.example.php`](../config/watcher.example.php) (plain PHP, every key with its default),
[`config/watcher.php`](../config/watcher.php) (Laravel/Lumen, reads `env()`), and the minimal
[`docs/examples/smoke.config.php`](examples/smoke.config.php).

The configuration is validated once, before any external call. A problem stops the run with exit code 2 and one line
that names the key (never the value): `invalid config: analysis.prices (no price for models.triage)`. **Unknown
keys are rejected at every level** (`unknown configuration key: selection.min_levl`; the message names the key path, never a value), so a typo cannot silently disable a setting. Free-form maps (`source.stream_tags`, `dependencies.<name>.stream_tags`, `analysis.prices`, `redaction.extra_patterns`, `watch_rules`, `path_map`) accept any names.

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
| `schedule.lock_ttl` | `900` | Seconds the per-window run lock lives (it is released at the end of every run). It is **not renewed** during a run (the state contract has no renew). To keep a second run from starting mid-analysis, one model call (two sequential attempts, SDK retries off) is bounded to `lock_ttl / 4`: the HTTP timeout is `lock_ttl / 8` seconds, within 10..180. A run makes at most `2 + escalation_cap` model calls. |

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
| `source.level_mode` | `text` | How `read_min_level` is pushed to the backend. **Loki:** `text` = LogQL line filter on level words (misses lines whose level lives only in the stream label); `label` = stream matcher on the `level` label, no line filter (with watch rules present it falls back to the unrestricted selector, because LogQL cannot OR a stream matcher with a line filter); `all` = no level restriction. **Datadog:** `all` = no `status:` filter, anything else = status filter. |
| `source.datadog.newest_first` | `false` | `true` sorts `-timestamp`, so an `event_cap` truncation keeps the newest events instead of the oldest. |
| `source.group_cap` | `5000` | Hard bound on distinct problem groups held in memory per run. Past it, events with a new message are grouped as one "other" group per level and the digest says "cluster cap hit". Events matching a watch rule keep their own group up to 2 x this value. |
| `source.event_cap` | `200000` | Hard cap on events read per run; the digest says "truncated" when it is hit. |

### Selection and noise control

| Key | Default | Notes |
|---|---|---|
| `selection.min_level` | `error` | Lowest level that makes a group a candidate by itself. |
| `selection.growth_factor` | `3.0` | A group "grew" when its count is at least this many times the previous window's (`> 1`). Also triggers the level-anomaly pass and dependency line reads. |
| `selection.top_n` | `50` | Candidates kept per run. |
| `selection.top_m` | `10` | Groups deep-analysed per run (`<= top_n`). |
| `watch_rules` | `[]` | `name => matcher`. A matching group is a candidate whatever its level. Only the **name** leaves the service. Plain-literal `template` rules and RE2-safe `error_type` rules are also sent to the backend as search filters so matching lines below `read_min_level` are read; other rules are matched only on lines that were read. |
| `mute` | `[]` | List of `['fingerprint' => '...']` or a matcher, each with optional `'until' => 'YYYY-MM-DD'` (inclusive, UTC; `null` = forever). Muted groups never reach a model. A `field` mute hides a group only when **every** event of the group matches; if some do not, the group stays and the digest says `N of M events match mute #i` (counts only). |

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
| `analysis.max_input_tokens` | triage 20000, analyze 30000, escalate 15000 | Per-step input budget; lowest-priority candidates are dropped first, with a note in the digest. The estimate (UTF-8 bytes / 3) includes the step's system prompt and the `service_context`, not only the groups. Enforced for `triage` (candidates), `analyze` (dependency context shrinks first) and `escalate`: an escalation sends the one finding's groups plus the dependency context that refers to them; over the limit, dependency context is trimmed first, then the groups' stack frames, and if it still does not fit the escalation is skipped (the first-pass finding is reported as it is; the digest says `N escalation(s) skipped`). |
| `analysis.max_output_tokens` | triage 1500, analyze 4000, escalate 2000 | A floor, not a ceiling: triage uses `max(configured, 30 * groups + 500)` because the verdict list grows with the groups. |
| `analysis.confidence_threshold` | `0.6` | Findings of severity error or higher below this are re-analysed once by the strong model; warn/info below it collapse into the "minor" line. `0 < x < 1`. |
| `analysis.escalation_cap` | `3` | Escalations per run. |
| `analysis.daily_budget_usd` | `0.50` | Hard daily cap per service (`>= 0`). Before every billable step (triage, analysis, each escalation) the run reserves its worst case: planned input tokens (system prompt + context + payload) at the input price, plus `max_output_tokens` at the output price (the larger of the cache-write and plain input price is used). The step runs only if today's recorded spend plus that fits under the cap; otherwise it is skipped and the digest says how many groups were `not triaged` / `not analysed` / escalations skipped. One attempt is reserved: the retry after invalid model output is not, so a run can pass the cap by at most that retry. A budget already spent when the run starts is the **triage-only day** (unchanged): triage runs without a reservation, no analysis, one notice a day. |
| `analysis.daily_token_limit` | null | Optional daily cap on tokens (input + output + cache), reserved the same way (planned input + `max_output_tokens`). |
| `analysis.budget_alert_percents` | `[25, 50, 75, 90]` | One notice per threshold and counter per day (the highest newly crossed one). Integers 1-99, ascending. |

### `redaction`

| Key | Default | Notes |
|---|---|---|
| `redaction.min_group_events_for_text` | `3` | A group with fewer events in its cluster sends **no message text** to the model (only level, error type, frames, counts). Raise it for stricter privacy, lower it never below what your DPO accepts. |
| `redaction.extra_patterns` | `[]` | `NAME => regex`. Matches are replaced with `<NAME>` in everything that leaves the service. `NAME` may not be `N`, `EMAIL`, `URL`, `HOST`, `IP`, `ID`, `TOKEN`, `PAN`, `STR`, `PATH` or `USER` (reserved). |

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

### Command-line flags (not config keys)

`bin/watcher run --config=<file> [--now=<ISO-8601>] [--dry-run [--estimate]] [--json]`, `bin/watcher check --config=<file>
[--post-test] [--json]`, `bin/watcher status --config=<file> [--json]`. `--estimate` requires `--dry-run`. See
[operations](operations.md) for what each prints and its exit codes.

### `heartbeat` (optional)

| Key | Default | Notes |
|---|---|---|
| `heartbeat.stale_after` | 1.5 x `schedule.window` | ISO-8601 duration (> 0). `watcher status` reports `stale` and exits non-zero when the last reported/quiet/baselined run is older than this. |
| `heartbeat.weekly_summary` | `false` | `true` sends one line per ISO week (service timezone) to the notice destination, from the first due run of the next week: counts of reported, quiet, baselined, failed and skipped runs, and the week's cost. Nothing is sent for a week with no run. |

### Context (all optional)

| Key | Default | Notes |
|---|---|---|
| `service_context` | `''` | Up to 1000 characters: what the service does and what is critical. Sent to the model on every request after the redaction scan. Never put personal data in it. |
| `dependencies` | `[]` | `name => ['stream_tags' => [...]]`. Their error totals are read each run; lines only when a candidate references the host or the dependency's errors grew. A per-dependency `source` override is not available yet (rejected). |
| `dependency_read_cap` | `5000` | Events read per triggered dependency. |
| `dependency_domains` | `['svc.cluster.local']` | A host in log text becomes `<URL:name>`/`<HOST:name>` (the declared name only, never a suffix) if it equals a declared dependency name or is `name.<d>` / `name.<one label>.<d>` for one of these domains; every other host is masked as `<URL>`/`<HOST>`. The digest only counts such call targets, it never names them. **`[]` turns every dependency host that is not exactly a declared name into `<URL>`/`<HOST>`.** A host is kept only if a dependency is declared for it, so declare the name in `dependencies` as well as the domain here. |
| `deploy.release`, `deploy.commit` | null | Usually from CI. A new group after a release change is marked "first seen after <release>". |
| `deploy.version_label` | null | Log label that carries the running version, if your source has one (gives the deploy moment). |
| `links.grafana.{base_url,datasource_uid}` / `links.datadog.app_url` | none | Builds a log-query link per finding from redacted data only. |
| `repo.{provider,web_url,path_map}` | none | `provider` is `gitlab` or `github`; `path_map` maps container path prefixes to repository paths for stack-frame links (needs `deploy.commit`). Vendor paths get no link. |

## Digest notices (counts only, never raw content)

Besides findings, the digest carries short notices: `truncated: N events read` (`event_cap`), `truncated: N groups dropped (budget)`
(`max_input_tokens`), `cluster cap hit` (`group_cap`), `N group(s) flagged interesting were not analysed` (triage found them but the
daily budget was spent or a step's worst case did not fit it, or `top_m` cut them), `N group(s) not triaged: the daily budget would have been exceeded`, `N escalation(s) skipped (daily budget or max_input_tokens.escalate)`, `compared with the run of <date>, N days ago` (the previous window had no snapshot; see `docs/operations.md`), `N pattern evaluation failures: rules may have been skipped` (a watch rule, mute or
backend-filter regex failed to evaluate, e.g. hit the PCRE backtrack limit; it fails open), `N call target(s) not declared as
dependencies` and `dependency unavailable: name`. A day with such a notice is sent even when there are no findings, so a missed
analysis never looks like a quiet day.

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
