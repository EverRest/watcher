# Contract: wording added by spec 014

Every line is built from timestamps, integers and fixed words only (Principle I); times are UTC as
`2026-10-21 06:00 UTC`; Slack text goes through the existing `SlackText` escaping. Lines go in the digest header block
under the comparison line. A quiet run never posts a digest, so a gap that would otherwise be hidden is sent as one short
notice to the notice destination, once per window (`noticeOnce`).

| Case | Text |
|---|---|
| Gap (FR-012) | `⚠️ {from} to {to}: not analysed ({n} window(s) missing or failed). Events in that span were not read.` where `n = round((to - from) / period)`, omitted for a `run_at` list (then `... not analysed.`) |
| Catch-up ran several windows (`catch_up > 1`) | `ℹ️ catch-up: {k} windows run, oldest first, because the host did not trigger in time.` (one digest per window as usual; this line is only on the first) |
| Window trimmed by the high-water mark | none (not news; visible in `--json` as `window.read_from`) |
| Same-slot comparison | `Compared with the same hour yesterday ({end}).` / `Compared with the same hour last week ({end}).` (`hour` is replaced by `slot` for periods that are not 1 h: `Compared with the same slot yesterday (...)`) |
| Same-slot fallback | `No snapshot for the same slot ({end}); compared with the previous window instead.` |
| Per-hour scaling | existing spec 004 line: `Counts are compared per hour (window length was {a} h, now {b} h).` |
| Run budget reached | `{n} group(s) not analysed: this run's budget ({usd}) would have been exceeded` (sibling of the existing daily-budget line, same counters) |
| Capped notice (one a day) | `watcher {service}{@env}: run limit reached ({n} runs today). Further runs today do nothing until {next local midnight} {tz}.` |
| Failure notices over the daily limit | no text (suppressed); the host log and exit code still carry it |
| Weekly line (multi-run) | existing counters without `skipped`, plus `{x} runs/day` |

Fixed words only. `{usd}` is a number with two decimals; `{service}`/`{@env}` are the operator-configured names already
used in notices.
