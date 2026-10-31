# Configuration reference

One PHP array per service. Plain PHP passes it to `Watcher::fromConfig()` (or returns it from the file given to
`bin/watcher run --config=`); Lumen/Laravel reads `config/watcher.php`. Complete working examples:
[`config/watcher.example.php`](../config/watcher.example.php) (plain PHP, every key with its default),
[`config/watcher.php`](../config/watcher.php) (Laravel/Lumen, reads `env()`), and the minimal
[`docs/examples/smoke.config.php`](examples/smoke.config.php).

The configuration is validated once, before any external call. A problem stops the run with exit code 2 and one line
that names the key (never the value): `invalid config: <key path>` (the message names the key, never the value). **Unknown
keys are rejected at every level** (`unknown configuration key: selection.min_levl`; the message names the key path, never a value), so a typo cannot silently disable a setting. Free-form maps (`source.stream_tags`, `dependencies.<name>.stream_tags`, `redaction.extra_patterns`, `watch_rules`, `path_map`) accept any names.

Secrets and model ids are never defaults in code: they come from the environment (or your secret store).

## Required keys

The master switch `enabled` (`WATCHER_ENABLED`) defaults to **false**: set it to true (per environment) or `run` reports `disabled` and does nothing. See [Feature flags](#feature-flags-spec-012).

`service`, `schedule.run_at`, `source.driver` with that driver's credentials, `analysis.api_keys.<provider>`,
`analysis.models.{triage,analyze,escalate}`, `state.driver` with its
settings, `notify.digest.webhook`.

## Keys

### `service`, `schedule`

| Key | Default | Notes |
|---|---|---|
| `service` | required | Prefix for every state key. **Changing it starts from scratch** (a new baseline). |
| `environment` (`WATCHER_ENVIRONMENT`) | none | Optional label (`int`, `prod`, ...): state keys become `<service>@<environment>`, so two processes with the same service name do not share state. |
| `schedule.run_at` (`WATCHER_RUN_AT`, comma-separated) | required unless `every` | `HH:MM` or a list of times at least one hour apart. Still due for one hour after each time, in `schedule.timezone`. Stored internally as a list; a single string is unchanged in config files. |
| `schedule.every` (`WATCHER_EVERY`) | — | `PT1H`, `PT2H`, `PT3H`, `PT4H`, `PT6H`, `PT8H`, or `PT12H`. Mutually exclusive with `run_at`. Contiguous windows unless `schedule.window` is set. |
| `schedule.timezone` (`WATCHER_TIMEZONE`) | `UTC` | IANA zone. Log-window and run days follow this zone. |
| `schedule.window` | `PT24H` for one `run_at`; gap to the previous boundary otherwise | ISO-8601 lookback ending at the window boundary. |
| `schedule.catch_up` (`WATCHER_CATCH_UP`) | `1` | `1`..`24`; values above `1` require `schedule.every`. |
| `schedule.ingestion_delay` (`WATCHER_INGESTION_DELAY`) | `PT0S` | Shorter than the period; shifts window ends for late-indexed lines. |
| `schedule.lock_ttl` | `900` | Seconds the per-window run lock lives (released at the end of every run). With a store that supports renewal (all three) the lock is **extended** between pipeline steps and before each model call whenever less than half of it is left, so a long run keeps it. One model call (two sequential attempts, SDK retries off) is still bounded to `lock_ttl / 4`: HTTP timeout `lock_ttl / 8` s, within 10..180. A run makes at most `2 + escalation_cap` model calls. |
| `schedule.run_deadline` (`WATCHER_RUN_DEADLINE`) | `lock_ttl` minus `max(30, lock_ttl / 10)` s (810 by default; half of `lock_ttl` when that is 60 or less) | Total wall-clock budget of one run, checked while reading and between pipeline steps. At expiry reading stops (read marked truncated, reason `deadline`), no model call starts, the window is snapshotted and a short notice is sent, but it is NOT recorded as done: the next trigger in the due period reads it again with a fresh run deadline, once. A second cut records the window final (quiet, reason `truncated:deadline`) with a second notice naming the number of unanalysed groups. Must not exceed `lock_ttl`. In a fleet it is further limited by `service_time_budget`.

### `source`

| Key | Default | Notes |
|---|---|---|
| `source.driver` | required | `loki` or `datadog`. |
| `source.loki.url` | required for loki | Loki base URL, or Grafana's data-source proxy `https://<grafana>/api/datasources/proxy/uid/<uid>`. Requests append `/loki/api/v1/...`. |
| `source.loki.auth` | none | `['type' => 'bearer', 'token' => ...]` or `['type' => 'basic', 'user' => ..., 'password' => ...]`. A **read-only** credential. |
| `source.loki.tenant` | null | Sent as `X-Scope-OrgID` for multi-tenant Loki. |
| `source.loki.allow_insecure` | `false` | The Loki URL must have a host and no credentials in the URL. `https` is required. Plain `http` is refused unless `allow_insecure: true` is set AND the host is loopback (`localhost`, `127.x`, `::1`), a private-network address (RFC 1918: `10/8`, `172.16/12`, `192.168/16`) or a cluster-local name (`*.svc`, `*.svc.cluster.local`, `*.cluster.local`). A public host is never accepted over `http`. `allow_insecure` is an explicit trust decision about the network between watcher and Loki (the bearer or basic credential travels in clear text); it is never a default. Redirects are never followed; TLS verification is always on. |
| `source.datadog.site` | `datadoghq.com` | e.g. `datadoghq.eu`; requests go to `https://api.<site>`. |
| `source.datadog.api_key`, `source.datadog.app_key` | required for datadog | The application key needs only the `logs_read_data` scope. |
| `source.stream_tags` | `[]` | Labels that select this service's logs, e.g. `['app' => 'orders-api', 'env' => 'prod']`. Loki selector `{app="..",env=".."}`, Datadog query `app:.. env:..`. |
| `source.read_min_level` | `warning` | Levels below this are only counted in totals (and read in a second pass if their total jumps). |
| `source.anomaly_cap` | `20000` | Event cap of that second pass. |
| `source.level_mode` | `text` | How `read_min_level` is pushed to the backend. **Loki:** `text` = LogQL line filter on level words plus stack-trace continuation shapes (misses unworded main lines whose level lives only in the stream label); `label` = stream matcher on the `level` label, no line filter (with watch rules present it falls back to the unrestricted selector, because LogQL cannot OR a stream matcher with a line filter); `all` = no level restriction. **Datadog:** `all` = no `status:` filter, anything else = status filter. |
| `source.datadog.newest_first` | `false` | `true` sorts `-timestamp`, so an `event_cap` truncation keeps the newest events instead of the oldest. |
| `source.group_cap` | `5000` | Hard bound on distinct problem groups held in memory per run. Past it, events with a new message are grouped as one "other" group per level and the digest says "cluster cap hit". Events matching a watch rule keep their own group up to 2 x this value. |
| `source.max_response_mb` | `16` | Largest backend reply accepted, 1 to 64 MB. A page over it is retried at half the page size instead of failing the source. |
| `source.event_cap` | `200000` | Hard cap on events read per run; the digest says "truncated" when it is hit, and a quiet run that hit it sends a notice once per window. Reading is oldest first, but one fifth of the cap is reserved for the newest events: the read stops at 80% of the cap, then reads the end of the window backwards for the rest, so today's incident is not hidden behind old events (a cap below 5 has no share; `source.datadog.newest_first` keeps only the newest). A read also stops, with its reason in the run record, at the run deadline, at 80% of PHP `memory_limit` (reason `memory`), after 1000 Loki pages, after 300 s of Loki paging, or when Loki's cursor stops advancing (`cursor`). Plain lines over 64 KB never reach the parser; they are dropped and counted (the digest read-quality line names the count). A structured (`{`) line over 64 KB becomes a `[structured line too large, content withheld]` placeholder event that keeps its level. A reply over the response cap (`source.max_response_mb`, default 16, max 64) is not fatal: the same cursor is re-read with half the page size (down to 100 lines) and the digest read-quality line counts the re-reads; only a 100-line page over the cap fails (`response too large`), as does a declared `Content-Length` over the cap. JSON nested deeper than 64 levels is refused. Service and environment names must not contain `@`. |

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

### `analysis` (models)

| Key | Default | Notes |
|---|---|---|
| `analysis.provider` | `gemini` | `openai` or `gemini`: the provider of every step unless `analysis.providers` names another. |
| `analysis.providers.{triage,analyze,escalate}` | `analysis.provider` | Optional per-step provider. Mix freely, e.g. triage on `openai`, the rest on `gemini`. |
| `analysis.api_keys.{openai,gemini}` | none | One key per provider in use (secret). A provider used by a step without a key fails at load, naming the key path. |
| `analysis.providers_options.{openai,gemini}` | the provider's host, `retries` 0 | `base_url` (must be `https`). `retries` 0 to 3: a transient failure (429, 500, 502, 503, 504, a timeout) is retried within the call timeout; a refusal or an invalid reply is not. |
| `analysis.client` | absent | No longer a transport choice. Absent or `http` is accepted and ignored. Any other value is refused. Delete the key. |
| `analysis.models.{triage,analyze,escalate}` | required | One id per tier. Cheapest model for triage, strongest for escalation. |
| `analysis.max_input_tokens` | triage 20000, analyze 30000, escalate 15000 | Per-step input limit; lowest-priority candidates are dropped first, with a note in the digest. The estimate (UTF-8 bytes / 3) includes the step's system prompt and the `service_context`, not only the groups. Enforced for `triage` (candidates), `analyze` (dependency context shrinks first) and `escalate`: an escalation sends the one finding's groups plus the dependency context that refers to them; over the limit, dependency context is trimmed first, then the groups' stack frames, and if it still does not fit the escalation is skipped (the first-pass finding is reported as it is; the digest says `N escalation(s) skipped`). |
| `analysis.max_output_tokens` | triage 1500, analyze 4000, escalate 2000 | A floor, not a ceiling: triage uses `max(configured, 30 * groups + 500)` because the verdict list grows with the groups; analyze and escalate use `max(configured, 450 * groups + 300)` while `analysis.advice.enabled` is on (a finding with `how_to_check` and `related_checks` is about 450 output tokens at its text limits, so a full `top_m` of long findings is not cut off and retried). With advice off the configured value is used as it is. |
| `analysis.confidence_threshold` | `0.6` | Findings of severity error or higher below this are re-analysed once by the strong model; warn/info below it collapse into the "minor" line. `0 < x < 1`. |
| `analysis.escalation_cap` | `3` | Escalations per run. A finding is escalated only when one of its groups is at `error` or above in the logs, the finding itself is `error`/`critical`, and its confidence is below `analysis.confidence_threshold`. |

### `redaction`

| Key | Default | Notes |
|---|---|---|
| `redaction.min_group_events_for_text` | `3` | A group with fewer events in its cluster sends **no message text** to the model (only level, error type, frames, counts). Raise it for stricter privacy, lower it never below what your DPO accepts. |
| `redaction.extra_patterns` | `[]` | `NAME => regex`. Matches are replaced with `<NAME>` in everything that leaves the service. `NAME` may not be `N`, `EMAIL`, `URL`, `HOST`, `IP`, `ID`, `TOKEN`, `PAN`, `STR`, `PATH` or `USER` (reserved). |

### `state`

| Key | Default | Notes |
|---|---|---|
| `state.driver` | required (set it to `redis`: the shipped `config/watcher.php` and the example do) | `redis`, `pdo` or `file`. **`file` is valid for a single runner only**, and in Kubernetes it loses history with every pod (see operations.md, "Where to keep state"). |
| `state.retention` | `2592000` | Seconds every stored item lives (30 days). Fingerprints, counters, verdicts and findings; never raw logs. |
| `state.redis.read_timeout` | `5` | Seconds a Redis call waits for a reply (the PHP socket default is 60). |
| `state.redis.client` | `auto` | `auto` (`ext-redis` when loaded, else `predis/predis`), `phpredis` or `predis`. Predis is a `suggest`, not a requirement. |
| `state.redis.prefix` | `watcher:` | Key prefix (1 to 64 of letters, digits, `_ . : -`). Give each environment that shares a Redis database its own. |
| `state.redis.compress_min_bytes` | `512` | Values written with `put` that are larger than this are stored as `z1:` + zlib (level 6) bytes. `0` turns compression off. Counters, locks and markers are never compressed. Needs `ext-zlib` (without it nothing is compressed and `watcher check` warns). Old uncompressed values keep reading. |
| `state.redis.{host,port,username,password,database}` | `127.0.0.1`, `6379`, null, null, `0` | `username` + `password` authenticate as a Redis 6 ACL user. A `tls://host` value of `host` connects over TLS (phpredis and Predis; the certificate is verified against PHP's CA bundle, see `docs/operations.md`); plain `AUTH` is otherwise sent in clear. Or inject a connected `\Redis` (it must not use a serializer: `Redis::SERIALIZER_NONE`, otherwise it is refused), a Predis client or a `Watcher\State\Redis\RedisClient` as `state.redis.instance` (it wins). Every key carries a TTL. |
| `state.pdo.{dsn,username,password,table}` | dsn required; table `watcher_state` | MySQL, PostgreSQL or SQLite. The table is **never created implicitly**: create it with `Watcher\State\PdoStateStore::createTableSql('mysql'\|'pgsql'\|'sqlite')`. |
| `state.file.path` | required for file | Writable directory; the package creates it with mode 0700 when it is missing, an existing directory keeps its mode, so keep it private to the service user. File names are hashes of the keys. |

### `notify`

| Key | Default | Notes |
|---|---|---|
| `notify.digest.driver` | `slack` | `slack`, `telegram`, `discord` or `none`. `none` is dashboard-only mode: every message is accepted and dropped, no other key is allowed under that entry, and at least one `observe` sink must be enabled (`notify.digest.driver none needs at least one enabled observe sink`). `notify.digest.enabled: false` is not the same: it skips the analysis. |
| `notify.digest.webhook` | required for slack, discord | Slack incoming webhook, or `https://discord.com/api/webhooks/<id>/<token>` (`discordapp.com` also accepted). |
| `notify.digest.allow_insecure` | `false` | Slack only (same for `notify.notice`): the webhook must be `https` with a host and no credentials; `true` allows plain `http` for a loopback, private-network or cluster-local host only (same rule as `source.loki.allow_insecure`). Redirects are never followed. |
| `notify.digest.bot_token` | required for telegram | Bot API token (`123456:ABC...`). Read it from the environment. |
| `notify.digest.chat_id` | required for telegram | Integer or `@channel` name; a supergroup id is negative. |
| `notify.digest.thread_id` | none | Telegram forum topic id (positive integer). Discord has no equivalent. |
| `notify.notice` | null | Separate destination (same shape, any driver) for failures and baseline messages; null uses the digest destination. |
| `notify.days` | `null` | Days (`mon`..`sun`, in `schedule.timezone`) on which the digest and immediate alerts are sent; `null` = every day. Outside them a message is dropped, not queued. Example for working days only: `['mon','tue','wed','thu','fri']`. The run still reads and analyses on those days. Failure notices, probes and the heartbeat are not affected. |

A key of another driver (`webhook` on a Telegram entry) is an error that names the key path, never the value. In a fleet
file, a service's `notify.digest` or `notify.notice` **replaces** the fleet default as a whole (it is not merged key by
key), and `digest: combined` needs a fleet `notify.digest` of any driver.

### Removed in 0.7.0: `kubernetes`, `events` and `source.driver: kubernetes`

The Kubernetes event source (spec 008) is gone. A configuration that still has the top-level `kubernetes` section, the
top-level `events` section or `source.driver: kubernetes` fails at load, and the message says to delete it. Read the events
from your log backend instead, through `source.driver: loki` or `datadog`. See [kubernetes.md](kubernetes.md).

### `observe` (event sinks, spec 013)

Guide, queries and the dashboard: [observability.md](observability.md). **Every sink is off** until its own `enabled` is `true`
(a real boolean; `'false'` is rejected naming the key). A sub-key of a disabled sink is still validated. Errors name the key
path, never a value. Redaction and the event sanitizer have no switch.

| Key | Default | Notes |
|---|---|---|
| `observe.top_groups` | `20` | 0..100 `group` events per run; `0` emits none. |
| `observe.log.enabled` | `false` | One JSON object per line. |
| `observe.log.stream` | `stderr` | `stderr` or `stdout`; under `--json` stdout is replaced by stderr so the summary stays one line. |
| `observe.log.include_text` | `false` | Opt in to put the (redacted) finding text on the stream; stderr is often forwarded by a shipper to a third party. |
| `observe.file.enabled` | `false` | JSON lines file, mode 0600. |
| `observe.file.path` | required when enabled | Absolute, no `..`, not a symlink; the directory must be yours and not group/world-writable unless sticky. |
| `observe.file.rotate` | `size` | `size` or `daily`. |
| `observe.file.max_bytes` | `10485760` | 1 MiB to 1 GiB (rotation by `size`). |
| `observe.file.max_files` | `10` | 1..100 rotated files kept. |
| `observe.file.retention_days` | `30` | 1..365; older files are deleted at each flush. |
| `observe.file.include_text` | `false` | |
| `observe.http.enabled` | `false` | NDJSON `POST`. |
| `observe.http.url` | required when enabled | `https` with a host and no credentials; plain `http` only with `allow_insecure` **and** `allow_private_network` and a loopback, private-network or cluster-local host. A ClusterIP in CGNAT (100.64.0.0/10) or unique-local IPv6 space cannot be configured even with the flag: use the service's `*.svc` name. A secret: masked everywhere. Must not point at the log source. |
| `observe.http.auth_header` | `Authorization` | Header name. |
| `observe.http.auth_scheme` | `Bearer` | Word before the token; `''` (in code) sends the token alone, for `X-API-Key` style headers. |
| `observe.http.auth_token` | none | A secret, at least 8 characters; no header is sent when absent. |
| `observe.http.batch_size` | `100` | 1..1000 events per request. |
| `observe.http.timeout` | `5` | 1..30 seconds per request; the whole flush is also bounded by what is left of the run deadline. |
| `observe.http.allowed_hosts` | `[]` | When set, the URL host must be one of them. |
| `observe.http.allow_insecure` | `false` | Permits `http://` (trusted-network hosts only, and only together with `allow_private_network`) and emits `config_warning http_insecure_allowed`. |
| `observe.http.allow_private_network` | `false` | Permits loopback, RFC 1918 and cluster-local hosts; link-local, CGNAT, metadata, unique-local IPv6 and reserved ranges stay refused (shared classifier). |
| `observe.http.include_text` | `false` | `true` emits `config_warning include_text_external`. |
| `observe.prometheus.enabled` | `false` | Textfile in the node_exporter format. |
| `observe.prometheus.dir` | required when enabled | Absolute directory that exists and is writable. |
| `observe.prometheus.name` | `watcher_<service>[_<environment>]` | `[a-z0-9_]{1,64}`; must be unset in a fleet. |
| `observe.prometheus.file_mode` | `0644` | Octal string `0600`..`0664`. |
| `observe.otlp` | reserved | Rejected: `observe.otlp is not available yet`. |

With any sink enabled, `service` must match `[A-Za-z0-9][A-Za-z0-9._@-]{0,63}` and `environment` `[A-Za-z0-9][A-Za-z0-9._-]{0,31}` (the event schema's own patterns); a name outside them is refused at load naming the key, because the sanitizer would otherwise null it and every metric label would read `unset`. Without a sink the old, wider rules apply.

In a fleet, `observe` may be set at the fleet level or in `defaults`; a service's own `observe` replaces it as a whole.
Each service flushes its own events (with its own `service` and `environment`) and writes its own Prometheus file.

### `api_context` (OpenAPI endpoint documentation, spec 010)

**Off** until the section exists with a `source` (an absent section, or `enabled: false`, reads nothing, writes no cache entry and leaves every
payload byte-identical). Validated even with `enabled: false`; errors name the key path, never a value. It is **not** a feature flag in the
table below. The Normalizer rewrites any path of 3 or more segments, so only routes of **at most 2 segments** (`GET /orders/<N>`) can match
a documented operation.

| Key | Default | Notes |
|---|---|---|
| `api_context.enabled` | `true` | `false` switches it off and still validates. |
| `api_context.source` | required when enabled | An absolute file path (or `file:/path`) to a JSON document, or an `https` URL. YAML is not supported. A URL may carry a token: it is a secret (masked everywhere). |
| `api_context.auth_token` | none | Sent as `Authorization: Bearer` on the one `GET`. A secret. |
| `api_context.ttl` | `PT24H` (never above the retention) | ISO-8601 duration, at least 1 second, at most `state.retention`. The cache entry expires exactly then; there is no stale fallback. |
| `api_context.max_endpoints` | `5` | 1..20 distinct endpoints per run; the digest says how many were skipped. |
| `api_context.summary_cap` | `80` | 20..200 characters kept per summary, operationId and tag, after masking. |
| `api_context.max_bytes` | `1048576` | 65,536..2,097,152 decoded bytes of the document (a 2 MB flat JSON decodes to about 144 MB in PHP, hence the ceiling). |
| `api_context.base_path` | none | Overrides `servers[0].url` / `basePath`; `/api/v1`. |
| `api_context.ignore_prefixes` | `[]` | At most 10 `/segment` prefixes stripped from routes. |
| `api_context.allow_insecure` | `false` | With `allow_private_network`, permits plain `http://`. |
| `api_context.allow_private_network` | `false` | Same rule as `observe.http`: `https` only; plain `http` needs `allow_insecure` **and** `allow_private_network` **and** a loopback, RFC 1918 or cluster-local host (`*.svc`, `*.cluster.local`); a private host over `https` needs `allow_private_network`. Link-local, CGNAT, metadata, unique-local IPv6 and reserved ranges stay refused whatever is allowed. Redirects are never followed; names are not resolved (a documented limit); `HTTP_PROXY` is honoured (see [operations.md](operations.md#proxies-and-the-internal-address-check)). |
| `api_context.allowed_roots` | `[]` | A file source must be a canonical path (no symlink in any component, no world-writable ancestor without the sticky bit) and is judged on the opened handle. With no roots the only file read is the configured `source` itself; with roots the source must lie below one of them. Any refusal is the single token `unreadable`, so a path reveals neither existence nor format. |

Failures are one fixed word in the digest (`API context unavailable (<token>)`) and in `watcher check`: `unreachable`, `unauthorized`, `timeout`,
`too_large`, `bad_format`, `unsupported_format`, `unsupported_version`, `unreadable`, `unsafe_source`. Nothing else is printed (no URL, path, header or body).

### Command-line flags (not config keys)

`bin/watcher run --config=<file> [--now=<ISO-8601>] [--dry-run [--estimate]] [--json]`, `bin/watcher check --config=<file>
[--post-test] [--json]`, `bin/watcher status --config=<file> [--json]`, `bin/watcher state:get <key> --config=<file>`. `--estimate` requires `--dry-run`. See
[operations](operations.md) for what each prints and its exit codes.

### Health check

`php artisan watcher:health` (Lumen/Laravel adapter only) reads the host app's health snapshot from the host's Redis, once per hour, and
alerts at once when a service's version changes. It is separate from the daily run: no run gate, no digest. The host schedules it hourly
(`$schedule->command('watcher:health')->hourly()`). The snapshot is one JSON object, `{"<service>": {"success": bool, "version": string|null,
"checked_at": ISO-8601}}`. Only those four fields are stored, one copy per service, under `health:<watcher service>:<snapshot service>` in the watcher's Redis (prefix
included). A `null` or `Version not defined` version is unknown and never alerts. The first time a service is seen, its copy is stored silently.

| Key | Default | Meaning |
|---|---|---|
| `health_check.enabled` | `false` | Off by default. The watcher's `enabled` (master switch) must be on as well. |
| `health_check.source.host` | required | Redis host of the host app. |
| `health_check.source.port` | `6379` | Redis port. |
| `health_check.source.database` | `0` | Database index of the host app (read only). |
| `health_check.source.username` | none | Redis ACL user, when the host app uses one. |
| `health_check.source.password` | none | A secret: masked everywhere. |
| `health_check.source.key` | required | Full raw key of the snapshot, for example `m-seq:health`. No watcher prefix is added. |
| `health_check.ttl_days` | `14` | 1..365. A stored copy lives this long; every hourly write refreshes it. |
| `health_check.notify_transitions` | `false` | Also alert once when a service flips between up and down. Never on every failing hour. |
| `health_check.timezone` | `UTC` | IANA zone (for CET/CEST set `Europe/Berlin`) for `summary_at`, `summary_hours` and `summary_days`. Independent of `schedule.timezone`. |
| `health_check.summary_at` | `09:00` | `HH:MM` in `health_check.timezone`, or `null` for no daily summary. Once a day a summary of every service (name, version, up/down, `checked_at`) is sent, even when nothing changed. Only the hour counts, because the check runs hourly. |
| `health_check.summary_hours` | `09-18` | `HH-HH` working hours (start included, end excluded) the summary may be sent in; `null` = all day. |
| `health_check.summary_days` | `['mon','tue','wed','thu','fri']` | Days health messages may be sent on (`mon`..`sun`), both the summary and the version and up/down alerts. Outside these days nothing is sent or stored; a change made on a weekend is sent on the first allowed day. `null` = every day. |
| `health_check.problems_hourly` | `true` | Every hour inside `summary_hours` on an allowed day, a `HEALTH PROBLEMS` message lists only the services that are DOWN or STALE. Nothing is sent when all are up. In the `summary_at` hour the full summary is sent instead. A service that went down in this very run (with `notify_transitions`) is not listed again in the same hour. While a service stays down it is listed every working hour, so for a quieter channel set this to `false` and keep only the daily summary. |
| `health_check.stale_after_hours` | `3` | `1`..`168`, or `null` to turn it off. A service whose `checked_at` is older than this, is not an ISO-8601 timestamp (`now` or `+1 day` do not count), or is more than an hour in the future is shown as `STALE`, even if its last status was up: the host stopped updating it. |

A failed summary send is logged (`health: summary not sent`) and does not change the exit code or the version alerts.

**When the check itself cannot tell anything.** A missing or unreadable snapshot, an unreachable Redis, or a snapshot without one valid service entry (including `{}`) sends `HEALTH CHECK BROKEN` with the reason at once, a reminder once every 24 hours while it lasts, and `HEALTH CHECK RECOVERED` once when it works again. A notice that fails to send is retried the next hour. This covers a broken source, not a watcher that does not run at all: if the host stops calling `watcher:health`, nothing can be sent, so watch the schedule from outside (for example a dead-man heartbeat).

Alerts go through `notify.notice` (the digest destination when it is not set). A failed send keeps the old copy, so the next hour retries.
A failed read or send exits this command with 1 and writes to the host log; the daily run and the digest are not affected.

Example for m-seq (test11), in the watcher's configuration:

```php
'health_check' => [
    'enabled' => true,
    'source' => [
        'host' => 'ew-redis',
        'database' => 0,            // the m-seq app's Redis database, not the watcher's (3)
        'password' => env('WATCHER_HEALTH_REDIS_PASSWORD'),
        'key' => 'm-seq:health',
    ],
    'ttl_days' => 14,
    'notify_transitions' => false,
],
```

### Fleet configuration

A fleet file returns an array (or a directory: `_fleet.php` with the fleet keys, plus one `*.php` per service, loaded in
name order). Each service is a complete single-service config, validated by the same rules, plus two fleet keys.
Validation covers the whole fleet before any call; the first error names the entry and key
(`services[1] (bff-a): selection.growth_factor must be greater than 1`). Example: [examples/watcher-fleet.php](examples/watcher-fleet.php).

| Key | Default | Notes |
|---|---|---|
| `fleet` | required | Name (`A-Za-z0-9._-`); part of the shared keys and messages. |
| `services[]` | required | Entries. `priority` (int, default 0, higher first) and `provides` (names other services use for this one, default `[service]`) are fleet-only. Order: priority, then name, then dependencies first. Names must be unique and must not contain `/`. |
| `defaults` | `[]` | Any single-service key, merged under every entry. `state` and `analysis.api_keys` must end up identical in all entries. |
| `digest` | `per_service` | `per_service` posts each digest to the entry's `notify.digest`; `combined` posts one message to the fleet `notify.digest`. |
| `notify` | `[]` | Fleet default destinations, merged under each entry's; also where the combined digest and the correlated finding go. |
| `timezone` | `UTC` | Day boundary of the fleet counters. |
| `time_budget` | none | ISO-8601 duration; services not started in time are `skipped_time` (exit 1). |
| `service_time_budget` | `time_budget` / number of services, at least half of the smallest `schedule.lock_ttl` | ISO-8601 duration: wall-clock share of one service, enforced through its run deadline, so one slow or hostile service cannot starve the rest. |
| `correlation.enabled`, `correlation.tolerance` | `true`, `PT0S` | Tolerance widens the time-overlap test. |

Commands: `bin/watcher fleet:run --config=<file|dir> [--now=<ISO-8601>] [--only=<a>[,<b>]] [--dry-run [--estimate]] [--json]`,
`fleet:check --config=<file|dir> [--post-test] [--json]`, `fleet:status --config=<file|dir> [--json]`. Laravel/Lumen:
`watcher:fleet` with the same options, reading `watcher.fleet`.

### `heartbeat` (optional)

| Key | Default | Notes |
|---|---|---|
| `heartbeat.stale_after` | 1.5 x `schedule.window` | ISO-8601 duration (> 0). `watcher status` reports `stale` and exits non-zero when the last reported/quiet/baselined run is older than this. |
| `heartbeat.weekly_summary` | `false` | `true` sends one line per ISO week (service timezone) to the notice destination, from the first due run of the next week: counts of reported, quiet, baselined, failed and skipped runs. Nothing is sent for a week with no run. |

### Immediate alerts

Off by default. A log line that matches a rule is sent at once, not in the digest, to `notify.notice` (or `notify.digest`
when that is unset), as a line that starts with `🚨 ALERT [critical] <service>: <message>`, then the context fields you
allow in `alerts.fields`, the environment and the time. The alert is checked on every read of the window, using the same
read as the digest, so it is only as early as the run that reads it. Source truncation (`source.event_cap`) can hide a
line from the alert as well as from the digest. Mute entries do not suppress alerts. Repeats within one read become one
message with `(×N)`. At most 5 alert messages go out per read; the rest are not recorded, so a later read sends them, and
one line says how many were held back.

| Key | Default | Notes |
|---|---|---|
| `alerts.enabled` | `false` | Master switch. Environment variable `WATCHER_ALERTS_ENABLED`. |
| `alerts.window` | `PT15M` | ISO-8601 duration (> 0). At most one alert per rule, message and affected service per window. Kept in the state store as a hash, so it survives a restart. |
| `alerts.fields` | `[]` | Context names an alert may show: any of `service`, `http_code`, `error`, `services`. Empty means no context values leave the service. Each listed value is masked: emails, tokens, URLs and numbers are replaced. `http_code` is kept only as three digits. `error` text is shown only for a group as large as `redaction.min_group_events_for_text`; `service` and `services` are identifiers and always shown, masked. |
| `alerts.immediate` | `[]` | Map of `name => ['level' => 'critical', 'message' => '...', 'action' => '...']`. Every given condition must hold. `level` (default `critical`) is a minimum and accepts any level name; `message` is a plain, case-sensitive substring of the log message; `action` must equal the event's `action` field exactly. A rule with only `level` matches every line at that level. |

The raw message is only matched in memory and never sent. The shown text is the rule's own `message` literal when it has one. Without a literal, the masked event text is shown only for a group as large as `redaction.min_group_events_for_text` (the digest's threshold); a smaller group shows `[withheld]`. Enabling `alerts.fields` sends masked field values to the
notice destination, which `docs/privacy.md` does not yet describe: the owner must update that page before you enable it.

Example for m-seq, whose health check logs `level: critical`, `message: "health check failed"` and a `context.service`
per failed service. The `fields` line is optional and is the privacy decision above:

```php
'alerts' => [
    'enabled' => true,
    'window' => 'PT15M',
    'fields' => ['service', 'http_code', 'error'],
    'immediate' => [
        'health-check' => ['level' => 'critical', 'message' => 'health check failed'],
    ],
],
```

A failed alert send is written to the host log (`immediate alert failed`) and does not fail the run or delay the digest.
Its window is only recorded after a successful send, so the next read retries it.

### `baseline` and `grouping` (optional)

| Key | Default | Notes |
|---|---|---|
| `baseline.mode` | `previous` | `previous`, `rolling` (mean of the last N windows) or `weekday` (median of the same weekday). `previous` is the old behaviour. Message: `baseline.mode must be one of previous, rolling, weekday`. |
| `baseline.windows` | 7 (rolling) / 4 (weekday) | Integer >= 2 (rolling) or >= 3 (weekday). Ignored for `previous`. |
| `baseline.max_age` | `P7D` | ISO-8601. Oldest snapshot a missing previous window may fall back to; must not exceed `state.retention`. |
| `baseline.exclude` | `[]` | Dates `YYYY-MM-DD` (local date of a window end) left out of rolling/weekday values. |
| `baseline.since` | `null` | `YYYY-MM-DD`: ignore windows that ended before this date (history reset). |
| `grouping.similarity` | `0.5` | `0 < x <= 1`: share of literal words that must match a cluster leader. |
| `grouping.memory_budget_mb` | `32` | Integer >= 1: estimated memory the template miner may hold. Each stored message keeps at most 64 tokens (the rest is one `<TRUNC>` placeholder). Past `memory_budget_mb` new messages are dropped like past `message_cap` (their groups show no text, `Grouping limit reached`). The clustering budget counts token operations. |
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
| `links.tempo.{base_url,datasource_uid,trace_field,service_name}` / `links.datadog_apm.{trace_field,service}` | none | A trace link per log finding (spec 009). `trace_field` (default `trace_id`) names the log field read for a trace id; a valid id (16-32 hex) of the group's earliest event goes **only inside the link URL** of the digest, never to the model, state, `--json` or a notice; a group without one gets a service-search link. `datadog_apm` needs `links.datadog.app_url`; Tempo wins when both are set. Built, never fetched. |
| `repo.{provider,web_url,path_map}` | none | `provider` is `gitlab` or `github`; `path_map` maps container path prefixes to repository paths for stack-frame links (needs `deploy.commit`). Vendor paths get no link. |

### `metrics` and `sentry` (optional, spec 009)

Both are **absent by default** (nothing is read, the digest is unchanged). A present section is validated even with `enabled: false`; errors
name the key path, never a value. They are not feature flags: the section's own `enabled` is the switch. Both are read-only, so give them a
read-only credential. Contract: `specs/009-metrics-and-sentry/contracts/config.md`.

| Key | Default | Notes |
|---|---|---|
| `metrics.enabled` | `true` | `false` keeps the section validated and reads nothing. |
| `metrics.source.type` | `prometheus` | `prometheus` or `grafana` (the datasource proxy; needs `datasource_uid`, letters, digits, `_ . -`). |
| `metrics.source.base_url` | required | `https` with a host and no credentials. See "Internal hosts" below. |
| `metrics.source.auth` | none | `['type' => 'bearer', 'token' => ...]`. A secret: masked everywhere. |
| `metrics.source.allow_insecure` | `false` | Plain `http` for a loopback, private-network or cluster-local host. |
| `metrics.source.allow_private_network` | `false` | Permits RFC 1918 and cluster-local (`*.svc`, `*.cluster.local`, `*.internal`) hosts. |
| `metrics.label_allowlist` | `[]` | Label **names** whose values may be shown (at most 5, masked twice, dropped if masking changes them). |
| `metrics.max_anomalies` | `3` | 0..10 metric anomalies turned into candidates per run. |
| `metrics.max_points` | `500` | 10..11000 samples a `range` query may return. |
| `metrics.vars` | `[]` | Extra `{placeholders}`; values match `[A-Za-z0-9_.:/-]{1,64}`. |
| `metrics.queries` | required | 1..6 metrics, `name => ['query', 'aggregate' (sum\|max\|avg\|last), 'unit', 'mode' (instant\|range), 'threshold' => ['relative', 'floor']]`. Built-in placeholders: `{service}`, `{window}`, `{start}`, `{end}`, `{baseline_start}`, `{baseline_end}`. No `threshold` = context line only, never an anomaly. |
| `sentry.enabled` | `true` | |
| `sentry.base_url` | `https://sentry.io` | A self-hosted Sentry: its URL. Same rules as `metrics.source.base_url`. |
| `sentry.organization`, `sentry.project` | required | Slugs (`A-Za-z0-9._-`, not only dots). |
| `sentry.auth_token` | required | A secret; read scope for issues only. |
| `sentry.max_issues` | `50` | 1..100 issues per run. |
| `sentry.allow_insecure`, `sentry.allow_private_network` | `false` | As for `metrics.source`. |

**Internal hosts.** Both URLs go through the same shared classifier as `observe.http` (`SecureUrl::internalHost`). An internal Prometheus or
Sentry in RFC 1918 space (`10/8`, `172.16/12`, `192.168/16`) or a cluster-local name (`*.svc`, `*.cluster.local`) is refused unless
`allow_private_network: true` is set on that section; plain `http` additionally needs `allow_insecure: true`; loopback needs `allow_insecure: true`. Link-local
(cloud credentials), CGNAT, metadata and the reserved ranges stay refused whatever is allowed, in every numeric spelling; a ClusterIP in CGNAT or
unique-local IPv6 space cannot be configured, use the service's `*.svc` name. Host names are not resolved to check them (a documented limit). Redirects
are never followed; every call has a 60 s wall-clock budget and stops when the run deadline is reached.
### `probes` (certificate and secret expiry)

Deterministic checks, no model call, off by default (`probes.enabled`; the master `enabled` must also be true). They warn before a TLS certificate or a declared secret expires and alert through the notice destination.

| Key | Default | Notes |
|---|---|---|
| `probes.enabled` (`WATCHER_PROBES_ENABLED`) | `false` | The section's own switch. Off: nothing is read, nothing is stored, nothing is sent; the section is still validated. |
| `schedule.probe_every` (`WATCHER_PROBE_EVERY`) | `PT1H` | ISO-8601, at least `PT5M`. Probes run on the first run trigger after this interval has passed (a due key with the same TTL). A host that triggers the run less often, for example once a day with `run_at`, runs them that often. |
| `probes.warn_days` (`WATCHER_PROBES_WARN_DAYS`) | `30` | Warn when at most this many days are left. |
| `probes.fail_days` (`WATCHER_PROBES_FAIL_DAYS`) | `7` | Fail when at most this many days are left, or when already expired. Must be below `warn_days`. |
| `probes.hosts` (`WATCHER_PROBES_HOSTS`) | `[]` | Host names. The TLS handshake on port 443 reads the leaf certificate's `validTo`. Verification is off so an expired certificate still yields its date. A host that cannot be reached is `unknown`: it never alerts and never changes the stored status. |
| `probes.secrets` | `[]` | List of `['name' => 'DB_PASSWORD', 'expires' => '2027-01-31', 'owner' => 'team-x']`. A secret is valid through its `expires` day (UTC). Only the date is compared; no secret value, environment variable or credential file is read. Config file only. |

Alerts: one short message per subject, when its status changes (a return to `ok` is announced too) or when a warning or failure still stands and its dedupe key has expired (24 hours). Messages name the subject and the days left, never a secret value, DSN, password or token.

Limit: the stored status lives 24 hours. A warning or failure that stood with no run for more than 24 hours has lost its record, so its return to `ok` is not announced. Keep the run trigger at least as often as `probe_every` to avoid it.

### `mcp` (optional, read-only by default)

The MCP server (`watcher mcp`, over stdio) reads from the same state. Only the groups named here are served.

| Key | Default | Env | Notes |
|---|---|---|---|
| `mcp.groups` | `['inspect','onboarding','docs']` | `WATCHER_MCP_GROUPS` | Any of `inspect`, `onboarding`, `docs`, `actions`. `actions` is off unless named. |
| `mcp.allow_dry_run` | `false` | `WATCHER_MCP_ALLOW_DRY_RUN` | Adds `watcher_dry_run`. |
| `mcp.artisan` | none | `WATCHER_MCP_ARTISAN` | Absolute path to the artisan file. Required when `actions` is on; checked at startup. |

With `actions` on, the tools `watcher_run_digest`, `watcher_health_check` and `watcher_test_message` run the artisan
commands `watcher:fleet` or `watcher:run`, `watcher:health` and `watcher:check --post-test`. A sending action needs
`confirm: true`; a digest is a dry run unless `dry_run: false`; `now` is for dry runs only. Each action runs at most once
per 5 minutes per session and is logged as `watcher [audit] <tool> exit=<code>`. `confirm` is a flag the client sends,
not a human approval.

## Log hygiene (spec 006)

`hygiene` section; every key optional. `enabled` (default **false**, `WATCHER_HYGIENE_ENABLED` in the shipped configs) switches on the privacy, shape and proposal reports. Keys: `read_min_level` (`info`), `event_cap` (200000, more is sampled and said so), `max_line_length` (2000), `noisy_share` (0.20), `variable_distinct` (20), `repeat_days` (7), `growth_factor` (2.0), `min_history_days` (14), `mute_min_daily` (100), `watch_lead` (1800 s), `decline_days` (90), `spike_factor` (2.0), `max_seconds` (900, time budget of one pass), `site_allow_dirs` (`[]`, directory names such as `data` that are accepted below a `repo.path_map` root although the privacy report's deny list names them). `variable_distinct` must be at least 5. Invalid values fail at start naming `hygiene.<key>`. Contract: `specs/006-log-hygiene/contracts/config.md`. `allow_constant_labels` (false) lets a message that never varies keep its label and a watch proposal (it may be one person's name; the privacy floor of [privacy.md](privacy.md#log-hygiene-reports-spec-006) otherwise withholds it), `literal_min_sites` (5, at least 2): a literal word of a shown template must be common log vocabulary or seen at that many different sites in the pass, else it is shown as `<w>`.

## Digest notices (counts only, never raw content)

Besides findings, the digest carries short notices: `truncated: N events read` (`event_cap`), `truncated: N groups dropped (input limit)`
(`max_input_tokens`), `cluster cap hit` (`group_cap`), `Grouping limit reached: N messages were not clustered` (`grouping.message_cap`), `Grouping time budget reached` (comparison budget spent; counts only), `N group(s) flagged interesting were not analysed` (`top_m` cut them, or the step's input did not fit `max_input_tokens`), `compared with the run of <date>, N days ago` (the previous window had no snapshot; see `docs/operations.md`), `N pattern evaluation failures: rules may have been skipped` (a watch rule, mute or
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
| immediate alerts | `alerts.enabled` | `WATCHER_ALERTS_ENABLED` | false | `alerts.immediate` is not matched, no alert is sent and no alert window is stored |
| master switch | `enabled` | `WATCHER_ENABLED` | **false** | `run` reads only this raw key first, **before validating anything**: only the boolean `true` turns the package on, so missing, `null`, `false`, `0`, `'0'`, `'false'`, `''`, `'yes'` or any other value is off (fail-safe; `check` still reports a non-boolean as invalid). With the switch off, `run` (also with `--dry-run`/`--estimate`) returns outcome `disabled` even on a broken or half-filled config (exit 0, `watcher: disabled (set enabled=true / WATCHER_ENABLED=1)` on stderr); the Disabled JSON summary still carries `service` and `environment` when they look like names; no source, state, model or notifier access, not even a heartbeat. The same short-circuit applies to every other entry point: `watcher check` / `Factory::doctor` (reports `disabled`; validates nothing, builds nothing), `watcher status` / heartbeat (no store read; validates nothing), `watcher hygiene` / `Hygiene::fromConfig`, fleet `FleetRunner::fromConfig` when the fleet switch is off, and MCP inspect tools (`watcher_status`, `watcher_latest_report`, `watcher_finding`, `watcher_hygiene_report`; `watcher_dry_run` is not offered). `check` reads the switch first too, so a broken config is reported `disabled` (exit 0), not invalid |
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
| triage | `analysis.triage.enabled` | `WATCHER_TRIAGE_ENABLED` | true | no triage call and no triage checkpoint; the selected candidates go straight to analysis (`top_m` and the input limit still apply) |
| advice | `analysis.advice.enabled` | `WATCHER_ADVICE_ENABLED` | true | the analysis prompt and output schema leave out `how_to_check` and `related_checks`: no `Check:` / `Related:` lines in the digest, about 100 fewer output tokens per finding, and the prompt, schema, caps and digest are exactly those of a version without the feature |
| baseline | `baseline.enabled` | `WATCHER_BASELINE_ENABLED` | true | previous-window comparison only, whatever `baseline.mode` says |
| grouping | `grouping.enabled` | `WATCHER_GROUPING_ENABLED` | true | the old exact token-count clusters instead of similarity clusters (fingerprints are unchanged) |
| notices | `notify.notice.enabled` | `WATCHER_NOTICE_ENABLED` | true | no failure, baseline or weekly notice is posted; failures still go to the host log and the exit code |
| digest | `notify.digest.enabled` | `WATCHER_DIGEST_ENABLED` | true | no digest is posted, so no model is paid for: the window is read and snapshotted and the run ends `quiet` (reason `digest_disabled`) |
| known_issues | `known_issues.enabled` | `WATCHER_KNOWN_ISSUES_ENABLED` | true | inert with no `entries`; false skips known lines, state writes and suppression |
| drafts | `drafts.enabled` | `WATCHER_DRAFTS_ENABLED` | false | paste-ready ticket text per finding after the digest (`drafts.destination` or notice) |
| routes | `notify.routes_enabled` | `WATCHER_ROUTES_ENABLED` | true | inert without `notify.routes`; severity routing to extra destinations (spec 005) |

`known_issues.entries[]`: `fingerprint` (12–64 hex, prefix match), `ticket` (opaque key), optional `status` (`open`/`resolved`), optional `until` (UTC date). `notify.routes[]`: `name`, `severity` list (omitted on the last catch-all route only), optional `to` destination (must differ from `notify.digest`). See `specs/005-incident-workflow/contracts/config.md`.
| fleet | fleet `enabled` | `WATCHER_FLEET_ENABLED` | true | every service is reported `disabled`; no store, source or notifier is reached |
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
| `WATCHER_GEMINI_KEY*` | `analysis.api_keys.gemini` |
| `WATCHER_MODEL_TRIAGE*`, `WATCHER_MODEL_ANALYZE*`, `WATCHER_MODEL_ESCALATE*` | `analysis.models.*` |
| `WATCHER_STATE`, `WATCHER_STATE_PATH` | `state.driver`, `state.file.path` |
| `WATCHER_RUN_DEADLINE` | `schedule.run_deadline` (seconds; shipped `config/watcher.php` only) |
| `WATCHER_REDIS_{HOST,PORT,PASSWORD,DATABASE}` | `state.redis.*` |
| `WATCHER_REDIS_CLIENT`, `WATCHER_REDIS_COMPRESS_MIN_BYTES`, `WATCHER_REDIS_PREFIX` | `state.redis.client`, `state.redis.compress_min_bytes`, `state.redis.prefix` (shipped `config/watcher.php`) |
| `WATCHER_DB_{DSN*,USERNAME,PASSWORD}` | `state.pdo.*` |
| `WATCHER_SLACK_WEBHOOK*` | `notify.digest.webhook` (Telegram and Discord: `bot_token`, `chat_id`, `webhook` the same way) |
| `WATCHER_SERVICE_CONTEXT` | `service_context` |
| `WATCHER_RELEASE*`, `WATCHER_COMMIT*` | `deploy.release`, `deploy.commit` |
| `WATCHER_GRAFANA_URL`, `WATCHER_LOKI_DS_UID` | `links.grafana.*` |
| `WATCHER_OBSERVE_TOP_GROUPS`, `WATCHER_OBSERVE_{LOG,FILE,HTTP,PROMETHEUS}_*` | `observe.*`, one variable per key above (`..._ENABLED`, `..._PATH`, `..._URL`, `..._AUTH_TOKEN`, `..._ALLOWED_HOSTS` as a comma list, ...), all sinks off by default |
| `WATCHER_DIGEST_DRIVER` | `notify.digest.driver` (`none` is dashboard-only mode; leave `WATCHER_SLACK_WEBHOOK` unset then, because a webhook under `none` is rejected) |
| `WATCHER_REPO_URL` | `repo.web_url` |

`config/watcher.php` names the models and validates on its own.
