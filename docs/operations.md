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
4. One digest goes to Slack; counters and fingerprints go to the state store.

The first run for a service only records a **baseline** (no model call). Analysis starts with the second window.

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

A failed **analysis** keeps the window snapshot but writes no run record, so the next trigger in the same hour retries.
A failed **digest delivery** is only logged (the channel is the one that broke).

## Deploying

### Lumen / Laravel

```bash
composer require everrest/watcher        # add the repository to composer.json first; the package is not on Packagist
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
              envFrom: [{secretRef: {name: watcher-secrets}}]
              env:
                - {name: WATCHER_RELEASE, value: "1.52.2"}           # from CI
                - {name: WATCHER_COMMIT, value: "abc1234"}
              volumeMounts: [{name: config, mountPath: /etc/watcher}]
          volumes: [{name: config, configMap: {name: watcher-config}}]
```

The ConfigMap holds the config file (no secrets: it reads `getenv()`), the Secret holds the keys. Egress needed: your
log backend, `api.anthropic.com`, `hooks.slack.com`, and Redis or the database if used. A failed Job (exit 1 or 2) is
also a signal for your cluster alerting.

### Plain cron

```cron
5 * * * *  cd /srv/watcher && php vendor/bin/watcher run --config=/etc/watcher/config.php >> /var/log/watcher.log 2>&1
```

## State

| Driver | Use when | Notes |
|---|---|---|
| `redis` | normal choice, several app replicas | every key has a TTL; the lock is a compare-and-delete script |
| `pdo` | you have a DB and no Redis | create the table once: `PdoStateStore::createTableSql('mysql')`; tested on SQLite only |
| `file` | one runner, one host | **never** with several replicas or pods without a shared volume and a single scheduler |

State holds counters, fingerprints, verdicts, reported markers, run records, spend counters and alert markers, each with
`state.retention` (30 days; spend and alert keys 2 days). It never holds raw log lines.

Reset a service (forces a new baseline): change `service`, or delete its keys. Redis keys are
`watcher:<kind>:<service>:...` (kinds: `snap` (also per dependency), `verdict`, `reported`, `run`, `lock`, `spend`, `alert`, `exhausted`); file state is
a directory of hashed file names, so wipe the whole directory.

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
  25/50/75/90% come as separate notices. At 100% the run does triage only until the next day. Cost is an estimate from
  `analysis.prices`: compare with the provider console from time to time and update prices when they change.
- **Quiet days.** No message means nothing qualified; to see that the job ran, look at the Job logs (the `outcome=` line).
  There is no heartbeat message.

## Upgrading

Versions follow semver; read `CHANGELOG.md` first. A config key that was renamed or removed makes validation fail loudly
(exit 2), never silently. State written by an older version is read as long as the key names match; if a release says
otherwise, change `service` to start a new baseline.

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing ever arrives | `outcome=` line in the Job log: `skipped_not_due` means the trigger is outside the `run_at` hour; `quiet` means nothing qualified |
| Digest is empty-ish or all `<*>` | clusters below `min_group_events_for_text` send no text; a rare problem can only show error type and frames |
| `skipped_locked` every time | a crashed run left a lock (it expires after `lock_ttl`, 15 min) or two schedulers are running |
| Digest says "cluster cap hit" | more distinct messages than `source.group_cap`: usually an unmasked id in a message; the overflow is counted but not analysed. Raise `group_cap` (memory grows with it) or fix the message |
| Costs higher than expected | many fresh fingerprints (cache cannot help); raise `growth_factor`, lower `top_m`, mute noisy families, check `read_min_level` |
| `analysis failed: authentication failed` with a valid key | see the Bearer note in [smoke-test.md](smoke-test.md#2-prepare-the-shell) |
| Dependencies missing in a digest | declare them in `dependencies` (the digest lists undeclared hosts it saw) |
| Links point nowhere | `links.*` and `repo.path_map` are config only; the Grafana Explore URL shape is not verified against every Grafana version |

## Rollback / disabling

Remove the schedule (CronJob suspend: `kubectl patch cronjob ... -p '{"spec":{"suspend":true}}'`). The package changes
nothing in your service or its logs; state can stay or be deleted.
