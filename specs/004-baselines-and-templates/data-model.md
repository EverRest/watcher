# Data Model: Baselines and Templates

No new state key kinds. The key list of spec 001 plus the in-flight `snap:<svc>:last` pointer is unchanged. All items
keep the existing expiry (`state.retention`).

## Snapshot (existing key `snap:<svc>:<window end UTC>`), two additive fields

| Field | Type | Note |
|---|---|---|
| `groups` | `{fingerprint: int}` | existing; one entry per group, at most `source.group_cap` (+ overflow groups per level) |
| `totals` | `{level: int}` | existing |
| `deploy` | object or null | existing |
| `start` | ISO-8601 UTC string | **new**; window start, for per-hour scaling. Absent in snapshots written before this feature: treated as "same length as the current window". |

Size: about 30 bytes per group entry; at the cap about 150 KB per snapshot, about 4.5 MB per service for 30 daily
windows. Readers ignore unknown fields, so a downgrade stays readable.

## BaselineSettings (value object, from config `baseline.*`)

| Field | Type | Rule |
|---|---|---|
| `mode` | `previous` / `rolling` / `weekday` | default `previous` |
| `windows` | int | rolling default 7, weekday default 4; >= 2 rolling, >= 3 weekday |
| `maxAge` | ISO-8601 duration | default `P7D`; owned by the in-flight pointer work. Reused, not redefined. |
| `exclude` | list of `YYYY-MM-DD` | local date (in `schedule.timezone`) of excluded window ends |
| `since` | `YYYY-MM-DD` or null | windows ending before this local date are ignored |

Validation (all before any external call, message names the key): mode known; `windows` range; non-`previous` mode
requires a whole-day `schedule.window`; `state.retention >= windows x span + 1 day` (span = window length for rolling,
7 days for weekday); dates parse.

## GroupingSettings (config `grouping.*`)

| Field | Type | Default | Rule |
|---|---|---|---|
| `similarity` | float | 0.5 | 0 < x <= 1 |
| `messageCap` | int | 50000 | >= 1000 |
| `backendPatterns` | bool | false | reserved for US4; rejected if true while US4 is not built |

The comparison budget is `400 x messageCap` (derived, not a key).

## Comparison (new, `src/Baseline/Comparison.php`, immutable)

| Field | Type | Meaning |
|---|---|---|
| `requestedMode`, `usedMode` | enum | differ when history is too short |
| `reason` | `null` / `short_history` / `pointer_fallback` | why they differ; `pointer_fallback` is set by the in-flight work |
| `ends` | `list<DateTimeImmutable>` | window ends actually used, ascending |
| `hours` | float | length of the current window in hours |
| `values` | `{fingerprint: float}` | per-hour comparison value for fingerprints seen in at least one used snapshot |
| `isBaseline()` | bool | true when no snapshot was usable (first run, or all older than `maxAge`) |
| `statusFor(fp, count)` | `GroupStatus` | New / Grown / Expected / Unchanged (rules in plan.md) |
| `previousFor(fp)` | `?int` | value x current hours, rounded, for "↑ from N" |

Holds counts only for the fingerprints asked for (the current run's groups). No text, no raw values.

## GroupStatus (existing enum), one new case

`New`, `Grown`, `Unchanged`, **`Expected`** (`'expected'`). `GroupStatus::resolve()` takes a float comparison value and a
`seen` flag. `GroupStatusLabel` and `RedactedGroup` map `Expected` to label `expected`; this is the only change to the
outgoing payload schema.

## Mining structures (in memory only, per run, `src/Reduce/TemplateMiner.php`)

| Structure | Fields | Bound |
|---|---|---|
| Bucket | key `level|errorType|token count|frame0` => messages | one per distinct bucket; each holds at least one message |
| Message | text (masked tokens joined by a space), `tokens`, `count`, `fp` | at most `messageCap` in total; `fp` is the message's fingerprint (a distinct message has exactly one) |
| Cluster (phase 2) | leader tokens, `varying` positions, `count` (events), member list | one per cluster; freed after templates are computed |
| MiningStats | `distinctMessages`, `droppedMessages`, `budgetExhausted` | exposed by `Reducer::mining()` for the digest |

Nothing here is persisted and no raw line is held: the tokens are the Normalizer's output.

## State transitions

- Run completes (reported, quiet or baselined): writes the snapshot (now with `start`) and, from the in-flight work, moves
  the pointer. A failed run writes neither.
- Mode change by the operator: takes effect at the next run; with too little history the run falls back to `previous`
  and says so, never to a baseline while any snapshot within `maxAge` exists.
- Upgrade: old snapshots lack `start` (assumed same length). No fingerprint changes, so no re-baseline.
