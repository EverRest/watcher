# Contract: command line

Entry point `bin/watcher` (class `Watcher\Cli\Application`); the Laravel/Lumen adapter exposes the same options as
`watcher:run`, `watcher:check`, `watcher:status`. All commands take `--config=<file returning the config array>`
(CLI only; the adapter reads the framework config).

**Streams**: stdout carries machine output only when `--json` is given, otherwise the primary result of the command.
With `--json`, stdout is **exactly one line** (JSON object, `\n`-terminated) and every human line goes to stderr.
Neither stream ever carries a secret, a credential-bearing URL, log text or a field value.

## watcher run

```text
watcher run --config=<file> [--now=<ISO-8601>] [--dry-run [--estimate]] [--json]
```

| Option | Meaning |
|---|---|
| `--now` | unchanged: run as of this instant (strict ISO-8601) |
| `--dry-run` | full pipeline; digest and notices printed to stdout, nothing posted, no state written, no lock left, run-done record ignored |
| `--estimate` | only with `--dry-run`; no model call; prints per-step group count and token estimate and the cost at configured prices. `--estimate` without `--dry-run` is a usage error |
| `--json` | one summary line on stdout (fields in data-model.md); human text on stderr |

Exit codes (unchanged): `0` any non-failed outcome including skips; `1` `failed`; `2` usage error or invalid config.
A dry run uses the same codes and never exits non-zero for being a dry run.

Human output (stderr when `--json`, else stdout for the summary line as today):

```text
watcher: outcome=reported tokens_in=1200 tokens_out=300 tokens_cached=0 cost_usd=0.0123
```

Dry-run output on stdout (before the summary line):

```text
== DRY RUN: nothing posted, no state written. Model calls were real and billed; spend not recorded. ==
== message to digest ==
<digest text>
== message to notice ==
<notice text>
== window already reported by a real run: executed anyway ==      (only when the run record existed)
```

Estimate output:

```text
== DRY RUN --estimate: no model calls made ==
step      groups  tokens_in(est)  tokens_out(max)  cost_usd(est)
triage        42            5100              1500         0.0031
analyze        5            2300              2000         0.0410   (upper bound: all top-M)
escalate       -               -                 -              -   (up to 2 groups, not estimated)
total                                                       0.0441
```

Outside the `run_at` hour a dry run reports `outcome=skipped_not_due` and, on stderr,
`hint: pass --now=<ISO-8601 inside the run_at hour> to preview a window`.

## watcher check

```text
watcher check --config=<file> [--post-test] [--json]
```

| Option | Meaning |
|---|---|
| `--post-test` | additionally posts exactly one message per configured destination, text `watcher check: test message for <service> (<digest|notice>) - safe to ignore`. Without it nothing is posted |
| `--json` | one line `{"schema":1,"ok":bool,"checks":[{"name","status","reason"}]}` on stdout |

Checks, always all of them, in this order (each `pass`, `fail` or `skip`):

| Name | Pass condition | Fail reasons (fixed vocabulary) |
|---|---|---|
| `config` | `WatchConfig::fromArray` accepts the config | `invalid: <setting name>` (the `InvalidConfig` message, which names a setting and never a value) |
| `source` | `LogSource::totals()` over the last 5 minutes with the configured stream selector returns | `unavailable`, `unauthorised` (HTTP 401/403), `HTTP <code>` |
| `provider_auth` | the models list answers 200 with the scheme the package uses | `package scheme (Authorization: Bearer) rejected (HTTP 401) but x-api-key works: the analyzer needs a code change`; `no scheme accepted (HTTP <a>/<b>)`; `timeout`; `skip` when the provider has no catalog |
| `models` | each of `analysis.models.{triage,analyze,escalate}` is in the provider's list | `unknown model for <step>` |
| `prices` | each configured model has a price entry | `no price for <step>` |
| `state` | `put`/`get`, two `increment`s read back as the sum, `acquire` true then false then `release` | `write failed`, `read-back mismatch`, `lock not exclusive` |
| `notifier` | each webhook is an `https://` URL with a host; syntax only | `digest webhook invalid`, `notice webhook invalid` |

If `config` fails, the remaining checks are reported as `skip: config invalid` (nothing can be built) and the exit
code is 1, not 2. A check throwing any exception is a `fail` with reason `internal error (<class>)`.

Human output (stdout; stderr under `--json`):

```text
config         PASS
source         FAIL  unauthorised
provider_auth  PASS  package scheme accepted
models         PASS
prices         PASS
state          PASS
notifier       PASS
result: 1 failed
```

Exit codes: `0` all checks `pass` or `skip`-by-provider; `1` at least one `fail`; `2` usage error (unreadable
`--config` file).

## watcher status

```text
watcher status --config=<file> [--json]
```

Reads `hb:{service}` and `trig:{service}` and prints the last-run record and the staleness verdict.

```text
service:        orders-api
state:          ok
last run:       2026-10-24T06:00:41+00:00  outcome=quiet  duration=38s  cost_usd=0.0000
last ok:        2026-10-24T06:00:41+00:00  age=3h12m  limit=36h
last trigger:   2026-10-24T09:00:00+00:00
compared:       now=2026-10-24T09:12:55+00:00 against last ok 2026-10-24T06:00:41+00:00
```

`--json`: `{"schema":1,"service","state","last_run":{...heartbeat record...}|null,"age_seconds","limit_seconds","now","last_ok_at","last_trigger_at"}`.

Exit codes: `0` `ok`; `1` `stale` or `failed`; `3` `unknown` (no record, expired record or unreadable store); `2`
usage error or invalid config.

## Laravel / Lumen

| Command | Options |
|---|---|
| `watcher:run` | `{--now=} {--dry-run} {--estimate} {--json}` |
| `watcher:check` | `{--post-test} {--json}` |
| `watcher:status` | `{--json}` |

Same output and exit codes. Under `--json`, human text uses `$this->error()`/`getErrorOutput` (stderr).
