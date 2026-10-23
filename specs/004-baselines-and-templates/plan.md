# Implementation Plan: Smarter Baselines and Better Templates

**Branch**: `004-baselines-and-templates` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: `specs/004-baselines-and-templates/spec.md`. Decisions: [research.md](research.md). Types and state:
[data-model.md](data-model.md). Config and digest wording: [contracts/](contracts/). Scenarios:
[quickstart.md](quickstart.md). Order of work: [tasks.md](tasks.md).

## Summary

Compare each window with a configurable set of earlier windows (previous, rolling average, same-weekday median) and
replace the exact-token-count template clusters with deterministic similarity clustering. Fingerprints do not change.
No new dependency, no new state key, no new CLI command.

## What is already done, what remains (as of 2026-10-24)

Another agent is landing the first half of US1 in `src/Watcher.php` while this plan is written. Facts below are from
the code at `2c9bc72` plus that agent's brief; **T001 re-checks them against the landed code before anything else.**

| Spec item | State | Detail |
|---|---|---|
| FR-001 last-good pointer `snap:<svc>:last`, updated only by successful runs | DONE (in flight) | not touched here |
| FR-002 fallback to the newest earlier snapshot, max age 7 days, digest notice | PARTLY done | pointer, lookup and notice are in flight. **Remaining:** "normalise counts to the same duration" needs the previous window's length, which snapshots do not store (T009 adds `start`; the scaling lives in `BaselineResolver`, T011). |
| FR-003 DST-safe windows, due hour once per local day | DONE | `RunGate::start()`/`runAtOn()`, `tests/EndToEnd/DstTest.php`. T008 only checks the matrix is complete. |
| FR-004 retries on transient failure | PARTLY done | Log sources: done (`src/Source/Retry.php`, 429/502/503/504, 500 where safe, bounded budget, used by Loki and Datadog). Model provider: deliberately **off** (`Factory.php:60` `maxRetries => 0`, review item W3: retries multiply the worst case past `lock_ttl`). **Not done here, owner decision O1.** |
| FR-005..FR-008 modes, "new/grown" per mode, exceptions, config validation | REMAINING | US2 |
| FR-009..FR-013 similarity grouping | REMAINING | US3 |
| FR-014 backend patterns | REMAINING, optional | US4, deferred (O5) |

US1 is therefore reduced to: verify the scenario matrix after the pointer work lands (T008), record the window start
in snapshots (T009), and scale counts to the comparison window length (inside US2's resolver, because previous-window
comparison goes through the same code path).

## Technical Context

**Language/Version**: PHP ^8.1. **Dependencies**: none added. **Storage**: existing `StateStore`; no new key kinds.
**Testing**: PHPUnit `Unit` / `Contract` / `EndToEnd`, fakes only, no network. Gates: `composer test`, `composer stan`,
`composer style` (`composer ci` runs all three); read `$?`, not the last output line.

**Performance goals**: grouping 200,000 events / 100,000 distinct messages in 256 MB and 60 s, or report truncation
(SC-005); the baseline modes add at most `baseline.windows` (default 7 or 4) `get` calls per run.

**Storage bound**: a snapshot holds one count per fingerprint, and fingerprints are capped by `source.group_cap`
(default 5000, plus level totals). Roughly 30 bytes per entry, so about 150 KB per snapshot at the cap and about 4.5 MB
per service at 30 days of daily windows. This feature adds two scalar fields to a snapshot and **no** new documents.
The resolver streams one snapshot at a time and keeps counts only for the fingerprints of the current run (at most
`group_cap` plus the 2x watch-rule allowance), so memory is `fingerprints x windows` integers.

## Design in one page

### Baselines (US1 remainder, US2)

1. `BaselineResolver` (new, `src/Baseline/`) builds a `Comparison` from the state store. It does not list keys. It
   computes candidate window ends by arithmetic: `rolling` = the window length back, 1..N times; `weekday` = 7, 14, 21,
   28 days back at the same local wall-clock `run_at` (via a new public `RunGate::endBack()`, DST-safe because it reuses
   `runAtOn()`); `previous` = the previous end, falling back through the pointer (in-flight work).
2. A candidate with no snapshot is skipped, not an error. Candidates listed in `baseline.exclude` (local dates) or
   ended before `baseline.since` are skipped. This is the whole of FR-007: no new state, no CLI.
3. Counts are scaled to events per hour using each snapshot's `start`/`end`; a snapshot without `start` (written before
   this feature) is assumed to have the current window length.
4. Status: `New` if the fingerprint is in none of the used snapshots. Otherwise the comparison value is the mean
   (rolling) or median (weekday) of the per-hour counts, zero where the snapshot exists but lacks the fingerprint;
   `Grown` if `count >= growth_factor x value`. If the value is 0 but the fingerprint was seen in at least one used
   snapshot, the value is the maximum of the set and an unchanged group gets the new status `Expected` (research R4).
5. Too little history (rolling < 2, weekday < 3) falls back to the previous-window mode and says so in the digest.
   No history at all is a baseline run, exactly as today.
6. Validation (FR-008): `rolling`/`weekday` require a whole-day `schedule.window` and
   `state.retention >= windows x span` (span = window length for rolling, 7 days for weekday) plus one day; failure
   names the setting before any call.

### Grouping (US3)

1. **Fingerprints are unchanged.** `Fingerprinter` is not edited. A problem keeps its id, `reported:` and `verdict:`
   keys stay valid, and no comparison resets. FR-012's migration clause does not trigger (research R3). T019 pins this
   with golden values.
2. What changes is the **template** only. Today the template comes from a cluster keyed by
   `(level, errorType, token count, frame0)`, so two unrelated problems of equal length share one cluster and every
   differing position becomes `<*>`. New: `TemplateMiner` collects *distinct masked messages with event counts* per
   bucket `(level, errorType, token count, frame0)`, and after the stream clusters them by similarity.
3. Clustering is deterministic: messages sorted by `(count desc, text asc)`, each joins the best existing cluster whose
   leader is at least `grouping.similarity` similar (ties: lowest leader text) or founds a new one. The input is a
   multiset, so the result does not depend on event order. Only truncation (below) can break order-independence, and it
   is reported.
4. A cluster's template masks the positions where any member differs from the leader, as today. The min-events rule is
   unchanged and applies to the cluster's event total. A fingerprint whose messages span several clusters takes the
   template of its largest cluster (ties: lowest template text).
5. Bounds: `grouping.message_cap` distinct messages per run (default 50,000) and a comparison budget of 400 x that.
   Messages past the cap are counted but not stored; clusters left unfinished when the budget runs out are withheld
   (template null). Both raise a `truncated` flag shown in the digest.

### Backend patterns (US4)

Deferred. See research R9 and open decision O5. One placeholder task, not to be started before the owner decides.

## Files by touch point

New: `src/Baseline/BaselineResolver.php`, `src/Baseline/Comparison.php`.
Edited: `src/Config/WatchConfig.php`, `src/Schedule/RunGate.php`, `src/Reduce/{GroupStatus,ProblemGroup,Reducer,TemplateMiner}.php`,
`src/Privacy/{GroupStatusLabel,RedactedGroup}.php`, `src/Notify/{DigestRenderer,DigestData}.php`, `src/Watcher.php`,
docs and `config/watcher.example.php`.

### Sequencing hazards

- `src/Watcher.php` is edited by the in-flight pointer work and by T009, T014 and T022. These three tasks are
  **strictly sequential**, in that order, and none starts before T001 confirms the pointer work is committed and the file
  re-read. They must not be run in parallel with each other or with any other agent.
- `src/Reduce/TemplateMiner.php` (T020) then `src/Reduce/Reducer.php` (T021): sequential, since the miner's API changes
  from `add(): key` / `template(key)` to `add(..., fp)` / `template(fp)`. `Reducer.php` also feeds `Watcher.php`'s
  `Reducer` construction, so T022 follows T021.
- `src/Notify/DigestRenderer.php` is edited by T013 and T022: sequential.

## Constitution Check

| Principle | Result |
|---|---|
| I Privacy fails closed | Similarity runs on already-masked tokens only. Outgoing fields are unchanged except one new fixed enum value (`expected`). Withhold-on-small-cluster, varying-position masking, second scan and withhold-on-failure are untouched. Truncation withholds, never passes through. The corpus is extended first (T017). Known limit P5 (a value identical in every event of a cluster survives) is not worsened but is **narrower clusters, so more clusters can now qualify**; T017 includes the retry-loop case. |
| II Read-only | Only reads snapshots; no new writes besides an extra field in the existing snapshot. |
| III Test-first | Every implementation task follows a red test task (tasks.md). Contract suites unchanged: no new store method. |
| IV Code decides | Status and comparison value are deterministic code; the model still never selects input. `Expected` is computed, not judged. |
| V Cost | No change in the number of calls (SC-007); payload grows only by template text and the 8-character `expected` label; covered by `CostTest` fixtures (T012, T018). |
| VI Config over hard-coding | All thresholds are keys with defaults, validated once at start (T003). The comparison budget ratio (400) is derived, documented. |
| VII Failures loud | Fallback of mode, scaling and truncation each print a digest line. |
| VIII Simplicity | No history index, no chain pointer, no CLI, no contract for one implementation, no dependency. Alternatives rejected in research. |

No violations; complexity tracking empty.

## Open decisions for the owner

See the end of [research.md](research.md) (O1..O6). Defaults are chosen so work can proceed; O1 and O5 change scope.
