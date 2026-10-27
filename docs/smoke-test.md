# First live smoke run (T114)

Everything in the test suite runs against fakes and recorded fixtures. This guide is the first time `watcher` talks to
real systems: your log backend, the Gemini API (or OpenAI) and Slack. It takes about 45 minutes and makes a few model calls. It is read-only for your logs and posts only to a Slack channel you choose.

## Other channels (spec 011)

To smoke-test Telegram or Discord, set `notify.digest` to that driver (see `docs/configuration.md`), then: 1. run
`watcher check --post-test` and see one labelled test message per distinct destination; 2. run `watcher run` over a day
with enough findings for a long digest and see numbered parts `(1/2)`, `(2/2)`; 3. set a wrong token or webhook and see
`telegram delivery failed: HTTP 401` or `discord delivery failed: HTTP 404` with no credential in any output.

## What "passed" means

| # | Check | Pass |
|---|---|---|
| 1 | Config loads, nothing external is called | `outcome=skipped_not_due`, exit 0 |
| 2 | Baseline run on real logs | `outcome=baselined`, one Slack notice, no model call, exit 0 |
| 3 | Analysis run on the next window | `outcome=reported` (or `quiet` with a reason you can verify) |
| 4 | Privacy | no personal value in the Slack message or in the state directory |
| 5 | Idempotency | the same run again gives `outcome=skipped_done` |
| 6 | Findings | the findings count in the digest matches the `findings_total` of the run record |
| 7 | Usefulness (SC-001) | the digest finds at least 80% of the High/Medium items you can see by hand |

If any check fails, stop and see [If something fails](#if-something-fails).

## 1. What you need

| Need | Where to get it | Environment variable |
|---|---|---|
| Checkout of this repo with dependencies | `cd ~/code/watcher && composer install` (PHP 8.2 or newer) | n/a |
| Gemini API key | Google AI Studio > Get API key > create a key for this test. Set a usage limit on its Google Cloud project first. | `WATCHER_GEMINI_KEY` |
| Three model ids (cheap / mid / strong) | Console model list. Triage = smallest, analysis = middle, escalation = strongest. | `WATCHER_MODEL_TRIAGE`, `WATCHER_MODEL_ANALYZE`, `WATCHER_MODEL_ESCALATE` |
| Read-only Loki access | Grafana > Administration > Users and access > Service accounts > new account with role **Viewer** > add token. | `WATCHER_LOKI_TOKEN` |
| Loki base URL | Through Grafana: `https://<grafana-host>/api/datasources/proxy/uid/<datasource-uid>` (the uid is in the data source's address bar). Direct Loki: its own base URL. | `WATCHER_LOKI_URL` |
| Slack incoming webhook for a **private test channel** | api.slack.com/apps > Create New App > From scratch > Incoming Webhooks > on > Add New Webhook to Workspace > pick the channel. | `WATCHER_SLACK_WEBHOOK` |
| The service to watch | The Loki label that selects it, e.g. `app=orders-api`: Grafana > Explore > label browser. | `WATCHER_SERVICE` (also used as the `app` label in the sample config) |

Grafana Cloud Loki or a multi-tenant Loki needs basic auth and/or a tenant header instead of a bearer token: edit
`source.loki.auth` / set `WATCHER_LOKI_TENANT` (see [configuration.md](configuration.md)). For Datadog switch
`source.driver` to `datadog` and use the keys listed there; the steps below stay the same.

**Do not paste secrets into chat, tickets or the results template.** Keep them in a file that is not committed.

## 2. Prepare the shell

Create `~/watcher-smoke.env` (outside the repo; `chmod 600`):

```bash
WATCHER_ENABLED=1
WATCHER_SERVICE=orders-api
WATCHER_LOKI_URL=https://grafana.example.com/api/datasources/proxy/uid/abc123
WATCHER_LOKI_TOKEN=...
WATCHER_GEMINI_KEY=...
WATCHER_SLACK_WEBHOOK=https://hooks.slack.com/services/...
WATCHER_MODEL_TRIAGE=...
WATCHER_MODEL_ANALYZE=...
WATCHER_MODEL_ESCALATE=...
WATCHER_STATE_PATH=/tmp/watcher-smoke
WATCHER_SERVICE_CONTEXT="One or two sentences: what the service does and what is critical."
```

Load it in each terminal you use: `set -a; . ~/watcher-smoke.env; set +a`.

### Verify the credentials before running watcher

After step 3 (config) run `watcher check` instead of hand-written curl calls:

```bash
php bin/watcher check --config=docs/examples/smoke.config.php
```

It checks the Loki query, the state store and the webhook syntax, and prints PASS/FAIL/SKIP per line. `provider_auth`
and `models` show SKIP: no provider has a model list in this release, so the key and the model ids are checked by the
first real run only. Add `--post-test` to post one labelled message to Slack.

Fallback when `check` itself is the problem (free, read-only):

```bash
curl -s -H "Authorization: Bearer $WATCHER_LOKI_TOKEN" "$WATCHER_LOKI_URL/loki/api/v1/labels"
curl -s -o /dev/null -w "gemini key: %{http_code}\n" "https://generativelanguage.googleapis.com/v1beta/models" \
  -H "x-goog-api-key: $WATCHER_GEMINI_KEY"
```

## 3. Config

Start from [`docs/examples/smoke.config.php`](examples/smoke.config.php): Loki, file state in `/tmp/watcher-smoke`, a
one Slack channel. Copy it next to your env file and change only:

- `source.stream_tags`: the labels that select your service in Loki (default `['app' => $WATCHER_SERVICE]`);
- `schedule.timezone` if you want `run_at` in local time (default UTC; `run_at` is `06:00`).

`run_at` matters for the next steps: a run is **due only from `run_at` to `run_at` + 59 minutes** (in that timezone).
`--now` lets you choose the moment, so you can run on historical windows.

## 4. Check 1: the config loads and nothing is called

`watcher check` (above) covers this. To also preview a run without posting anything:

```bash
php bin/watcher run --config=docs/examples/smoke.config.php --dry-run --estimate   # no model call
php bin/watcher run --config=docs/examples/smoke.config.php --dry-run              # real calls, digest printed, nothing posted
```

The manual not-due run below still shows the exit-code behaviour:

```bash
cd ~/code/watcher
php bin/watcher run --config=docs/examples/smoke.config.php --now=2026-10-22T05:00:00Z
```

Expected (05:00 is before `run_at`):

```
watcher: outcome=skipped_not_due
```

exit code 0, and the state directory is not created. The config was parsed and validated, and no request left the
machine. If the config is wrong you get exit 2 and a line naming the key, for example:

```
invalid config: source.loki.url is required
```

## 5. Check 2: the baseline

Pick **two consecutive days that fall inside your Loki retention**. Example below uses 22 and 23 October; replace the
dates. With `run_at=06:00`, `window=PT24H`:

- run A at `2026-10-22T06:00:00Z` reads 21 Oct 06:00 -> 22 Oct 06:00;
- run B at `2026-10-23T06:00:00Z` reads 22 Oct 06:00 -> 23 Oct 06:00 and compares with A.

```bash
php bin/watcher run --config=docs/examples/smoke.config.php --now=2026-10-22T06:00:00Z
```

Expected:

```
watcher: outcome=baselined
```

and in Slack: `baseline recorded: N groups, M events`. No model is called on the first run. `N` is the number
of distinct problem groups and `M` the number of events in them: events at level `warning` or above (the `read_min_level`)
that were read for that window. Sanity-check `M` against Grafana (Explore, same labels, same range, a line filter for
warning/error/critical levels); it should be close, and never larger than the window's real count.

## 6. Check 3: the analysis

```bash
php bin/watcher run --config=docs/examples/smoke.config.php --now=2026-10-23T06:00:00Z
```

Expected one of:

- `watcher: outcome=reported ...` and a digest in Slack. This
  is the case you want. Read it: every finding has a title, a 12-character fingerprint, evidence, a line that starts
  with "Hypothesis:", a suggested step,  Links appear only if you
  configured `links`/`repo`.
- `watcher: outcome=quiet`: nothing was new, grown or severe enough (or everything triage judged to be noise). Verify
  by hand that this is true for that window. If you expected findings, lower `selection.min_level` to `warning` in the
  config copy (add `'selection' => ['min_level' => 'warning']`), delete the state directory (see [Cleanup](#cleanup)) and
  repeat checks 2 and 3.
- `outcome=failed ...`: see below.

Output channels: **stdout** gets the one `watcher: ...` line; **stderr** gets `watcher [error] ...` lines for failures
(messages only: never keys, URLs or log text). Exit codes: `0` ok, `1` failed, `2` invalid config.

## 7. Check 4 and 5: privacy and idempotency

Privacy (this is the part that matters most):

```bash
# 1. The state directory holds hashed file names and counters/templates, never raw log lines.
ls /tmp/watcher-smoke | head
# 2. Search it and the Slack message for personal values you KNOW are in those logs
#    (a customer email domain, a name, a phone prefix, a token prefix).
grep -rIl "example-customer.com" /tmp/watcher-smoke   # expect no output
```

Search the digest text in Slack for the same strings: expect none. The only free text in Slack is wording the model
wrote from masked templates. If a personal value appears anywhere, **stop and report it with the exact place**, without
pasting the value.

Idempotency:

```bash
php bin/watcher run --config=docs/examples/smoke.config.php --now=2026-10-23T06:00:00Z
# watcher: outcome=skipped_done
php bin/watcher run --config=docs/examples/smoke.config.php --now=2026-10-23T06:59:59Z
# watcher: outcome=skipped_done
```

## 8. Check 6: findings

The digest's finding count (the number of finding sections) should equal `findings_total` in the `reported` run record. The run record carries no token or cost figure.

## 9. Optional failure drills (5 minutes)

Each should fail loudly, with the reason in stdout and a notice in Slack, and without leaking a secret:

| Break | Run | Expect |
|---|---|---|
| Wrong Loki token | any in-window `--now` | `outcome=failed reason="source failed: loki 401 ..."`, exit 1 |
| Wrong Slack webhook | a run that would post | exit 1, stderr `slack delivery failed: HTTP 404`, **no URL** in any output |
| Wrong model id | analysis run | `analysis failed: ...`, exit 1, the state shows the window snapshot but no run record, so the next trigger retries |

## 10. Check 7: compare with your manual list (SC-001)

If you keep a hand-made backlog for the same window (for example `docs/context/logs/backlog.md`), fill in:

| Manual item | Severity | Visible in app logs? | In the digest? |
|---|---|---|---|
| ... | High/Med | yes/no | yes/no |

Pod-level items (CrashLoopBackOff, HPA errors, probes) are **not in application logs**; mark them "no" in the third
column and exclude them from the score: they need the Kubernetes-events source (roadmap spec 003). Coverage =
(digest hits) / (items visible in app logs). Target: **80% or more** of High/Medium.

## 11. Results to send back

Paste this (no secrets, no personal values):

```
Date/time, window A / window B:
Backend (Loki/Datadog), service labels:
Check 1 outcome line:
Check 2 outcome line + groups/events from the Slack notice:
Check 3 outcome line + findings count:
Gemini key test (HTTP code of the models list):
Privacy check: any personal value found? (where, no value)
Idempotency: skipped_done? yes/no
SC-001: visible items N, found M, coverage %:
Anything surprising in the digest (noise, missing context, wrong severity):
```

Also add a short entry to `CHANGELOG.md` under `## Unreleased` ("Verified against ...").

## If something fails

| You see | Likely cause | What to do |
|---|---|---|
| `invalid config: <key> ...` | missing/typo'd value | fix the named key; unknown top-level keys are rejected on purpose |
| `source failed: loki 401/403 ...` | token or proxy path wrong | repeat the `curl .../labels` test; for Grafana the base URL must end at `/uid/<uid>` (no `/loki`) |
| `source failed: loki 400 ...` | label does not exist or line filter | check `stream_tags` against the label browser |
| `loki request failed (ConnectException)` | network/VPN/DNS | reach the host from this shell |
| `analysis failed: authentication failed` | key wrong, or the Bearer question above | rerun the two `curl /v1/models` tests |
| `analysis failed: provider unavailable` | 5xx/529/timeouts after retries | retry later; the next trigger retries the window automatically |
| `analysis failed: invalid model output` | the model returned unusable JSON twice | report with the model ids used |
| `slack delivery failed: HTTP 404/403` | webhook revoked or wrong channel | regenerate the webhook |
| `outcome=skipped_not_due` when you expected a run | `--now` outside `run_at`..`run_at`+59 min in the config timezone | move `--now` into the hour |
| `outcome=skipped_done` when you want to repeat | the window already ran | wipe the state, or use another `service` name |
| `outcome=quiet` but you expected findings | nothing new/grown, or `min_level` too high | see check 3 |

## Cleanup

```bash
# the state is only files under WATCHER_STATE_PATH
ls /tmp/watcher-smoke && rm -r /tmp/watcher-smoke
```

Revoke the smoke Gemini key and the Slack webhook if they were created only for this test, and keep the env file
out of version control.
