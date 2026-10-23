# Data Model: Operability Tools

All records are JSON arrays stored through `StateStore`. Key components are encoded by `Keys::join` (URL-encoded,
per service), so several services can share one store. No record contains log text, templates, field values or
secrets (Principle I).

## Heartbeat record

Key `hb:{service}` (`Keys::heartbeat`). Written by a run that finished; never by a skip.

| Field | Type | Meaning |
|---|---|---|
| `v` | int | record version, 1 |
| `service` | string | service name |
| `finishedAt` | string ATOM | end of the run (injected clock) |
| `outcome` | string | `reported`, `quiet`, `baselined` or `failed` (`invalid_config` is never stored: the config, and so the service, is unknown) |
| `reason` | string or null | short failure reason, whitespace collapsed, `"` replaced, cut at 200 characters, configured secrets masked (same rules as `RunResult::summary()` / `NoticeRenderer`) |
| `windowStart`, `windowEnd` | string ATOM | the window of the run |
| `durationSeconds` | int | finish minus start |
| `costUsd` | float | estimated cost of this run, 0 when no model ran |
| `lastOkAt` | string ATOM or null | `finishedAt` of the last run whose outcome was `reported`, `quiet` or `baselined`; carried forward by a `failed` run |

TTL: 3 x `state.retention`, rewritten by every finished run.

## Trigger record

Key `trig:{service}` (`Keys::trigger`). `{ "at": ATOM }`, written on every trigger outcome (see research R2). TTL: 3 x retention.

## Weekly counters

Keys `hbweek:{service}:{YYYY-Www}:{outcome}` for outcome in `reported`, `quiet`, `baselined`, `failed`,
`skipped_done`, `skipped_locked`, and `hbweek:{service}:{YYYY-Www}:cost` (USD). Value is the existing
`increment` record `{ "total": float }`. TTL 14 days. Week = ISO week in `schedule.timezone`.

Sent marker `hbsent:{service}:{YYYY-Www}`: `acquire` with owner, TTL 14 days, kept on success.

## State keys and TTLs (additions to `Keys`)

| Constant | Builder | TTL (`Keys::ttl`) |
|---|---|---|
| `HEARTBEAT = 'hb'` | `Keys::heartbeat($service)` | 3 x retention |
| `TRIGGER = 'trig'` | `Keys::trigger($service)` | 3 x retention |
| `WEEK = 'hbweek'` | `Keys::week($service, $isoWeek, $counter)` | 14 days |
| `WEEKSENT = 'hbsent'` | `Keys::weekSent($service, $isoWeek)` | 14 days |
| check scratch `check:{random}` | built inline by `Doctor`, not in `Keys` | 60 s |

Every key has an expiry (Constitution, Technical Constraints).

## HeartbeatStatus (value object, not stored)

| Field | Meaning |
|---|---|
| `state` | `ok`, `stale`, `failed`, `unknown` |
| `record` | the heartbeat record or null |
| `ageSeconds` | now minus `lastOkAt` (or null) |
| `limitSeconds` | `heartbeat.stale_after` or 1.5 x window |
| `now`, `comparedWith` | the two instants compared, printed so a wrong host clock is visible |
| `lastTriggerAt` | from `trig:` or null |

Rules: record missing or store unreadable -> `unknown`; `outcome = failed` -> `failed` (even when `lastOkAt` is
recent); `now - lastOkAt > limit` -> `stale`; otherwise `ok`.

## Run summary (JSON line)

Built from `RunResult` + `RunStats`. Counts, numbers, enumerated outcome names, times, ids only.

| Field | Type | Source |
|---|---|---|
| `schema` | int | 1 |
| `service` | string or null | config |
| `outcome` | string | `RunOutcome::value` |
| `reason` | string or null | same sanitised reason as the heartbeat |
| `window_start`, `window_end` | string ATOM or null | `Window` |
| `duration_ms` | int or null | |
| `events_read` | int or null | |
| `groups` | int or null | groups after reduction |
| `candidates` | int or null | after selection and reported-filter |
| `findings` | int or null | after analysis |
| `calls` | object `{triage, analyze, escalate}` ints, or null | model calls per step |
| `tokens_in`, `tokens_out`, `tokens_cached` | int | from `Usage` (0 when no model ran) |
| `cost_usd` | float | this run |
| `today_usd` | float or null | day's total after the run |
| `release` | string or null | configured/derived release label |
| `version` | string | package version (research R8) |
| `dry_run` | bool | true under `--dry-run` |

## RunStats (value object)

`{ windowStart, windowEnd, durationMs, eventsRead, groups, candidates, findings, callsByStep, todayUsd, release }`,
all nullable; `RunResult` gets it as a fourth, optional constructor argument.

## Check report

`CheckResult { name, status: pass|fail|skip, reason }`; `CheckReport { results[], ok(): bool }` (`ok` = no `fail`).
Names: `config`, `source`, `provider_auth`, `models`, `prices`, `state`, `notifier` (+ `post_test:{digest|notice}`
only with `--post-test`). `reason` is a short fixed-vocabulary string or an HTTP status, never a message from a
library (messages may carry URLs or keys).

## Dry-run overlay

Not stored. `DryRunStateStore` holds no data: all writes are discarded; `get` of kind `run` returns null.
