# Contract: configuration keys added by spec 004

Unknown keys stay rejected. Every violation throws `InvalidConfig` naming the key, before any external call.

```php
'baseline' => [
    'mode' => 'previous',        // previous | rolling | weekday. Default previous (no behaviour change)
    'windows' => null,           // int. rolling: >= 2, default 7. weekday: >= 3, default 4. Ignored for previous
    'max_age' => 'P7D',          // ISO-8601. Oldest snapshot a missing previous window may fall back to (pointer work)
    'exclude' => [],             // list of 'YYYY-MM-DD': local date of a window END to leave out of rolling/weekday values
    'since' => null,             // 'YYYY-MM-DD': ignore windows that ended before this local date (history reset)
],
'grouping' => [
    'similarity' => 0.5,         // 0 < x <= 1: share of literal words that must match the cluster leader
    'message_cap' => 50000,      // >= 1000: distinct masked messages kept per run; the rest is counted, not clustered
    'backend_patterns' => false, // reserved (US4, deferred): must stay false
],
```

| Key | Default | Validation message (prefix) |
|---|---|---|
| `baseline.mode` | `previous` | `baseline.mode must be one of previous, rolling, weekday` |
| `baseline.windows` | 7 / 4 | `baseline.windows must be an integer >= 2` (rolling) or `>= 3` (weekday) |
| `baseline.max_age` | `P7D` | `baseline.max_age must be an ISO-8601 duration` |
| `baseline.exclude` | `[]` | `baseline.exclude entries must be dates like 2026-12-25` |
| `baseline.since` | `null` | `baseline.since must be a date like 2026-10-01` |
| `grouping.similarity` | `0.5` | `grouping.similarity must be greater than 0 and at most 1` |
| `grouping.message_cap` | `50000` | `grouping.message_cap must be an integer >= 1000` |
| `grouping.backend_patterns` | `false` | `grouping.backend_patterns is not available yet` |

Cross-key rules:

- `mode` is `rolling` or `weekday` and `schedule.window` is not a whole number of days:
  `baseline.mode rolling/weekday needs a whole-day schedule.window`.
- `state.retention` below `windows x span + 1 day` (span: window length for rolling, 7 days for weekday):
  `state.retention (N s) is shorter than baseline.windows needs (M s)`.
- `baseline.max_age` longer than `state.retention`: `baseline.max_age exceeds state.retention`.

Unchanged and reused: `selection.growth_factor` (applies to every mode), `source.group_cap`,
`redaction.min_group_events_for_text`.

Note: if the in-flight pointer work already added a key for the maximum comparison age, T003 keeps that key's name and
documents it here; `baseline.max_age` is the planned name only.
