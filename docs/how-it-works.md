# How the watcher works (map)

One page: where each part runs, where its configuration lives, and what decides what gets sent. Details and every key are in
[configuration.md](configuration.md); this page says which file to look at.

## Where it runs

- The watcher is the Composer package `everrest/watcher`, built into the m-seq image. It runs inside the **`m-seq-schedule`**
  container (`artisan schedule:run`), not in its own deployment.
- Pinned in `m-seq/app/composer.json`. Changing the version means a new m-seq image.

## Where the configuration lives (three places)

| Layer | File | What it decides |
|---|---|---|
| 1. Values | Helm values per namespace: `aks-ew-stg-we/namespaces/ew-test11/m-seq-values.yaml`, `it-devs/namespaces/evo-core/m-seq-values.yaml.gotmpl` | The `WATCHER_*` environment variables for that namespace |
| 2. Assembly | `m-seq/app/config/watcher.php` | Reads the env and builds the array the package validates. Returns the single-service config plus `fleet` |
| 3. Rules | `watcher-immediate-alerts/docs/configuration.md`, `src/Config/Catalog/*Keys.php` | Each key: default, type, validation, meaning |

Secrets (`WATCHER_LOKI_TOKEN`, `WATCHER_GEMINI_KEY`, `WATCHER_TG_TOKEN`, Redis passwords) come from a Kubernetes Secret, not from values.

To change behaviour: change the env value in layer 1. Change layer 2 only when a new env name has to be read.

## What runs, and when

| What | Trigger | Config | Sends |
|---|---|---|---|
| Daily run (digest) | `WATCHER_RUN_AT` (for example `06:00`) in `WATCHER_TIMEZONE` | `schedule`, `source`, `analysis` | Digest and immediate alerts via `notify`, only on `notify.days` |
| Fleet | `WATCHER_FLEET_ENABLED=1` | `fleet` section: one service per `m-seq`, `m-seq-schedule`, workers | Per-service digests |
| Health check | Hourly, `watcher:health` | `health_check` | Version-change alerts, one summary a day at `summary_at`, only on `summary_days` |
| Health writer (m-seq) | Hourly, `health:check-services` in m-seq's Kernel | `m-seq/app/config/services.php` (`healthCheck`, per-service `health` blocks) | Writes the snapshot `{APP_NAME}:health` to m-seq's Redis |
| Read-only MCP | On demand, `watcher:mcp` over stdio | `mcp` section | Nothing: status, reports, docs |

The health check does not measure health itself. m-seq writes the snapshot, and the watcher reads it.

## Redis: three different roles

| Redis | Who uses it | Config key |
|---|---|---|
| Watcher state (db `3` by default, prefix `watcher:<env>:`) | Watcher: run state, digests, health copies | `state.redis`, `WATCHER_REDIS_*` |
| m-seq app (db `0`) | m-seq writes `{APP_NAME}:health` here | `health_check.source`, `WATCHER_HEALTH_REDIS_*` |
| Health source key | Watcher reads the snapshot | `health_check.source.key`, `WATCHER_HEALTH_KEY` |

## Which switch controls what

- `WATCHER_ENABLED`: master switch for the whole watcher. Off means nothing runs.
- `WATCHER_HEALTH_ENABLED`: the health check only. Off by default.
- `WATCHER_NOTIFY_DAYS` (for example `mon,tue,wed,thu,fri`): days the digest and immediate alerts may be sent. Unset means every day. Dropped messages are not sent later. Failure notices, probes and the heartbeat are not affected.
- `health_check.summary_days` (default `mon..fri`): days health messages may be sent, including version alerts. The health zone is `health_check.timezone` (CET in m-seq: `WATCHER_HEALTH_TIMEZONE=Europe/Berlin`).
- `WATCHER_OBSERVE_LOG`: writes findings as JSON lines to stderr for Loki.

## Traps we have already hit

1. **The package default timezone is UTC.** `health_check.timezone` defaults to `UTC` in package 0.13.1. Without `WATCHER_HEALTH_TIMEZONE`, a CET deployment gets the summary at 09:00 UTC.
2. **`notify.days` is per service.** A fleet rejects it at fleet level (`Unknown configuration key: fleet.notify.days`). It goes into `defaults` (m-seq `watcher.php`).
3. **Older packages reject new keys.** Package `^0.10` refuses `notify.days`. The m-seq config and the composer constraint must change together.
4. **`summary_every_hours` was removed in 0.13.1.** A config that still sets it fails with an error, which is intended.
5. **Two places show the same thing.** The watcher's `docs/configuration.md` describes the package. m-seq's `config/watcher.php` decides what this deployment sets. When they disagree, layer 2 wins for that deployment.

## Run it by hand, from the server or from an MCP client

Read-only MCP tools (`watcher_status`, `watcher_latest_report`, ...) are in the `inspect` group and always safe.
The `actions` group runs the same artisan commands with parameters. It is off unless `mcp.groups` names it, or
`WATCHER_MCP_GROUPS` includes `actions`.

| MCP tool | Runs | Parameters | Needs `confirm` |
|---|---|---|---|
| `watcher_run_digest` | `watcher:fleet` (or `watcher:run` with `fleet: false`) | `dry_run` (default true), `now` (ISO-8601), `only` (fleet services), `fleet` (default true) | only when `dry_run` is false |
| `watcher_health_check` | `watcher:health` | none | yes |
| `watcher_test_message` | `watcher:check --post-test` | none | yes |

- A dry run posts nothing and records nothing. It still calls the model, so it costs tokens.
- A real run sends through `notify.days`: outside those days the digest is dropped, as it is for the schedule.
- Each action runs at most once per 5 minutes per MCP session, and every run is logged as `watcher [audit] <tool> exit=<code>`.
- The child process gets its arguments as a list, never through a shell. Only the exit code and the JSON line come back, not the digest text.

The same commands on the console: `php artisan watcher:fleet --dry-run`, `php artisan watcher:health`,
`php artisan watcher:check --post-test`.

## Where to look when something is wrong

- Is it running at all: `watcher:status` (the host's `watcher_status` MCP tool gives the same).
- Last digest: `watcher_latest_report` (MCP) or `watcher:state-get`.
- Config errors: `watcher:check` (add `--post-test` to send one labelled test message per destination).
- Health snapshot: read `{APP_NAME}:health` in m-seq's Redis (db 0); the watcher logs `health:` lines in m-seq's scheduler pod.
- Scheduler logs: `kubectl logs` for the `m-seq-schedule` deployment in the namespace.
