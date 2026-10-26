# Operating watcher

For the engineer who installs `watcher` into a service and keeps it running. First time: do the
[smoke run](smoke-test.md). Every setting is in [configuration.md](configuration.md); what leaves the service is in
[privacy.md](privacy.md).

## How a run works

The host triggers the package **at least once an hour**. On each trigger the package decides whether a run is due:

1. `schedule.run_at` plus 59 minutes is the due period (in `schedule.timezone`). Outside it: `skipped_not_due`, no
   external call, no state touched.
2. The window ends at the local `run_at` and covers `schedule.window` (24 h by default). A per-window lock and a run
   record make the run **idempotent**: a repeated or concurrent trigger gives `skipped_locked` / `skipped_done`.
3. It counts levels in the backend, reads lines at `warning` and above, groups and masks them, drops muted groups,
   compares with the previous window, picks candidates in code, and only then calls the model (triage, then analysis,
   then at most `escalation_cap` escalations). Nothing new means **no model call and no message**.
4. One digest goes to your notification channel (Slack, Telegram or Discord); counters and fingerprints go to the state store.

The first run for a service only records a **baseline** (no model call). Analysis starts with the second window.

**A skipped or failed day is not a new baseline.** After every successful snapshot write the run also writes
`snap:<service>:last` (the window key, same TTL as `state.retention`). When the snapshot of the exact previous window is
missing, the run compares with the pointer's snapshot if it is at most 7 days older than this window's end, and the digest
says `compared with the run of <date>, N days ago` (plus a warning when N > 1). Counts are per window and are **not scaled** for
the gap, so growth against a 2-day-old snapshot is raw: read it knowing that. Only with no snapshot at all, or one older
than 7 days, is the run a baseline.

### Baseline modes

`baseline.mode` decides what each group is compared with (see `docs/configuration.md`). `previous` (default) uses the
previous window. `rolling` uses the average of the last `windows` windows; `weekday` uses the median of the same weekday
over the last `windows` weeks, so a weekly pattern is not reported as growth. Missing windows are skipped; the digest line
says how many were used, e.g. `Compared with the median of 3 of the last 4 Wednesdays (...)`. With too little history the
run falls back to the previous window and says so (`Weekday comparison needs 3 earlier Wednesdays, found 2; ...`). A group
seen in earlier windows but absent from the comparison value shows `(expected for this weekday)` or `(seen in earlier
windows)` instead of `new`. If the window length changed, counts are compared per hour. To leave a bad day out, add its
date to `baseline.exclude`; to restart history, set `baseline.since`. The two notices `compared with the run of <date>`
and `Compared with ...` are the only places the comparison basis is shown: read them before trusting a growth arrow.

Grouping notices: `Grouping limit reached` means more distinct messages than `grouping.message_cap` arrived;
`Grouping time budget reached` means clustering stopped early. In both cases only the affected groups lose their message
text; counts, fingerprints and comparisons are unaffected. Upgrading: templates may read differently, fingerprints do not.

## Outcomes and exit codes

The command prints one line to stdout, `watcher: outcome=<outcome> [reason="..."] [tokens_in=.. tokens_out=..
tokens_cached=.. cost_usd=..]`, and failure messages to stderr as `watcher [error] ...` (messages only: no keys, URLs,
log text).

| Outcome | Exit | Meaning | Action |
|---|---|---|---|
| `reported` | 0 | Digest sent | read it |
| `quiet` | 0 | Nothing new, grown or severe (or all noise) | none |
| `baselined` | 0 | First window recorded | none |
| `skipped_not_due` | 0 | Triggered outside the due hour | none |
| `skipped_done` | 0 | This window already ran | none |
| `skipped_locked` | 0 | Another runner holds this window | none (check you do not run two schedulers) |
| `failed` | 1 | Source, analysis, delivery or state failed | see the reason and the notice in Slack |
| `invalid_config` | 2 | Config rejected before any call | fix the named key |
| `disabled` | 0 | `enabled` is not `true` (the default): nothing was touched, and the rest of the config was not even validated | set `enabled=true` / `WATCHER_ENABLED=1` where it should run |

`--estimate` without `--dry-run` is a usage error (exit 2).

A failed **analysis** keeps the window snapshot but writes no run record, so the next trigger in the same hour retries. The exception: a model **refusal** (code `analysis_refused`) and the **second** failed attempt of the same window write a `failed` run record (`no further attempts for this window`), so later triggers report `skipped_done` instead of paying for the window again; the next window starts clean.
If triage had already been paid for, its verdicts (noise / known / interesting; never findings, and never noise/known for
error-level or watch-rule groups) are kept in `triage:<service>:<window-end>` for 6 hours, so the retry in the same window
makes no second triage call. The record is cleared once the digest is delivered.
A failed **digest delivery** is only logged (the channel is the one that broke).

## Deploying

### Lumen / Laravel

```bash
composer require everrest/watcher        # from Packagist once published; before that add the GitHub repository as a VCS repository
```

Register the provider (Lumen: `bootstrap/app.php`), copy `vendor/everrest/watcher/config/watcher.php` to
`config/watcher.php` (Laravel can `vendor:publish`; Lumen has no publish command), fill in `prices` and set the
environment variables from [configuration.md](configuration.md):

```php
$app->register(Watcher\Laravel\WatcherServiceProvider::class);
```

Schedule it hourly (the package decides when it is due):

```php
$schedule->command('watcher:run')->hourly();      // app/Console/Kernel.php; also needs `schedule:run` every minute
```

`php artisan watcher:run [--now=ISO-8601]` prints the same summary line and returns the same exit codes.

### Kubernetes CronJob (any language of host app)

```yaml
apiVersion: batch/v1
kind: CronJob
metadata: {name: watcher-orders-api}
spec:
  schedule: "5 * * * *"                 # hourly; the package runs once, in the run_at hour
  concurrencyPolicy: Forbid
  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 5
  jobTemplate:
    spec:
      backoffLimit: 0
      activeDeadlineSeconds: 900
      template:
        spec:
          restartPolicy: Never
          containers:
            - name: watcher
              image: registry.example.com/orders-api-watcher:1.0.0   # your image with the package installed
              command: ["php", "vendor/bin/watcher", "run", "--config=/etc/watcher/config.php"]
              envFrom: [{secretRef: {name: watcher-secrets}}]   # WATCHER_REDIS_PASSWORD lives here
              env:
                - {name: WATCHER_STATE, value: redis}                # the default; see "Where to keep state" below
                - {name: WATCHER_REDIS_HOST, value: redis.cache.svc}
                - {name: WATCHER_RELEASE, value: "1.52.2"}           # from CI
                - {name: WATCHER_COMMIT, value: "abc1234"}
              volumeMounts: [{name: config, mountPath: /etc/watcher}]
          volumes: [{name: config, configMap: {name: watcher-config}}]
```

The ConfigMap holds the config file (no secrets: it reads `getenv()`), the Secret holds the keys. Egress needed: your
log backend, `api.anthropic.com`, the channel host (`hooks.slack.com`, `api.telegram.org`, `discord.com` / `discordapp.com`), and Redis or the database if used. A failed Job (exit 1 or 2) is
also a signal for your cluster alerting.

#### Where to keep state (Kubernetes)

Use **Redis**. It is the default driver and the only one that fits a CronJob: every run is a new pod, and what the
next run needs (baseline snapshots, the run lock, spend counters, reported markers, the heartbeat) must outlive it.

```yaml
env:
  - {name: WATCHER_STATE, value: redis}
  - {name: WATCHER_REDIS_HOST, value: redis.cache.svc}   # a Service in the cluster, or a managed Redis
  - {name: WATCHER_REDIS_PORT, value: "6379"}
  - {name: WATCHER_REDIS_DATABASE, value: "3"}           # keep it apart from the app's data
  # WATCHER_REDIS_PASSWORD comes from the Secret (envFrom)
```

Why not the default `emptyDir` (or any container-local path) with `state.driver=file`: it is deleted with the pod, so
every run starts as a first run (a baseline, no comparison, no "already reported" memory, and the daily budget counter
restarts from zero). `watcher check` reports `pass` with a `warning:` on the state line when the driver is `file`
and `KUBERNETES_SERVICE_HOST` is set, so this setup is visible before the first run.

A PersistentVolumeClaim keeps the history and is the fallback when there is no Redis, with these limits:

- **One replica, one scheduler.** The file store has no cross-process lock. `concurrencyPolicy: Forbid` and a
  `ReadWriteOnce` claim keep two pods apart on most clusters, but nothing in the package enforces it.
- The directory must be mode `0700` and owned by the pod user (a group-writable mount, as many CSI drivers create by
  default, is refused; use an init container or `fsGroup` with a `0700` subdirectory).
- A volume is per cluster and zone; a pod that is rescheduled to another zone cannot mount it.

Redis connection failures are lazy: a trigger that is not in its run hour never connects. The Redis client is `ext-redis`
when loaded, else `predis/predis` (`composer require predis/predis`, `^2.0 || ^3.0`); force one with `WATCHER_REDIS_CLIENT=phpredis|predis`.

**Redis requirements.**

- **`maxmemory-policy noeviction`** (or a `volatile-*` policy on an instance that holds only data with TTLs you accept losing). `allkeys-*` policies can evict the spend counters, the run lock and the delivered markers, which makes the budget cap and the duplicate-digest protection fail open.
- **One key prefix per environment.** Two environments (or two tenants) with the same `service` on one Redis database share locks, spend and markers. Give each its own `state.redis.prefix` (`WATCHER_REDIS_PREFIX`, default `watcher:`) or its own database.
- An injected `\Redis` must use no serializer and no compression (`SERIALIZER_NONE`, `COMPRESSION_NONE`); it is refused otherwise.
- **Size budget.** A stored value may be at most 4 MB of JSON, 100,000 elements and 16,000 arrays/objects (`StateCodec`), so the worst accepted value decodes to about 16 MB of PHP memory. Larger values are refused at write (`StateValueTooLarge`, the run fails visibly) instead of being written and read back as missing. A snapshot passes up to roughly 90,000 groups; the default `source.group_cap` is 5,000.
- **Errors fail closed.** A failed `GET` (a phpredis `false` with an error, a Predis error reply) raises and the run fails; only a missing key reads as missing.
- **Lost lock.** If the run lock is found expired or taken when it is renewed, the run stops at that checkpoint (reason `lock_lost`, host log and exit code 1, no digest). With asynchronous Redis replication a failover can lose a lock; the delivered marker still prevents a second digest for the same window.
- **Budget overshoot.** On Redis the daily budget is reserved atomically: before each model step one Lua script checks both caps (USD and tokens) and adds the step's worst case to the spend counters (with the expiry set in the same script); when the call returns, the reservation is settled to the real cost, and a call that failed before anything was billed gives it back. Parallel runs of one service therefore cannot both pass the check and overshoot the cap. Two limits remain: the file and PDO stores (and `--dry-run`) still check against what is booked so far and book afterwards, so concurrent runs can overshoot by up to one step each; and the fleet cap is checked before each step but not reserved (it is exact only because a fleet runs its services one after another in one process). The reservation script touches two keys (USD and tokens) without a shared hash tag, so it is **not Redis Cluster safe** (the script would fail with CROSSSLOT and the run would fail closed); use a single Redis instance or a primary with replicas, as the rest of the package assumes. A run killed between the reservation and the settlement leaves its worst case counted until the counter expires at the end of the day's retention (48 h), which only makes the cap stricter.

### Notification channels

`watcher check` validates each destination's shape without a network call and names only the key
(`notifier: digest bot_token invalid`); `--post-test` posts one labelled message per distinct destination. A digest
longer than the channel limit is posted as up to 10 numbered parts. If a later part fails, a rerun posts part 1 again, so
a part may appear twice. On HTTP 429 the notifier waits the stated time within `min(60, lock_ttl / 10)` seconds, then
fails with `HTTP 429`.

### Dashboard-only mode and event sinks

To read results in Grafana (or Kibana, Datadog, ...) instead of a chat channel, set `notify.digest.driver` to `none` and enable a sink; see [observability.md](observability.md). The run is otherwise unchanged. Notes for operators:

- `log` needs a shipper on the pod or host (Alloy, Promtail, Vector, Filebeat) reading stderr; `prometheus` needs node_exporter's textfile collector on a volume the job can write, which on a CronJob means a shared volume or a push-capable sidecar; `http` adds egress to the one URL you configure.
- A sink failure never changes the outcome or exit code. Look for `sink_failures` in the `--json` summary, `sink_failed:<sink>` in the next heartbeat event, `watcher_sink_failures_total` and `watcher status`.
- Alert on absence, not only on failure: no event with a successful `run` outcome for 36 h (Loki), or `time() - watcher_last_success_timestamp_seconds > 129600` (Prometheus). The dashboard cannot tell you the job never started; the alert can.
- `watcher check` verifies each sink locally; `--post-test` sends one `check_test` event. `--dry-run` prints the events and writes nothing.

### Plain cron

```cron
5 * * * *  cd /srv/watcher && php vendor/bin/watcher run --config=/etc/watcher/config.php >> /var/log/watcher.log 2>&1
```

## State

| Driver | Use when | Notes |
|---|---|---|
| `redis` | **the default**: Kubernetes, several app replicas | every key has a TTL; the lock is a compare-and-delete script; `ext-redis` or `predis/predis`; large values are compressed |
| `pdo` | you have a DB and no Redis | create the table once: `PdoStateStore::createTableSql('mysql')`; tested on SQLite only |
| `file` | one runner, one host | **never** with several replicas or pods without a shared volume and a single scheduler |

**File store:** the directory must be owned by the user running watcher and must not be world-writable unless it has the sticky bit (create it `0700`; a group-writable `0775` Laravel `storage` folder owned by that user is fine, and files are still written `0600`). A refused directory fails the run (notice `config failed: state.file.path ...`) and `watcher check`, naming `state.file.path` and never its value, symlinks inside it are never followed, and files are written `0600`. **PDO store:** keys longer than 191 characters are refused at write (the `k` column); the first write of a key retries once on a duplicate-key race. The daily budget check is read-then-book on this store, so concurrent runs of the same service can overshoot it by one step each (the lock prevents that for one window; the atomic reservation is Redis only).

**Every Redis key has a TTL, by construction.** `RedisStateStore` has one `SET` (always `PX`), the counter is a single Lua script that runs `INCRBYFLOAT` and `PEXPIRE` together (a bare `INCRBYFLOAT` would leave the key immortal), and the lock renew uses `PEXPIRE`. A TTL of zero or less is refused with an `InvalidArgumentException` that names the key kind (`run`, `spend`, ...), never the key. A test runs random put / increment / acquire / renew / release sequences against the store (and a real server when `WATCHER_TEST_REDIS` is set) and fails if any key ever exists without one. Values the store itself never writes (anything your app puts under the `watcher:` prefix) are not covered.

State holds counters, fingerprints, verdicts, reported markers, run records, spend counters and alert markers, each with
`state.retention` (30 days) except: spend and alert keys 2 days, weekly heartbeat counters 14 days, `noise`/`known` verdicts
3 days, heartbeat and trigger records 3 x `state.retention`. It never holds raw log lines.

Reset a service (forces a new baseline): change `service`, or delete its keys. Redis keys are
`watcher:<kind>:<service>:...` (kinds: `snap` (also per dependency), `verdict`, `reported`, `run`, `lock`, `spend`, `alert`, `exhausted`); file state is
a directory of hashed file names, so wipe the whole directory.

## Loki over plain http inside a private network

A plain-http Loki such as `http://loki.monitoring.svc:3100` is refused by default: `https` is required. Options, safest first: put Loki behind an
https ingress or a service mesh and use the `https` URL; reach it through a port-forward or loopback sidecar (`http://127.0.0.1:3100`) with
`source.loki.allow_insecure: true`; or, when the network between the pod and Loki is yours to trust, set `allow_insecure: true` with the
in-cluster URL (`*.svc`, `*.cluster.local`) or an RFC 1918 address. `allow_insecure` is a trust decision, never a default, and never works for a public host.

### Proxies and the internal-address check

Sources that fetch from a URL they were given (metrics, Sentry, an OpenAPI spec) refuse literal internal addresses and in-cluster names
unless the operator opts in (`SecureUrl::internalHost`; names are not resolved). Guzzle honours `HTTP_PROXY` / `HTTPS_PROXY` / `NO_PROXY` from the environment: through a
proxy the connection goes where the proxy decides, so the check does not cover it. Loki and Slack keep working behind a proxy and are not
changed; where a proxy must not be used, leave those variables unset for the process.

### Metrics and Sentry (spec 009)

`metrics` (Prometheus, or Grafana's datasource proxy) and `sentry` are optional and read-only; absent, a run is unchanged. An internal
Prometheus or Sentry is the normal case: set `allow_private_network: true` on that section for an RFC 1918 address or a cluster-local `*.svc` /
`*.cluster.local` name (default `false`, one flag per section, like `observe.http.allow_private_network`), and `allow_insecure: true` as well when it is plain
`http`. Link-local, CGNAT, metadata and reserved ranges stay refused. When a source cannot be read, the digest says so with a fixed word and nothing else changes:
`Metrics unavailable (timeout)`, `Sentry unavailable (window_too_old)` (`unreachable`, `unauthorized`, `timeout`, `bad_response`, `rate_limited`, `too_large`,
`window_too_old`). `window_too_old`: the window starts before the oldest Sentry stats bucket (24 h hourly, 14 d daily), so the count would be a fraction of the
window; use a shorter `schedule.window`. `N Sentry issues dropped` counts issues that could not be read honestly. `timeout` is also what a source reports when the
run deadline ends it. Known limit: a chunked or compressed reply without `Content-Length` is only bounded by the 60 s call budget until the streaming cap of
`ResponseLimit` lands. `watcher check` gains a `metrics` row (one `vector(1)` query) and a `sentry` row (one issue read over the last five minutes) when the section is configured and enabled; a failure is a fixed word, never a URL or token. In a fleet each service is checked and run with its own sections and tokens.

**`--dry-run` and `--estimate` read the sources.** The metrics and Sentry reads are read-only but real and credentialed, and a dry run or an estimate makes
the same ones a run does (`--estimate` makes no model call; neither posts anything or writes state). The banner says which reads were made:
`== external reads made (read-only, credentialed): metrics, sentry ==`. To avoid them, set `enabled: false` on the section.

**Ranking (spec 009).** A log group at level Error or above is never displaced by a metric-anomaly or Sentry group: log groups are selected by `top_n` exactly as
without these sources, Sentry groups get only the `top_n` volume slots the log groups leave free, and anomaly and Sentry groups rank below the lowest such log group, so the analysis budget (`top_m`, the input cut) only gives them the
slots that remain. A Sentry Error still counts as explaining a metric move, so no anomaly is raised for it.

**Metric labels in the digest.** A label value reaches the `Metrics:` line only when it is 1 to 64 characters of `A-Za-z0-9_.:-` and survives masking
unchanged; anything else (control, format or non-character code points, spaces, chat markup) is dropped, not shown masked. A ratio that is not a finite
number (a baseline near zero) prints `change not computable`; a metric problem never fails the run.
## API context (spec 010)

With `api_context` configured, a finding whose masked template names a documented route of at most 2 segments gets one `📖 GET /orders/{id} — Get an order [orders]`
line in the digest and a short `ep` field in the analysis payload. The OpenAPI description is read only when some selected template names a route, at
most once per `api_context.ttl`, and cached reduced in the state store (key `api:<service>:<source hash>-<settings fingerprint>`, always with a TTL; a
change of `summary_cap`, `base_path` or the redaction rules is a cache miss). The cache is bounded by the state codec (4 MiB, 100,000 elements, 16,000
containers, counted on the text): a document with more operations than fit keeps the first ones and the digest says `N operation(s) not kept (cache limit)`;
an entry the store still cannot hold is simply not cached and read again next run. A source that cannot be read gives one `⚠️ API context unavailable (<token>)`
note and never fails the run. `watcher check` adds an `api_context` line (`N operations kept, M not kept`, or the failure token) that bypasses the cache,
posts nothing and spends no tokens. Under `--dry-run` the source is read and nothing is cached. When the analysis input would exceed `analysis.max_input_tokens`,
the `ep` fields are the first context dropped (the digest lines stay, with a note).

## Availability guards

A run is bounded by `schedule.run_deadline`, memory (80% of `memory_limit`), 64 KB per line, 16 MB per backend reply and a Loki page/time budget. When any of them (or the event cap on a quiet run, group overflow, lines that could not be scanned, or a source that returned **nothing at all for 3 consecutive windows** while level totals are on) cuts the picture short, one short notice of counts goes to the notice destination, once per window, and the reason is in the run record, the heartbeat reason and the JSON `reason` (`truncated:deadline`, `truncated:memory`, `truncated:cursor`, `truncated:cap`, `overflow`, `oversized_lines`, `unscannable_events`, `zero_events`). `watcher status` prints it as `last warning`. A failed run posts at most 3 failure notices per window. If a digest was delivered but a state write then failed, a short-lived marker stops the next trigger from sending it again. A PHP fatal (for example memory exhausted) cannot be caught: a shutdown handler releases the lock and logs `E_ERROR` (type only) to the host logger. A `--dry-run` refuses to start when the real daily cap is already spent, and repeated dry runs in one process show cumulative cost.

A flood of one already-reported fingerprint can still fill `event_cap` with old events; the quiet-run notice tells you so, and `source.event_cap`, a narrower `stream_tags` or (Datadog) `newest_first` are the remedies.

**Newest share.** When a read is cut short by `event_cap` (or by the `pages`/`time` budget), one fifth of `event_cap` is reserved for the newest events: the oldest-first read stops at 80% of the cap, then the source reads the end of the window backwards (Loki `direction=backward`, Datadog `sort=-timestamp`) for up to the remaining 20%, starting after the last event already kept, and passes it on oldest to newest. A flood of old events therefore cannot hide today's incident. A cap below 5 has no share (plain oldest-first), `newest_first` on Datadog already keeps only the newest, and after a spent `pages`/`time` budget the newest read is limited to two pages. If the backward read reaches back to where the forward read stopped, nothing was lost and the read is not reported as truncated. Events in the unread middle are still dropped (the `truncated:cap` notice still counts them as a cut).

## Daily practice

- **Noise.** Each finding shows a 12-character fingerprint in backticks. Copy it into `mute` (with an `until` date if the
  problem is being fixed) to stop it; use a `template`/`error_type`/`field` matcher for a family. A muted group is counted
  as "N muted" and never reaches a model. A `field` matcher (`user_id equals 42`) mutes a group only if every event of
  it matches; otherwise the group stays and the digest shows `N of M events match mute #i` (index in your `mute` list).
- **Must-watch topics.** Add a `watch_rules` entry; its groups are analysed even at `warning`.
- **Reading a finding.** The "Hypothesis" line is a model guess from masked text, not a fact. The evidence line is limited
  to counts, levels, error type, frames and timestamps. Rare problems (fewer events than
  `redaction.min_group_events_for_text`) show no message text by design: use the log link or Grafana for the detail.
- **Cost.** The footer shows tokens, the model per step, the estimate and today's total against the cap. Alerts at
  25/50/75/90% come as separate notices. Before each model call the run checks that its worst case (planned input + `max_output_tokens`, at the configured prices) still fits under the daily cap, and skips the step if not (the digest counts what was skipped); at 100% the run does triage only until the next day. Cost is an estimate from
  `analysis.prices`: compare with the provider console from time to time and update prices when they change.
- **Quiet days.** No message means nothing qualified; to see that the job ran, look at the Job logs (the `outcome=` line).
  A weekly summary line is optional (`heartbeat.weekly_summary`); there is no daily heartbeat message.

## Pod events need an hourly trigger

With `events` (Kubernetes, [kubernetes.md](kubernetes.md)) every trigger reads the cluster, even outside the `run_at` hour,
because the cluster keeps events about an hour. A daily-only trigger loses most events and the digest says so ("possible gap").
`run --dry-run` reads the cluster and writes no ledger. If the cluster cannot be read the log part is reported as usual and the
digest (or, on a quiet day, one notice) says "Pod events were not read: <reason>".

## Heartbeat, staleness and alerting

Every finished run (reported, quiet, baselined, failed) stores a liveness record; a due-but-skipped trigger
(`skipped_done`, `skipped_locked`) stores a trigger time. Read it with `watcher status` (or `php artisan watcher:status`):

```text
service:        orders-api
state:          ok
last run:       2026-10-24T06:00:41+00:00  outcome=quiet  duration=38s  cost_usd=0.0000
last ok:        2026-10-24T06:00:41+00:00  age=3h12m  limit=36h
compared:       now=2026-10-24T09:12:55+00:00 against last ok 2026-10-24T06:00:41+00:00
```

`state` is `ok`, `degraded` (the last run worked but the Kubernetes pod events could not be read: `events:` shows `unavailable` or `stale`, and `reason=k8s_ingest_failed:<code>`), `stale` (last good run older than `heartbeat.stale_after`, default 1.5 x window), `failed` (last run
failed) or `unknown` (no record, expired or unreadable store; never reported as ok). Exit codes: 0 ok, 1 degraded, stale or
failed, 3 unknown, 2 bad config. A wrong host clock shows in the `compared:` line, which prints both instants.

Alert from the monitor you already have, probing every 15 minutes:

```bash
# cron on any host that can read the state: mail on non-zero exit
*/15 * * * * vendor/bin/watcher status --config=/app/watcher.php >/dev/null || echo "watcher not healthy" | mail -s "watcher" ops@example.com
```

```bash
# Prometheus: node_exporter textfile collector, written by cron every 5 minutes
vendor/bin/watcher status --config=/app/watcher.php --json 2>/dev/null \
  | jq -r '"watcher_status_age_seconds " + ((.age_seconds // -1)|tostring), "watcher_status_ok " + (if .state=="ok" then "1" else "0" end)' \
  > /var/lib/node_exporter/watcher.prom.$$ && mv /var/lib/node_exporter/watcher.prom.$$ /var/lib/node_exporter/watcher.prom
# alert: watcher_status_ok == 0 for 30m
```

## Fleet

`watcher fleet:run --config=<fleet file or directory>` runs every service of a fleet one after another over **one**
state store and **one** analyzer (the config is in [configuration.md](configuration.md#fleet-configuration), an example
in [examples/watcher-fleet.php](examples/watcher-fleet.php)).

- **Hosting**: run the fleet from a **dedicated job** (one cron entry or CronJob, hourly). A fleet started inside one of
  the watched services stops with it, so it cannot report that service's death; the services themselves need no package,
  scheduler or CLI, only a log selection and an entry.
- **Exit codes**: `0` all services non-failed (not-due, done and locked skips included); `1` a service failed, a service
  ended `skipped_time` (the `time_budget` ran out), or a fleet post failed; `2` invalid fleet (nothing runs). `--only=a,b`
  runs a subset; correlation then uses only what ran. `--json` prints one line on stdout.
- **Check and status**: `fleet:check` runs the single-service check for every service plus one shared state check, prints
  the resolved order and warns when a declared dependency is provided by no fleet service. `fleet:status` prints the
  fleet heartbeat (id `fleet/<name>`) and each service's; exit `1` degraded (pod events unread), stale or failed, `3` unknown, `0` ok. Alert on the
  fleet heartbeat as in [Heartbeat](#heartbeat-staleness-and-alerting).
- **Dry run**: `fleet:run --dry-run` prints every service's digest, the combined digest (with `digest: combined`) and the
  correlated finding; nothing is posted, no state is written, the fleet counters are untouched.
- **Budget**: the fleet cap is checked before each model call, like the service cap; the notice names `fleet cap` or
  `service cap`. Services later in the order are the ones that go triage-only.
- **Correlation limits**: a link needs a declared dependency, a `<URL:name>` / `<HOST:name>` placeholder in the consumer's
  template, an origin group that is `new` or `grown` at or above its `selection.min_level`, and overlapping group time
  ranges (plus `correlation.tolerance`). It is a hypothesis, only between services that ran in the same trigger, and
  the combined digest and the correlated message are posted after the services' run records are written: if that post
  fails the run is loud (host log, exit 1) and is **not** re-sent, but no digest loses a finding, because every consumer
  finding is delivered with its annotation first.

## Before the first run: check and dry run

- `watcher check --config=...` runs every check and prints PASS/FAIL/SKIP per line (config, source, provider_auth,
  models, prices, state, notifier). It makes read-only calls and posts nothing; `--post-test` posts one labelled test
  message per destination. Exit 0 means no failure; `--json` prints one machine line.
- `watcher run --dry-run --estimate` makes no model call and prints groups, estimated tokens and cost per step.
- `watcher run --dry-run` runs the whole pipeline for the window ending at the latest `run_at` instant (ignoring the due
  hour and the run record), prints the digest and notices, and posts and records nothing. **Model calls are real and
  billed**; spend is not recorded.
- `--json` on `run` prints one summary line on stdout (also for `failed` and `invalid_config`) and the human lines on stderr. Besides the outcome, counts and cost it carries the health counters, always present (0 when none): `state_unreadable`, `unscannable_events`, `oversized_lines`, `overflowed_events`, `pattern_failures` and `read_quality` (an object with `unknown_levels`, `orphan_trace_lines`, `message_tails_dropped`, `truncated_lines`, `truncated_structured`, `regex_failures`, `pages_shrunk`). With `metrics` or `sentry` configured the summary also carries `metrics` (`read`, `failed`, `anomalies`) and `sentry` (`read`, `dropped`, `merged`, `failed`); counts only, absent when the section is not configured. Alert on any of them rising above their usual 0; `reason` names the first cut (`truncated:*`, `overflow`, `oversized_lines`, `unscannable_events`, `zero_events`).

## Log hygiene reports (spec 006)

```bash
vendor/bin/watcher hygiene --config=config/watcher.php                 # four reports on stdout
vendor/bin/watcher hygiene --config=config/watcher.php --report=cost --days=14 --json
vendor/bin/watcher hygiene --config=config/watcher.php --notify       # weekly, from the host's cron
vendor/bin/watcher hygiene --config=config/watcher.php --decline=<id> # quiet a proposal for hygiene.decline_days
vendor/bin/watcher hygiene --config=config/watcher.php --dry-run       # read-only look: nothing written, no lock, nothing sent
```

Run it **daily** (it stores the aggregates proposals need: 14 complete days before the first proposal; a day that was sampled at the event cap or the time budget, or never ran, is missing history) and add `--notify` once a week. One pass at a time per service (a second one exits 1 `another hygiene pass is running`); a pass stops reading at `hygiene.max_seconds` and says it is sampled. A "gone" retraction needs `max(hygiene.repeat_days, 2)` complete quiet days (days that were not sampled) without the site, so a weekly job is not reported gone and new again. The pass lock lives `schedule.lock_ttl` seconds and is renewed while the pass runs when the state store can (`hygiene.max_seconds + schedule.lock_ttl + 60` seconds on a store that cannot); a failed lock release is noted (`lock release failed (<Class>)`) and never changes the result; a state failure names the failing class only (`state unavailable (<Class>)`). Even without `--notify` a pass updates its own state (day record, site registry); `--dry-run` (also `php artisan watcher:hygiene --dry-run`) is the read-only look: the writes are shadowed in memory, no lock is taken (a scheduled pass is not blocked), nothing is sent, and a `--now` more than a day ahead of the clock is refused otherwise. It reads the source once per run (one more read than `run` on those days) and calls no model. Every source driver works (`loki`, `datadog`, `kubernetes`); with `kubernetes` the pass binds the cluster ledger to its state store, and a dry run keeps that ledger in memory. Exit 0 reports produced or switched off, 1 source/state/delivery failure (a fixed reason), 2 usage or invalid config. Proposals are text: paste a fragment into `mute` / `watch_rules` yourself. Output schema: `specs/006-log-hygiene/contracts/report-schema.md`.

## Upgrading

Versions follow semver; read `CHANGELOG.md` first. A config key that was renamed or removed makes validation fail loudly
(exit 2), never silently. State written by an older version is read as long as the key names match; if a release says
otherwise, change `service` to start a new baseline.

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing ever arrives | `outcome=` line in the Job log: `skipped_not_due` means the trigger is outside the `run_at` hour; `quiet` means nothing qualified |
| Digest is empty-ish or all `<*>` | clusters below `min_group_events_for_text` send no text; a rare problem can only show error type and frames |
| What does the store hold for a window? | `watcher state:get heartbeat --config=...` (also `last-snapshot`, `snapshot:2026-10-24T06:00:00Z`, `run:2026-10-24T06:00:00Z`; Laravel `php artisan watcher:state-get heartbeat`). Read-only; every string is redacted, secrets masked and long values cut to 200 characters; any other key (a raw Redis key, a glob, another service) is refused with exit 2; exit 1 means nothing is stored |
| `skipped_locked` every time | a crashed run left a lock (it expires after `lock_ttl`, 15 min) or two schedulers are running |
| Digest says "cluster cap hit" | more distinct messages than `source.group_cap`: usually an unmasked id in a message; the overflow is counted but not analysed. Raise `group_cap` (memory grows with it) or fix the message |
| Digest says "read quality: …" | the source's parser guessed or dropped something: events whose level word it could not read (taken as info), stack-frame lines that arrived with no open record (a page or stream starting mid-trace), multi-line JSON messages cut to their first line. Counts only; Loki and Datadog report it. A large number means the log format is not what the parser expects: check the `level` field spelling or the line shape |
| Costs higher than expected | many fresh fingerprints (cache cannot help); raise `growth_factor`, lower `top_m`, mute noisy families, check `read_min_level` |
| `analysis failed: authentication failed` with a valid key | see the Bearer note in [smoke-test.md](smoke-test.md#2-prepare-the-shell) |
| Dependencies missing in a digest | declare them in `dependencies` (the digest shows only a count of undeclared call targets, never their names; a host is kept as `<URL:name>` only if it equals a declared name or is `name.<domain>` for a `dependency_domains` entry) |
| Links point nowhere | `links.*` and `repo.path_map` are config only; the Grafana Explore URL shape is not verified against every Grafana version |

## Enabling and disabling per environment

One package, one config, one switch per environment. `enabled` (`WATCHER_ENABLED`) defaults to **off**, so a deploy that
does not set it runs nothing and says so (`watcher: disabled (set enabled=true / WATCHER_ENABLED=1)` on stderr, outcome
`disabled`, exit 0). Name the environment with `WATCHER_ENVIRONMENT` so state keys do not collide
(`<service>@<environment>`); every other feature has its own `WATCHER_*_ENABLED` variable (full table: [configuration.md](configuration.md#feature-flags-spec-012)).
Use the `Watcher\Config\Env` helper in a plain-PHP config (`Env::bool('WATCHER_ENABLED', false)`); `config/watcher.php`
already does.

| Environment | Variables | Result |
|---|---|---|
| int on | `WATCHER_ENABLED=1`, `WATCHER_ENVIRONMENT=int`, `WATCHER_HEARTBEAT_WEEKLY_SUMMARY=0` | runs, keys `orders-api@int`, no weekly line |
| prod on | `WATCHER_ENABLED=1`, `WATCHER_ENVIRONMENT=prod`, `WATCHER_HEARTBEAT_WEEKLY_SUMMARY=1` | runs, keys `orders-api@prod`, weekly line |
| dev off | nothing (or `WATCHER_ENABLED=0`) | `disabled`: no source, state, model or notifier access; `watcher status` says `disabled` (exit 0); `watcher check` validates the config only |

Cheaper variants: `WATCHER_ESCALATION_ENABLED=0` (no stronger model), `WATCHER_TRIAGE_ENABLED=0` (no triage call),
`WATCHER_DIGEST_ENABLED=0` (read and snapshot only, no model calls), `WATCHER_NOTICE_ENABLED=0` (failures only in the
host log and the exit code).

Kubernetes (the same image and ConfigMap everywhere; only the environment differs):

```yaml
env:
  - { name: WATCHER_ENABLED, value: "1" }          # absent or "" = off
  - { name: WATCHER_ENVIRONMENT, value: "int" }    # state keys: orders-api@int
  - { name: WATCHER_HEARTBEAT_WEEKLY_SUMMARY, value: "0" }
  - { name: WATCHER_ESCALATION_ENABLED, value: "0" }
  - name: WATCHER_ANTHROPIC_KEY
    valueFrom: { secretKeyRef: { name: watcher, key: anthropic-key } }
```

A fleet has the same switches: fleet `enabled`, and `enabled` per entry or in `defaults` (a disabled service is reported
`disabled`, not failed). Two environments must not share a store prefix: the process cannot detect that, so give each its
own `environment` (fleet validation rejects a duplicate `service@environment`).

## Rollback / disabling

Set `WATCHER_ENABLED=0` (the run then touches nothing and works even if the config is broken or half-filled), or remove the schedule (CronJob suspend: `kubectl patch cronjob ... -p '{"spec":{"suspend":true}}'`). The package changes
nothing in your service or its logs; state can stay or be deleted.

**Rolling back to a release without Redis compression.** An older release cannot decode `z1:` values: it reads them as **missing**, with no crash.
Every value above 512 bytes (snapshots, run records, verdict blobs) is then lost for that window: a run record reads as "not run yet" (a possible
duplicate digest) and the baseline is gone (the next run baselines again). Roll back safely in this order: (1) set `state.redis.compress_min_bytes: 0`
and deploy; (2) wait one retention period (`state.retention`) so the compressed entries expire, or delete the package's keys by prefix
(`watcher:*`, or your configured prefix; scan, then delete in batches); (3) only then roll back the release. `watcher check` does not warn about this:
compression is on by default, so the warning would be on every install.
