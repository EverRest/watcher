# Contract: weekly report

Built by `watcher report` from `watcher.event.v1` lines of the `observe.file` sink (spec 013, contracts/event-schema.v1.md).

## Input read (allowlist; everything else is dropped and counted)

| Event | Fields used |
|---|---|
| all | `schema`, `event_type`, `service`, `environment`, `window_end` (must match `YYYY-MM-DDTHH:MM:SSZ`) |
| `run` | `outcome`, `release`, `muted`, `groups_total`, `tokens_in`, `tokens_out`, `tokens_cached`, `cost_usd`, `findings_by_severity` |
| `group` | `fingerprint` (12 hex), `level`, `count`, `trend`, `watch_rules` |
| `finding` | `fingerprint`, `severity` |

Lines with another `schema`, an unknown `event_type`, a bad pattern, or another service@environment are skipped (the last
silently, the others counted in `N lines skipped`). Text fields (`title`, `evidence`, ...) are never read.

## Message (header + blocks as `notice` sections; counts and identifiers only)

```
📊 orders / int — week 2026-W43 (Mon 2026-10-19 – Sun 2026-10-25, UTC)
Runs: 7 reported/quiet · 0 failed · 1 baselined

Recurring (days seen): 3fa9c1d2e4b7 error 6/7 days (known: ABC-123) · 91c0aa7f3e21 warn 5/7 days
Stopped after a release (no longer among the top groups): 5be0d4417a90 after 1.4.2
Noisiest groups (events): 3fa9c1d2e4b7 error 1 840 · 77ad03c19b5e warn 612
Model spend: 412 k tokens in / 38 k out · $3.27
Muted groups (sum over runs): 12
Known issues: 2 of 3 entries seen this week
Coverage: top 20 groups per run · 0 lines skipped
```

(The muted figure is the sum of the runs' `muted` counters.)

## Rules

- ISO week in UTC; default is the last complete week; `--week=YYYY-Www` selects another.
- Lists are `report.weekly.top` long (default 5); ties break by fingerprint asc.
- "known: KEY" comes from `known_issues` configuration only (join on fingerprint).
- No `run` event with outcome reported, quiet or baselined in the week: nothing is sent.
- Sent once per week and service@environment: marker `rsent:<service>:<isoWeek>`, expiry 14 days. A failed delivery does
  not write the marker.
- `--dry-run` prints the same message and sends/stores nothing; `--json` prints the data (same fields, no text).
- Coverage line always present: the file holds only the top groups per run, so "stopped" and "noisiest" are statements
  about that top list.
