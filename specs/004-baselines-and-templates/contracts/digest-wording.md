# Contract: digest wording added by spec 004

All lines are built from timestamps, integers and fixed words only, so nothing derived from a log message can appear in
them. Times are UTC in the form `2026-10-21 06:00 UTC`. One line each, in the digest header block under the existing
"vs previous window" level line. Slack text goes through the existing `SlackText` escaping.

## Comparison line (omitted when `usedMode = previous`, the previous window was found, and counts needed no scaling)

| Case | Text |
|---|---|
| previous window missing, older one used (pointer work, landed; unchanged, comes from `DigestData::comparedWith`) | `ℹ️ compared with the run of {Y-m-d}, {N days\|hours} ago`, then, when the gap is over one day: `⚠️ no snapshot for the previous window (skipped or failed run): growth is measured against the older run, counts are per window and not scaled for the gap` |
| rolling | `Compared with the average of {n} windows ({first} to {last}).` |
| rolling, some candidates missing | `Compared with the average of {n} of {N} windows ({first} to {last}).` |
| weekday | `Compared with the median of the last {n} {Weekday}s ({first} to {last}).` |
| weekday, some missing | `Compared with the median of {n} of the last {N} {Weekday}s ({first} to {last}).` |
| too little history, fell back | `{Mode} comparison needs {min} earlier {windows|Weekdays} (built: `windows`, or the weekday name plus `s`, e.g. `Mondays`), found {n}; compared with the previous window instead.` |
| window length differs | appended: `Counts are compared per hour (window length was {a} h, now {b} h).` |
| exclusions applied | appended: `{k} excluded by baseline.exclude.` (only when k > 0) |

`{Weekday}` is the English weekday of the window end in `schedule.timezone`; the `s` is always appended (`Mondays`).

## Group status

| Status | Digest trend text |
|---|---|
| `new` | unchanged (`new`) |
| `grown` | unchanged: `(↑ from {N})`, N from `Comparison::previousFor()` |
| `expected` | `(expected for this weekday)` in weekday mode; `(seen in earlier windows)` in rolling mode |
| `unchanged` | unchanged |

## Grouping truncation (shown next to the existing "cluster cap hit" notice)

- `Grouping limit reached: {d} messages were not clustered and their groups show no message text.` when
  `droppedMessages > 0`.
- `Grouping time budget reached: some groups show no message text.` when `budgetExhausted`.

## Outgoing payload

The only schema change to what reaches the model: `st` may be `expected`. Template text may be longer (fewer
placeholders). Token impact is covered by the budget fixtures (tasks T012, T018).
