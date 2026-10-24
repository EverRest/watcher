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

The master switch `enabled` (`WATCHER_ENABLED`) defaults to **false**: set it to true (per environment) or `run` reports `disabled` and does nothing. See [Feature flags](#feature-flags-spec-012).

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
| `schedule.lock_ttl` | `900` | Seconds the per-window run lock lives (released at the end of every run). With a store that supports renewal (all three) the lock is **extended** between pipeline steps and before each model call whenever less than half of it is left, so a long run keeps it. One model call (two sequential attempts, SDK retries off) is still bounded to `lock_ttl / 4`: HTTP timeout `lock_ttl / 8` s, within 10..180. A run makes at most `2 + escalation_cap` model calls. |
| `schedule.run_deadline` (`WATCHER_RUN_DEADLINE`) | `lock_ttl` minus `max(30, lock_ttl / 10)` s (810 by default; half of `lock_ttl` when that is 60 or less) | Total wall-clock budget of one run, checked while reading and between pipeline steps. At expiry reading stops (read marked truncated, reason `deadline`), no model call starts, the window is snapshotted and recorded quiet with reason `truncated:deadline`, and a short notice is sent. Must not exceed `lock_ttl`. In a fleet it is further limited by `service_time_budget`.

### `source`

| Key | Default | Notes |
|---|---|---|
| `source.driver` | required | `loki` or `datadog`. |
| `source.loki.url` | required for loki | Loki base URL, or Grafana's data-source proxy `https://<grafana>/api/datasources/proxy/uid/<uid>`. Requests append `/loki/api/v1/...`. |
| `source.loki.auth` | none | `['type' => 'bearer', 'token' => ...]` or `['type' => 'basic', 'user' => ..., 'password' => ...]`. A **read-only** credential. |
| `source.loki.tenant` | null | Sent as `X-Scope-OrgID` for multi-tenant Loki. |
| `source.loki.allow_insecure` | `false` | The Loki URL must be `https` with a host and no credentials in the URL. `true` additionally allows plain `http` for a **loopback** host only (`localhost`, `127.x.x.x`, `::1`). Redirects are never followed; TLS verification is always on. |
| `source.datadog.site` | `datadoghq.com` | e.g. `datadoghq.eu`; requests go to `https://api.<site>`. |
| `source.datadog.api_key`, `source.datadog.app_key` | required for datadog | The application key needs only the `logs_read_data` scope. |
| `source.stream_tags` | `[]` | Labels that select this service's logs, e.g. `['app' => 'orders-api', 'env' => 'prod']`. Loki selector `{app="..",env=".."}`, Datadog query `app:.. env:..`. |
| `source.read_min_level` | `warning` | Levels below this are only counted in totals (and read in a second pass if their total jumps). |
| `source.anomaly_cap` | `20000` | Event cap of that second pass. |
| `source.level_mode` | `text` | How `read_min_level` is pushed to the backend. **Loki:** `text` = LogQL line filter on level words (misses lines whose level lives only in the stream label); `label` = stream matcher on the `level` label, no line filter (with watch rules present it falls back to the unrestricted selector, because LogQL cannot OR a stream matcher with a line filter); `all` = no level restriction. **Datadog:** `all` = no `status:` filter, anything else = status filter. |
| `source.datadog.newest_first` | `false` | `true` sorts `-timestamp`, so an `event_cap` truncation keeps the newest events instead of the oldest. |
| `source.group_cap` | `5000` | Hard bound on distinct problem groups held in memory per run. Past it, events with a new message are grouped as one "other" group per level and the digest says "cluster cap hit". Events matching a watch rule keep their own group up to 2 x this value. |
| `source.max_response_mb` | `16` | Largest backend reply accepted, 1 to 64 MB. A page over it is retried at half the page size instead of failing the source. |
| `source.event_cap` | `200000` | Hard cap on events read per run; the digest says "truncated" when it is hit, and a quiet run that hit it sends a notice once per window. Reading is oldest first: the cap keeps the oldest events (set `source.datadog.newest_first` for the newest). A read also stops, with its reason in the run record, at the run deadline, at 80% of PHP `memory_limit` (reason `memory`), after 1000 Loki pages, after 300 s of Loki paging, or when Loki's cursor stops advancing (`cursor`). Physical lines over 64 KB never reach the parser; they are counted. A reply over the response cap (`source.max_response_mb`, default 16, max 64) is not fatal: the same cursor is re-read with half the page size (down to 100 lines) and the digest read-quality line counts the re-reads; only a 100-line page over the cap fails (`response too large`), as does a declared `Content-Length` over the cap. JSON nested deeper than 64 levels is refused. Service and environment names must not contain `@`. |

### Selection and noise control

| Key | Default | Notes |
|---|---|---|
| `selection.min_level` | `error` | Lowest level that makes a group a candidate by itself. |
| `selection.growth_factor` | `3.0` | A group "grew" when its count is at least this many times the previous window's (`> 1`). Also triggers the level-anomaly pass and dependency line reads. |
| `selection.top_n` | `50` | Candidates kept per run. |
| `selection.top_m` | `10` | Groups deep-analysed per run (`<= top_n`). They are chosen by watch rule, level, growth, then novelty; new groups take at most half of the slots while older ones wait (fixed, not configurable; the digest counts the overflow). Each template sent to the model is cut to 300 characters (`CompactPayload::MAX_TEMPLATE_CHARS`, a constant, not a config key). |
| `watch_rules` | `[]` | `name => matcher`. A matching group is a candidate whatever its level. Only the **name** leaves the service. Plain-literal `template` rules and RE2-safe `error_type` rules are also sent to Loki as search filters; Datadog gets only patterns that are a single plain word (`\w+`), and one pattern it cannot express switches its server-side filter off (the client still applies it). Either way matching lines below `read_min_level` are read; other rules are matched only on lines that were read. |
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
| `analysis.max_output_tokens` | triage 1500, analyze 4000, escalate 2000 | A floor, not a ceiling: triage uses `max(configured, 30 * groups + 500)` because the verdict list grows with the groups; analyze and escalate use `max(configured, 450 * groups + 300)` while `analysis.advice.enabled` is on (a finding with `how_to_check` and `related_checks` is about 450 output tokens at its text limits, so a full `top_m` of long findings is not cut off and retried). The daily-budget reservation uses the same figure. With advice off the configured value is used as it is. |
| `analysis.confidence_threshold` | `0.6` | Findings of severity error or higher below this are re-analysed once by the strong model; warn/info below it collapse into the "minor" line. `0 < x < 1`. |
| `analysis.escalation_cap` | `3` | Escalations per run. A finding is escalated only when one of its groups is at `error` or above in the logs, the finding itself is `error`/`critical`, and its confidence is below `analysis.confidence_threshold`. |
| `analysis.daily_budget_usd` | `0.50` | Hard daily cap per service (`>= 0`). Before every billable step (triage, analysis, each escalation) the run reserves its worst case: planned input tokens (system prompt + context + payload) at the input price, plus `max_output_tokens` at the output price (the larger of the cache-write and plain input price is used). The step runs only if today's recorded spend plus that fits under the cap; otherwise it is skipped and the digest says how many groups were `not triaged` / `not analysed` / escalations skipped. Both possible attempts of a step are reserved: twice the input tokens, and the output cap plus the cap of the retry (a reply cut off by `max_tokens` is retried with the cap doubled, at most 8192), so a step that might need its retry only starts when the retry fits too. The user message adds a data wrapper (about 70 tokens) that is part of the estimate. A budget already spent when the run starts is the **triage-only day** (unchanged): triage runs without a reservation, no analysis, one notice a day. |
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
| `state.redis.read_timeout` | `5` | Seconds a Redis call waits for a reply (the PHP socket default is 60). |
| `state.redis.{host,port,username,password,database}` | `127.0.0.1`, `6379`, null, null, `0` | `username` + `password` authenticate as a Redis 6 ACL user. A `tls://host` value of `host` connects over TLS (phpredis); plain `AUTH` is otherwise sent in clear. Or inject a connected `\Redis` as `state.redis.instance` (it wins). Every key carries a TTL. |
| `state.pdo.{dsn,username,password,table}` | dsn required; table `watcher_state` | MySQL, PostgreSQL or SQLite. The table is **never created implicitly**: create it with `Watcher\State\PdoStateStore::createTableSql('mysql'\|'pgsql'\|'sqlite')`. |
| `state.file.path` | required for file | Writable directory; the package creates it with mode 0700 when it is missing, an existing directory keeps its mode, so keep it private to the service user. File names are hashes of the keys. |

### `notify`

| Key | Default | Notes |
|---|---|---|
| `notify.digest.driver` | `slack` | `slack`, `telegram` or `discord`. |
| `notify.digest.webhook` | required for slack, discord | Slack incoming webhook, or `https://discord.com/api/webhooks/<id>/<token>` (`discordapp.com` also accepted). |
| `notify.digest.allow_insecure` | `false` | Slack only (same for `notify.notice`): the webhook must be `https` with a host and no credentials; `true` allows plain `http` for a loopback host only. Redirects are never followed. |
| `notify.digest.bot_token` | required for telegram | Bot API token (`123456:ABC...`). Read it from the environment. |
| `notify.digest.chat_id` | required for telegram | Integer or `@channel` name; a supergroup id is negative. |
| `notify.digest.thread_id` | none | Telegram forum topic id (positive integer). Discord has no equivalent. |
| `notify.notice` | null | Separate destination (same shape, any driver) for failures, budget notices and baseline messages; null uses the digest destination. |

A key of another driver (`webhook` on a Telegram entry) is an error that names the key path, never the value. In a fleet
file, a service's `notify.digest` or `notify.notice` **replaces** the fleet default as a whole (it is not merged key by
key), and `digest: combined` needs a fleet `notify.digest` of any driver.

### Command-line flags (not config keys)

`bin/watcher run --config=<file> [--now=<ISO-8601>] [--dry-run [--estimate]] [--json]`, `bin/watcher check --config=<file>
[--post-test] [--json]`, `bin/watcher status --config=<file> [--json]`. `--estimate` requires `--dry-run`. See
[operations](operations.md) for what each prints and its exit codes.

### Fleet configuration

A fleet file returns an array (or a directory: `_fleet.php` with the fleet keys, plus one `*.php` per service, loaded in
name order). Each service is a complete single-service config, validated by the same rules, plus two fleet keys.
Validation covers the whole fleet before any call; the first error names the entry and key
(`services[1] (bff-a): selection.growth_factor must be greater than 1`). Example: [examples/watcher-fleet.php](examples/watcher-fleet.php).

| Key | Default | Notes |
|---|---|---|
| `fleet` | required | Name (`A-Za-z0-9._-`); part of the shared keys and messages. |
| `services[]` | required | Entries. `priority` (int, default 0, higher first) and `provides` (names other services use for this one, default `[service]`) are fleet-only. Order: priority, then name, then dependencies first. Names must be unique and must not contain `/`. |
| `defaults` | `[]` | Any single-service key, merged under every entry. `state` and `analysis.api_key` must end up identical in all entries. |
| `digest` | `per_service` | `per_service` posts each digest to the entry's `notify.digest`; `combined` posts one message to the fleet `notify.digest`. |
| `notify` | `[]` | Fleet default destinations, merged under each entry's; also where the combined digest, the correlated finding and fleet budget notices go. |
| `budget.daily_budget_usd`, `budget.daily_token_limit`, `budget.alert_percents` | none, none, `[25,50,75,90]` | Fleet-wide caps on top of each service's own; the day is in the fleet `timezone`. |
| `timezone` | `UTC` | Day boundary of the fleet counters. |
| `time_budget` | none | ISO-8601 duration; services not started in time are `skipped_time` (exit 1). |
| `service_time_budget` | `time_budget` / number of services | ISO-8601 duration: wall-clock share of one service, enforced through its run deadline, so one slow or hostile service cannot starve the rest. |
| `correlation.enabled`, `correlation.tolerance` | `true`, `PT0S` | Tolerance widens the time-overlap test. |

Commands: `bin/watcher fleet:run --config=<file|dir> [--now=<ISO-8601>] [--only=<a>[,<b>]] [--dry-run [--estimate]] [--json]`,
`fleet:check --config=<file|dir> [--post-test] [--json]`, `fleet:status --config=<file|dir> [--json]`. Laravel/Lumen:
`watcher:fleet` with the same options, reading `watcher.fleet`.

### `heartbeat` (optional)

| Key | Default | Notes |
|---|---|---|
| `heartbeat.stale_after` | 1.5 x `schedule.window` | ISO-8601 duration (> 0). `watcher status` reports `stale` and exits non-zero when the last reported/quiet/baselined run is older than this. |
| `heartbeat.weekly_summary` | `false` | `true` sends one line per ISO week (service timezone) to the notice destination, from the first due run of the next week: counts of reported, quiet, baselined, failed and skipped runs, and the week's cost. Nothing is sent for a week with no run. |

### `baseline` and `grouping` (optional)

| Key | Default | Notes |
|---|---|---|
| `baseline.mode` | `previous` | `previous`, `rolling` (mean of the last N windows) or `weekday` (median of the same weekday). `previous` is the old behaviour. Message: `baseline.mode must be one of previous, rolling, weekday`. |
| `baseline.windows` | 7 (rolling) / 4 (weekday) | Integer >= 2 (rolling) or >= 3 (weekday). Ignored for `previous`. |
| `baseline.max_age` | `P7D` | ISO-8601. Oldest snapshot a missing previous window may fall back to; must not exceed `state.retention`. |
| `baseline.exclude` | `[]` | Dates `YYYY-MM-DD` (local date of a window end) left out of rolling/weekday values. |
| `baseline.since` | `null` | `YYYY-MM-DD`: ignore windows that ended before this date (history reset). |
| `grouping.similarity` | `0.5` | `0 < x <= 1`: share of literal words that must match a cluster leader. |
| `grouping.memory_budget_mb` | `32` | Integer >= 1: estimated memory the template miner may hold. Each stored message keeps at most 64 tokens (the rest is one `<TRUNC>` placeholder). Past the budget new messages are dropped like past `message_cap` (their groups show no text, `Grouping limit reached`). The clustering budget counts token operations. |
| `grouping.message_cap` | `50000` | Integer >= 1000: distinct masked messages kept per run. Beyond it the message is counted, not clustered, and its group shows no text. |
| `grouping.backend_patterns` | `false` | Reserved (deferred); `true` is rejected. |

`rolling` and `weekday` need a whole-day `schedule.window` and a `state.retention` of at least `windows x span + 1 day`
(span: the window for rolling, 7 days for weekday). Grouping always uses similarity; there is no on/off switch.

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
(`max_input_tokens`), `cluster cap hit` (`group_cap`), `Grouping limit reached: N messages were not clustered` (`grouping.message_cap`), `Grouping time budget reached` (comparison budget spent; counts only), `N group(s) flagged interesting were not analysed` (triage found them but the
daily budget was spent or a step's worst case did not fit it, or `top_m` cut them), `N group(s) not triaged: the daily budget would have been exceeded`, `N escalation(s) skipped (daily budget or max_input_tokens.escalate)`, `compared with the run of <date>, N days ago` (the previous window had no snapshot; see `docs/operations.md`), `N pattern evaluation failures: rules may have been skipped` (a watch rule, mute or
backend-filter regex failed to evaluate, e.g. hit the PCRE backtrack limit; it fails open), `N call target(s) not declared as
dependencies` and `dependency unavailable: name`, and the model-distrust notices: `triage marked N error-or-higher or watch-rule group(s) as noise, known or left them out; they were analysed anyway`, `N error-or-higher or watch-rule group(s) were analysed but the model reported nothing for them; they are analysed again next window`, `N new group(s) not analysed: new groups are capped at half of top_m per run`, `N lower-level group(s) dropped by triage as noise or known`, `N group(s) reused from earlier verdicts (not analysed again)` (see `docs/privacy.md`). A day with such a notice is sent even when there are no findings, so a missed
analysis never looks like a quiet day.

## Feature flags (spec 012)

Every feature can be switched on or off in the config array and by an environment variable, so one package serves
int, prod and dev. **Only the master `enabled` defaults to off**; every other flag defaults to on, which is exactly the
behaviour before flags existed. A switched-off feature does nothing: no read, no write, no model call, no notice, no
cost. Flags must be real booleans in an array config (the string `'false'` is rejected: `heartbeat.enabled must be true
or false`). In a plain-PHP config read the variables with `Watcher\Config\Env` (`bool`, `int`, `string`, `float`,
`list`; unset or empty = the default; true/false/1/0/yes/no/on/off in any case; an invalid value throws `InvalidConfig:
environment variable NAME must be true or false`, naming the variable and never its value). The shipped configs
(`config/watcher.php`, `config/watcher.example.php`, `docs/examples/*.php`) already do this.

| Flag | Config key | Environment variable | Default | When false |
|---|---|---|---|---|
| master switch | `enabled` | `WATCHER_ENABLED` | **false** | `run` returns outcome `disabled` (exit 0, `watcher: disabled (set enabled=true / WATCHER_ENABLED=1)` on stderr); no source, state, model or notifier access, not even a heartbeat. `status` reports `disabled` without reading the store; `check` still validates the config and skips the rest as `disabled` |
| environment name | `environment` (optional string) | `WATCHER_ENVIRONMENT` | unset | not a flag: state keys become `<service>@<environment>`; shown in the digest header and the JSON summary (`environment`) |
| heartbeat | `heartbeat.enabled` | `WATCHER_HEARTBEAT_ENABLED` | true | no liveness record, trigger record or weekly counter is written; `status` reports `disabled` |
| weekly summary | `heartbeat.weekly_summary` | `WATCHER_HEARTBEAT_WEEKLY_SUMMARY` | false (already opt-in) | no weekly line |
| file purge | `purge.enabled` | `WATCHER_PURGE_ENABLED` | true | expired files of the file state store are not purged |
| dependencies | `dependencies.enabled` | `WATCHER_DEPENDENCIES_ENABLED` | true | declared dependencies are not read, not named in redaction, nothing is stored for them |
| deploy info | `deploy.enabled` | `WATCHER_DEPLOY_ENABLED` | true | no release/commit line, no version query to the source |
| log links | `links.logs.enabled` | `WATCHER_LINKS_LOGS_ENABLED` | true | no log-search link per finding |
| code links | `links.code.enabled` | `WATCHER_LINKS_CODE_ENABLED` | true | no code link per stack frame |
| service context | `service_context.enabled` (with `service_context.text`; a plain string still works) | `WATCHER_SERVICE_CONTEXT_ENABLED` | true | the context is not scanned and not sent to the model |
| watch rules | `watch_rules.enabled` | `WATCHER_WATCH_RULES_ENABLED` | true | no rule is evaluated (rules are still validated) |
| mute | `mute.enabled` | `WATCHER_MUTE_ENABLED` | true | nothing is muted (entries are still validated) |
| level anomaly | `level_anomaly.enabled` | `WATCHER_LEVEL_ANOMALY_ENABLED` | true | no level totals are read, no second pass; snapshots omit `totals`, and a missing `totals` means "unknown", never "appeared" |
| escalation | `selection.escalation.enabled` | `WATCHER_ESCALATION_ENABLED` | true | no stronger-model call: triage and analysis only |
| triage | `analysis.triage.enabled` | `WATCHER_TRIAGE_ENABLED` | true | no triage call and no triage checkpoint; the selected candidates go straight to analysis (`top_m`, budget and reservation still apply) |
| advice | `analysis.advice.enabled` | `WATCHER_ADVICE_ENABLED` | true | the analysis prompt and output schema leave out `how_to_check` and `related_checks`: no `Check:` / `Related:` lines in the digest, about 100 fewer output tokens per finding, and the prompt, schema, caps and digest are exactly those of a version without the feature |
| baseline | `baseline.enabled` | `WATCHER_BASELINE_ENABLED` | true | previous-window comparison only, whatever `baseline.mode` says |
| grouping | `grouping.enabled` | `WATCHER_GROUPING_ENABLED` | true | the old exact token-count clusters instead of similarity clusters (fingerprints are unchanged) |
| budget alerts | `budget_alerts.enabled` | `WATCHER_BUDGET_ALERTS_ENABLED` | true | no 25/50/75/90 % notice and no "budget exhausted" notice (the caps themselves still apply) |
| notices | `notify.notice.enabled` | `WATCHER_NOTICE_ENABLED` | true | no failure, baseline, budget or weekly notice is posted; failures still go to the host log and the exit code |
| digest | `notify.digest.enabled` | `WATCHER_DIGEST_ENABLED` | true | no digest is posted, so no model is paid for: the window is read and snapshotted and the run ends `quiet` (reason `digest_disabled`) |
| fleet | fleet `enabled` | `WATCHER_FLEET_ENABLED` | true | every service is reported `disabled`; no store, budget, source or notifier is reached |
| fleet service | entry (or `defaults`) `enabled` | `WATCHER_ENABLED` | **false** (as a service) | that service is skipped with outcome `disabled` (not an error, exit 0) |
| fleet correlation | `correlation.enabled` | `WATCHER_FLEET_CORRELATION_ENABLED` | true | no cross-service annotation or correlated message |

Where a section is not a plain map the flag is a reserved key or a small map: `notify.notice` may be `['enabled' =>
false]` alone (it then uses the digest destination); `watch_rules`, `mute` and `dependencies` reserve the key `enabled`
(a rule or dependency cannot be named `enabled`); `mute` keeps its list items beside it.

**Deliberately absent.** There is no flag for redaction, the normalizer or the allowlist (privacy is not optional; an
architecture test fails if one appears) and none for read-only behaviour. `analysis.enabled` is not provided: a digest
without model findings needs a new groups-only digest section and an extension of the privacy corpus, which is not a
small change; use `notify.digest.enabled=false` (no model calls) or `watcher run --dry-run --estimate` instead.

**Environment and shared state.** With `environment` set, a service's keys are `<service>@<environment>` (for example
`orders-api@int`). Two processes that share one store and use the same prefix share state; a process cannot see the
other's config, so `watcher check` cannot warn about it. Give each environment its own `environment` (or its own
store). Fleet validation rejects two entries with the same `service@environment`.

**Minimal enabled config.** The smallest config that runs (the rest of the required keys as in
[Required keys](#required-keys)):

```php
return [
    'enabled' => true,                 // or WATCHER_ENABLED=1 with Env::bool('WATCHER_ENABLED', false)
    'service' => 'orders-api',
    // schedule.run_at, source, analysis, state, notify.digest ...
];
```

## Environment variables used by the shipped configs

`config/watcher.php` (Laravel/Lumen) reads these through `env()`. `config/watcher.example.php` reads the subset marked
with an asterisk through `getenv()`; the rest are set in the array.

| Variable | Config key |
|---|---|
| `WATCHER_SERVICE` | `service` |
| `WATCHER_ENABLED`, `WATCHER_ENVIRONMENT`, `WATCHER_*_ENABLED` | feature flags, see [Feature flags](#feature-flags-spec-012) |
| `WATCHER_RUN_AT`, `WATCHER_TIMEZONE` | `schedule.run_at`, `schedule.timezone` |
| `WATCHER_SOURCE` | `source.driver` |
| `WATCHER_LOKI_URL*`, `WATCHER_LOKI_TOKEN*`, `WATCHER_LOKI_TENANT` | `source.loki.*` |
| `WATCHER_DD_SITE`, `WATCHER_DD_API_KEY*`, `WATCHER_DD_APP_KEY*` | `source.datadog.*` |
| `WATCHER_ANTHROPIC_KEY*` | `analysis.api_key` |
| `WATCHER_MODEL_TRIAGE*`, `WATCHER_MODEL_ANALYZE*`, `WATCHER_MODEL_ESCALATE*` | `analysis.models.*` |
| `WATCHER_PRICE_{TRIAGE,ANALYZE,ESCALATE}_{IN,OUT}*` | `analysis.prices` (example config only) |
| `WATCHER_STATE`, `WATCHER_STATE_PATH` | `state.driver`, `state.file.path` |
| `WATCHER_RUN_DEADLINE` | `schedule.run_deadline` (seconds; shipped `config/watcher.php` only) |
| `WATCHER_REDIS_{HOST,PORT,PASSWORD,DATABASE}` | `state.redis.*` |
| `WATCHER_DB_{DSN*,USERNAME,PASSWORD}` | `state.pdo.*` |
| `WATCHER_SLACK_WEBHOOK*` | `notify.digest.webhook` (Telegram and Discord: `bot_token`, `chat_id`, `webhook` the same way) |
| `WATCHER_SERVICE_CONTEXT` | `service_context` |
| `WATCHER_RELEASE*`, `WATCHER_COMMIT*` | `deploy.release`, `deploy.commit` |
| `WATCHER_GRAFANA_URL`, `WATCHER_LOKI_DS_UID` | `links.grafana.*` |
| `WATCHER_REPO_URL` | `repo.web_url` |

`config/watcher.php` ships `'prices' => []` and has no price variables: it fails validation (`analysis.prices (no price for models.triage)`) until you fill the array in.
