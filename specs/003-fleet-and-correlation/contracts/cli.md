# Contract: command line (additions)

`watcher run|check|status` are unchanged. New commands take a fleet file or directory in `--config=`.
The Laravel/Lumen adapter adds `watcher:fleet` with the same options (reads the fleet array from the framework config key `watcher.fleet`).

```text
watcher fleet:run    --config=<file|dir> [--now=<ISO-8601>] [--only=<service>[,<service>...]] [--dry-run [--estimate]] [--json]
watcher fleet:check  --config=<file|dir> [--post-test] [--json]
watcher fleet:status --config=<file|dir> [--json]
```

| Command | Behaviour |
|---|---|
| `fleet:run` | validate the whole fleet, order it, run every service, post the combined digest/correlated finding. `--only` runs a subset (names must exist; correlation uses only what ran). `--dry-run`/`--estimate` are passed to every service and to the fleet posts (printed, nothing sent, no state written, fleet counters untouched) |
| `fleet:check` | the 002 `check` for each service (source, model, notifier; no spend) plus one shared state check, then prints the resolved order and any config-level warnings (e.g. a dependency name not provided by any fleet service). Fleet validation errors are reported as the failed `config` check |
| `fleet:status` | the fleet heartbeat plus each service's heartbeat; stale/failed/unknown as in 002 |

**Exit codes**: `0` all services non-failed (skips for not-due/done/locked included); `1` any service failed, any
service `skipped_time`, or a fleet post (combined/correlated) failed; `2` usage error or invalid fleet; `3` (status only)
unknown, as in 002.

**Streams**: as 002. Without `--json`: one human line per service (`bff-a: outcome=reported ...`), then
`fleet platform: services=3 failed=0 skipped_time=0 correlated=1`, on stdout. With `--json`: exactly **one** line on
stdout, human lines on stderr:

```json
{"fleet":"platform","exit":0,"services":[{"service":"api","outcome":"reported","reason":null,"cost_usd":0.0412,"findings":2},
 {"service":"bff-a","outcome":"reported","reason":null,"cost_usd":0.0,"findings":0}],
 "correlated":1,"correlated_posted":true,"cost_usd":0.0412,"fleet_cost_today_usd":0.1203}
```

Per-service objects are a subset of the 002 `JsonSummary` line (outcome, reason code, cost, findings); no group content.
Invalid fleet: one line `{"fleet":null,"exit":2,"error":"invalid_config"}` and the key path on stderr.
