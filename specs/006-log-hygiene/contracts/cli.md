# Contract: `watcher hygiene`

```text
watcher hygiene --config=<file> [--report=<name>[,<name>...]] [--days=<n>] [--now=<ISO-8601>]
                [--notify] [--json] [--decline=<proposal id>]
```

Laravel: `php artisan watcher:hygiene [--report=…] [--days=…] [--now=…] [--notify] [--json] [--decline=…]`.

| Option | Meaning |
|---|---|
| `--report` | any of `privacy`, `shape`, `proposals`, `cost`; default all four |
| `--days` | days shown by the cost report (default 7, 1..30) |
| `--now` | strict ISO-8601, as for `run`; sets the end of the window and "today" |
| `--notify` | post each **non-empty** report, after weekly suppression, to the `notice` destination; stdout is still printed |
| `--json` | stdout is one JSON line `{"schema":1,"service":…,"reports":{…}}`; the text goes to stderr |
| `--decline=<id>` | marks a proposal declined for `hygiene.decline_days`; reads and writes state only; no other report is produced |

## Behaviour

- Window: `Window::endingAt(now, schedule.window)`; the source is read once (`hygiene.read_min_level`, `hygiene.event_cap`).
- `hygiene.enabled=false`: `privacy`, `shape`, `proposals` are not produced; stderr says `watcher: hygiene reports disabled (set hygiene.enabled=true)`; `cost` is produced; exit 0. `--notify` and `--decline` are refused with exit 2 when disabled.
- The master `enabled=false`: `watcher: disabled (set enabled=true / WATCHER_ENABLED=1)`, nothing is read, exit 0.
- A report with nothing to say: stdout `<name>: nothing to report`; with `--notify` nothing is sent.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | reports produced (findings or not) or disabled as above |
| 1 | source or state failure (reason is a fixed phrase: `source unavailable`, `state unavailable`, `notice delivery failed`, `another hygiene pass is running`, or `internal error (Class)`), also in `--json` as `failure`; no report is partially sent |
| 2 | usage error, invalid config, unknown `--report`, `--days` out of range, bad `--now`, unknown proposal id |

## Output rules

Stdout/stderr, JSON and notices carry only what [report-schema.md](report-schema.md) lists. Free text is produced by code from those fields; no log message, field or value is ever interpolated.
