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
| `disabled` | 0 | `enabled` is false (the default): nothing was touched | set `enabled=true` / `WATCHER_ENABLED=1` where it should run |

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

### Notification channels

`watcher check` validates each destination's shape without a network call and names only the key
(`notifier: digest bot_token invalid`); `--post-test` posts one labelled message per distinct destination. A digest
longer than the channel limit is posted as up to 10 numbered parts. If a later part fails, a rerun posts part 1 again, so
a part may appear twice. On HTTP 429 the notifier waits the stated time within `min(60, lock_ttl / 10)` seconds, then
fails with `HTTP 429`.

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

**File store:** the directory must not be writable by group or others (create it `0700`; a group-writable Laravel `storage` folder is refused with a message naming `state.file.path`), symlinks inside it are never followed, and files are written `0600`. **PDO store:** keys longer than 191 characters are refused at write (the `k` column); the first write of a key retries once on a duplicate-key race. The daily budget check is read-then-book, so concurrent runs of the same service can overshoot it by one step each (the lock prevents that for one service; Redis `INCRBYFLOAT` reservation is not implemented).

**Every Redis key has a TTL, by construction.** `RedisStateStore` has one `SET` (always `PX`), the counter is a single Lua script that runs `INCRBYFLOAT` and `PEXPIRE` together (a bare `INCRBYFLOAT` would leave the key immortal), and the lock renew uses `PEXPIRE`. A TTL of zero or less is refused with an `InvalidArgumentException` that names the key kind (`run`, `spend`, ...), never the key. A test runs random put / increment / acquire / renew / release sequences against the store (and a real server when `WATCHER_TEST_REDIS` is set) and fails if any key ever exists without one. Values the store itself never writes (anything your app puts under the `watcher:` prefix) are not covered.

State holds counters, fingerprints, verdicts, reported markers, run records, spend counters and alert markers, each with
`state.retention` (30 days) except: spend and alert keys 2 days, weekly heartbeat counters 14 days, `noise`/`known` verdicts
3 days, heartbeat and trigger records 3 x `state.retention`. It never holds raw log lines.

Reset a service (forces a new baseline): change `service`, or delete its keys. Redis keys are
`watcher:<kind>:<service>:...` (kinds: `snap` (also per dependency), `verdict`, `reported`, `run`, `lock`, `spend`, `alert`, `exhausted`); file state is
a directory of hashed file names, so wipe the whole directory.

## Availability guards

A run is bounded by `schedule.run_deadline`, memory (80% of `memory_limit`), 64 KB per line, 16 MB per backend reply and a Loki page/time budget. When any of them (or the event cap on a quiet run, group overflow, lines that could not be scanned, or a source that returned **nothing at all for 3 consecutive windows** while level totals are on) cuts the picture short, one short notice of counts goes to the notice destination, once per window, and the reason is in the run record, the heartbeat reason and the JSON `reason` (`truncated:deadline`, `truncated:memory`, `truncated:cursor`, `truncated:cap`, `overflow`, `oversized_lines`, `unscannable_events`, `zero_events`). `watcher status` prints it as `last warning`. A failed run posts at most 3 failure notices per window. If a digest was delivered but a state write then failed, a short-lived marker stops the next trigger from sending it again. A PHP fatal (for example memory exhausted) cannot be caught: a shutdown handler releases the lock and logs `E_ERROR` (type only) to the host logger. A `--dry-run` refuses to start when the real daily cap is already spent, and repeated dry runs in one process show cumulative cost.

A flood of one already-reported fingerprint can still fill `event_cap` with old events; the quiet-run notice tells you so, and `source.event_cap`, a narrower `stream_tags` or (Datadog) `newest_first` are the remedies. The reserved newest-share read is not implemented.

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

`state` is `ok`, `stale` (last good run older than `heartbeat.stale_after`, default 1.5 x window), `failed` (last run
failed) or `unknown` (no record, expired or unreadable store; never reported as ok). Exit codes: 0 ok, 1 stale or
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
  fleet heartbeat (id `fleet/<name>`) and each service's; exit `1` stale or failed, `3` unknown, `0` ok. Alert on the
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
- `--json` on `run` prints one summary line on stdout (also for `failed` and `invalid_config`) and the human lines on stderr.

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

Set `WATCHER_ENABLED=0` (the run then touches nothing), or remove the schedule (CronJob suspend: `kubectl patch cronjob ... -p '{"spec":{"suspend":true}}'`). The package changes
nothing in your service or its logs; state can stay or be deleted.
