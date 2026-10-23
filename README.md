# everrest/watcher

Scheduled, model-routed log analysis. Once per window (default 24 h) it reads one service's logs from
Loki or Datadog, groups errors into fingerprinted clusters, decides in plain code which ones deserve
attention, asks Anthropic models to judge only those, and posts one digest to Slack. It is read-only:
it never acts on a service, opens tickets, or stores raw log lines.

```
LogSource -> Reducer -> Redactor -> Selector -> Triage -> Analyze -> (Escalate) -> Dedupe -> Notifier
(per env)    fingerprint  allowlist  anomaly     cheap     mid        strong       StateStore  Slack
             + counters   + normalise rules(code) model     model      model, once
             + vs yesterday
```

Nothing new to report means zero model calls. The first run only records a baseline.

## Requirements

- PHP ^8.1.
- Optional extensions: `ext-redis` (Redis state), `ext-pdo` plus a driver (PDO state).
- A Slack incoming webhook, an Anthropic API key, read-only credentials for Loki or Datadog.
- Lumen/Laravel is optional (`illuminate/support`, `illuminate/console` ^10 or ^11); the core has no framework dependency.

## Install

```bash
composer require everrest/watcher
```

The repository is not public yet. Until it is, add it as a VCS repository to your `composer.json` first:

```json
{ "repositories": [{ "type": "vcs", "url": "<url of the EverRest/watcher repository>" }] }
```

## Quick start

1. Copy `config/watcher.example.php` into your service and fill in the environment variables it reads.
   It returns a plain PHP array.
2. Run it:

   ```bash
   vendor/bin/watcher run --config=path/to/watcher.php          # add --now=2026-10-23T06:00:00Z to run "as of" an instant
   ```

3. Lumen/Laravel: the service provider (`Watcher\Laravel\WatcherServiceProvider`) registers the
   `watcher:run` command and merges `config/watcher.php`, which reads `WATCHER_*` environment variables.
   Register the provider yourself (Lumen: `$app->register(Watcher\Laravel\WatcherServiceProvider::class)` in
   `bootstrap/app.php`; Laravel: `config/app.php`; the package declares no auto-discovery). Laravel can publish the
   config with `php artisan vendor:publish --tag=watcher-config`. Then schedule it hourly:

   ```php
   $schedule->command('watcher:run')->hourly();
   ```

## Configuration

`config/watcher.example.php` is the complete plain-PHP reference: every key with its default and a one-line
comment. `config/watcher.php` is the same surface for Lumen/Laravel, driven by environment variables.
The contract is `specs/001-scheduled-log-watch/contracts/configuration.md`.

Configuration is validated once, before any network call. A bad value throws `InvalidConfig` naming the key
(never the value), and the process exits with code 2. Unknown top-level keys are rejected.
Required: `service`, `schedule.run_at`, a source driver with its credentials, `analysis.api_key`, all three
`analysis.models`, a price for each of those models, a state driver with its settings, `notify.digest.webhook`.

## Log sources

Use **read-only** credentials. The package only issues query requests (enforced by `tests/Unit/ArchitectureTest.php`).

| Driver | Requests | Credential |
|--------|----------|------------|
| `loki` | `GET /loki/api/v1/query`, `GET /loki/api/v1/query_range` | Bearer token or basic auth with query-only rights; optional `X-Scope-OrgID` tenant. `url` may be a Loki base URL or a Grafana datasource proxy URL. |
| `datadog` | `POST /api/v2/logs/analytics/aggregate`, `POST /api/v2/logs/events/search` (read-only queries sent as POST) | API key plus an application key limited to the `logs_read_data` scope. Set `site` (e.g. `datadoghq.eu`). |

`source.stream_tags` selects which logs belong to the service. Only levels at or above `source.read_min_level`
are read as events; lower levels are counted in totals only.

## State

State holds fingerprints, counters, cached verdicts, spend and run records. Every item has an expiry
(`state.retention`, default 30 days).

| Driver | Notes |
|--------|-------|
| `redis` | Needs `ext-redis`. Pass `host/port/password/database`, or inject a connected `\Redis` as `state.redis.instance`. |
| `pdo` | Any PDO DSN. The table is **never created implicitly**: run `Watcher\State\PdoStateStore::createTableSql($driver, $table)` once (sqlite, mysql, pgsql) in a migration. |
| `file` | **Single runner only.** No cross-process guarantees; use it for one host or development. |

## Scheduling

The host triggers the command at least hourly. The package decides whether a trigger is the scheduled one: a run
is due from `schedule.run_at` (in `schedule.timezone`) for one hour. Outside that hour the trigger exits 0 at no cost.
Inside it, a lock (`schedule.lock_ttl`) and a per-window run record make the run idempotent: a second trigger in the
same window does nothing, and a failed run leaves no record so the next trigger retries.

## Privacy

Enforced in code, not by the model. The model never redacts and never chooses its own input.

- Only allowlisted fields are sent: level, normalised message template (numbers, ids, emails, tokens, hosts become
  placeholders such as `<N>`, `<ID>`, `<EMAIL>`), error type, at most 5 stack frames, counts and timestamps.
- Clusters smaller than `redaction.min_group_events_for_text` (default 3) send no message text, because small
  clusters are the ones most likely to hold a single person's data.
- Fail closed: any redaction error, unknown field or unparseable value is dropped, never passed through.
  Add your own patterns with `redaction.extra_patterns`.
- Model output is scrubbed again before it is cached or shown.
- No raw log line is persisted. Field values used by `watch_rules` and `mute` are matched in memory and dropped.

What leaves the service: (1) to Anthropic, the compact payload above plus `service_context` (up to 1000 chars,
written by you); (2) to Slack, the digest (titles, evidence, hypotheses, counts, short fingerprints, cost line) and
failure/budget notices; (3) queries to your log backend. Nothing else is sent anywhere.

## Cost model

Work is routed by tier and each tier has its own model id, token limits and price (no ids in code):

1. **triage** (cheap model): one batched call over the selected candidates; verdict per fingerprint.
2. **analyze** (mid model): only groups triage marked interesting, at most `selection.top_m`.
3. **escalate** (strong model): only for error-or-worse findings with confidence below `analysis.confidence_threshold`,
   once per finding, at most `analysis.escalation_cap` per run.

Selection is deterministic: new fingerprints, groups that grew by `selection.growth_factor`, level at or above
`selection.min_level`, watch-rule matches, plus the top `selection.top_n`; the rest collapse to one summary line.
No candidates means zero model calls; unchanged groups reuse cached verdicts. Inputs over `max_input_tokens` drop
the lowest-priority groups and the digest says so.

`analysis.prices` is required for every model (`[input, output, cache_read, cache_write]`, USD per million tokens).
Spend is metered per call. At `daily_budget_usd` (and optional `daily_token_limit`) the run falls back to triage only
and posts a "budget exhausted" notice. Alerts post at `budget_alert_percents` (default 25/50/75/90), once each per day.
Every digest ends with a cost line: tokens in/out/cached, model per step, approximate cost, spend today.

## Noise control

- `mute`: by fingerprint or matcher (`template`, `error_type`, `field`), optionally until a date. Muted groups never
  reach a model; the digest shows a count.
- `watch_rules`: named matchers that raise a matching group's priority; only the rule name leaves the service. Plain-literal `template` rules and RE2-safe `error_type` rules are also sent to the log backend as search filters, so a matching line below `read_min_level` is still read; other rules (non-literal templates, `field`) are matched only on the lines that were read.
- Dedupe: a group already reported is omitted unless it grew again.

## Failures and exit codes

| Code | Meaning |
|------|---------|
| 0 | Success, including "not due", "already done", "locked", baseline and quiet runs |
| 1 | Run failed (source unavailable, analysis failed, delivery failed, internal error) |
| 2 | Invalid configuration, bad arguments or bad `--now` |

Failures are never silent: a source, analysis or internal failure posts a short notice to `notify.notice`
(or the digest destination) and returns 1. If the notice channel itself fails, the host logger (if you pass one to
`Watcher::fromConfig()`) and the exit code carry it. Notices state a reason and never include secrets or values.

## Digest

One message per service per run: a header (service, environment, window), findings grouped by severity
(critical, error, warn, info), then a line for minor/low-confidence findings, remaining groups, muted count, truncation
notices and a cost footer. A finding shows title, event count and trend (`new` or `up from N`), a 12-character
fingerprint, evidence, a hypothesis (always labelled as such) and a suggested next step.

## Documentation

[docs/](docs/README.md): [first live smoke run](docs/smoke-test.md), [operating it](docs/operations.md),
[configuration reference](docs/configuration.md) and [what leaves the service](docs/privacy.md).

## Known limits

- The Grafana Explore URL shape and the Datadog response shapes are not verified against live systems; sources are
  tested against recorded fixtures only.
- The `Authorization` header and request format the Anthropic SDK sends were not verified against the live API.
- At most `source.group_cap` (5000) distinct problem groups are held per run; on a day with more distinct messages the rest
  is grouped as one "other" group per level and the digest says so. Watch-rule matches are exempt up to twice the cap.
- Cluster templates are coarse; two different problems with a similar message shape can share a fingerprint.
- Field values never leave the service, so `field` matchers can match but cannot be explained to the model.
- Dependencies are read with the service's own log source: `dependencies.<name>.source` (a different backend per
  dependency) is rejected as not available yet. A dependency's lines are read before triage, so a day on which triage
  marks everything noise can still have spent the dependency reads (totals are always one query per dependency).
- A failed alert notice releases its claim, but an alert is only announced again when later spend crosses that
  threshold again or on the next day.
- Loki's server-side filter works per physical line, so stack-trace lines are not returned and frames come from the
  head line of a record; a record that straddles a Loki page boundary stays split.
- The suite passes on PHP 8.1, 8.3, 8.4 (official Docker images) and 8.5; the GitHub Actions matrix in `.github/workflows/ci.yml` has not run yet because the repository has no remote.
- Only Slack, Anthropic, Loki, Datadog and the three state drivers exist. There is no remediation and no ticketing.

## Development

```bash
composer install
composer test    # PHPUnit, no network
composer stan    # PHPStan
composer style   # Pint (--test)
```

Development is spec-driven: `.specify/memory/constitution.md` (principles), `docs/brief.md` (origin),
`specs/001-scheduled-log-watch/` (spec, plan, contracts, quickstart, tasks). A behaviour change starts with a failing
test; a change to what leaves the service extends the privacy corpus (`tests/Fixtures/privacy`).
`tests/Unit/ArchitectureTest.php` guards: no model ids, no consumer names, read-only sources, no framework in the core.
