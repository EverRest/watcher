# Implementation Plan: Log Hygiene Reports

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) · **Data model**: [data-model.md](data-model.md) · **Contracts**: [contracts/](contracts/) · **Quickstart**: [quickstart.md](quickstart.md) · **Tasks**: [tasks.md](tasks.md)

## Summary

A separate, read-only pass (`watcher hygiene`) that reads the service's own window through the existing `LogSource`, groups it with the existing `Reducer`, and measures it in code: which call sites carry personal-data patterns (US1), which sites are noisy or badly shaped (US2), which mute/watch rules the history justifies (US3), and what the model spend is per service and day (US4). Reports contain call-site identifiers, categories, levels, counts, shares, times, trends and already-allowed masked templates; never a value, an example line or a field. The run path (`Watcher`) is not changed: with `hygiene.enabled=false` (the default) nothing is added to it, which is how SC-005 holds. New code lives in `src/Hygiene/`.

## Technical context

- PHP ^8.1, no new dependency. Framework-agnostic core; the Laravel command is an adapter (`src/Laravel/HygieneCommand.php`).
- Reuses: `Normalizer` (placeholders = categories), `Fingerprinter`, `Reducer`/`TemplateMiner` (templates, withholding), `Redactor` (site identifiers and templates are masked the same way as anything else), `MuteList` and `WatchRules` (replay and rule fragments), `StateStore`, `Keys`, `RunGate`, `Heartbeat`-style fixed-vocabulary reasons.
- State: aggregates only, `state.retention` TTL, written through the existing `StateStore` contract (file, Redis, PDO).
- Tests: PHPUnit, fakes only (`FakeLogSource`, `InMemoryStateStore`, `FakeNotifier`, `FrozenClock`); no network.
- Gates: `composer style`, `composer stan` (level as configured), `composer test`; `make ci` once at the end, exit code read.

## Constitution check

| Principle | How this plan complies |
|---|---|
| I. Privacy fails closed | Reports are built from counters keyed by masked identifiers. Raw values are never stored: the scanner keeps integers per site and category, nothing else. Site ids and templates pass `Redactor`; an unmaskable id is dropped and the template stands in, an unmaskable template is withheld. The privacy corpus is extended **first** (T001) and a test fails if any seeded value reaches a report, the JSON, the notice, or any stored record. `RedactedGroup`'s allowlist is untouched; no new field leaves toward the model (there is no model call at all in this feature). |
| II. Read-only | Only reads the log source and writes its own state; the only outbound write is a post to the configured notice destination. Proposals are text; the package never edits its configuration. |
| III. Test-first | Every task below names its test first. Mandatory suites extended: privacy corpus (T001, T021), table tests for categories, selection of proposals and the never-severe matrix (T014, T021). |
| IV. Code decides | Everything is deterministic code; the model is not involved. Same history and config give the same reports. |
| V. Token and cost | No model call. The ledger (T011) adds a few counter writes per model call only when `hygiene.enabled`; it adds zero tokens. |
| VI. Configuration | Every threshold comes from the `hygiene` section with a documented default; validated at start naming the setting. Nothing consumer-specific. |
| VII. Failures loud | Source/state failure: non-zero exit and a stderr reason; "no findings" prints a distinct line and exit 0. Sampling and bounded caps are stated in the report. |
| VIII. Simplicity | One contract (`Report`, four implementations the spec names). Concrete classes elsewhere. No scheduler of its own: the host's cron calls the command. |

No violations; complexity tracking is empty.

## Structure

```text
src/Hygiene/
  Category.php            enum + placeholder map
  PiiScan.php             normalised text -> category counts (no values)
  SiteId.php              call-site identifier (frame, else template), masked
  HygieneScanner.php      streaming measurement: privacy + shape, bounded, sampling flag
  VariablePositions.php   FR-002 from reduced groups
  Trend.php, SiteRegistry.php   new/growing/stable/gone + weekly suppression (FR-007)
  HygieneHistory.php      per-day aggregate records (FR-016), declines
  Report.php              contract: name, isEmpty, toArray, text
  PrivacyReport.php, ShapeReport.php, ProposalReport.php, CostReport.php
  Proposals.php           mute + watch proposals, Replay
  Cost/CostLedger.php, Cost/LedgerAnalyzer.php, Cost/CostReader.php
  Hygiene.php             orchestration, options, result
src/Cli/Application.php   + `hygiene` command (hunks only)
src/Config/WatchConfig.php, Factory.php   + `hygiene` section, flag, wiring (hunks only)
src/Laravel/HygieneCommand.php, WatcherServiceProvider.php
bin/watcher               + hygiene factory argument
```

## Decisions that differ from, or refine, the spec text

Recorded with reasons in [research.md](research.md): separate pass instead of a tap in `Watcher::locked()` (Lane A is rewriting it); category = placeholder family; distinct-value count is an upper bound; scheduled delivery is the host's cron; ledger for calls per step and top spenders because the run record does not hold them.

## Risks

- A mis-masked site identifier would leak: covered by running every identifier through `Redactor` and by the corpus test over every output.
- State growth: day records are capped (300 families, 500 sites) and say so when they truncate.
- `Watcher` unchanged, so a future change to what a run records must keep `usage` in the run record (the cost report's source of truth); guarded by T012's fixture.
