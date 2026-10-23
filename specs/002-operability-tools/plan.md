# Implementation Plan: Operability Tools

**Branch**: `002-operability-tools` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-operability-tools/spec.md`

## Summary

Five small tools around the existing pipeline, with no new core contract:

1. **Heartbeat** (US1): after every due run `Watcher::run()` writes one record per service into the existing
   `StateStore` (TTL = 3 x retention, refreshed by each run). `watcher status` reads it and exits non-zero when
   stale, failed or unknown. An optional weekly line is sent once per ISO week, from the first due run of the next week.
2. **`watcher check`** (US2): a `Doctor` that builds sources, analyzer and store with the existing `Factory`, runs
   seven independent checks and prints pass/fail per check. Model metadata comes from a new **optional** interface
   `ModelCatalog` (implemented for Anthropic, found with `instanceof`), not from a change to `Analyzer`.
3. **`--dry-run [--estimate]`** (US3): `DryRunStateStore` (reads through, swallows `put`/`acquire`/`release`/
   `increment`, hides `run:` keys) + `CapturingNotifier` (prints to stdout). `--estimate` swaps the analyzer for an
   `EstimatingAnalyzer` that makes no call. The pipeline code is untouched except for the construction in
   `Watcher::fromConfig`.
4. **`--json`** (US4): `RunResult` carries an optional `RunStats` (counts only); `JsonSummary` renders exactly one
   line on stdout; human text goes to stderr.
5. **File purge** (US5): `FileStateStore` implements the optional `Purgeable`; `Watcher::run()` calls it once the
   gate says "due". Lock sidecars are removed only with inode revalidation (R5).

Decisions are in [research.md](research.md); keys and fields in [data-model.md](data-model.md); commands in
[contracts/cli.md](contracts/cli.md); scenarios in [quickstart.md](quickstart.md).

## Technical Context

**Language/Version**: PHP ^8.1 (unchanged). **New dependencies**: none (Guzzle already present; version string via
`Composer\InstalledVersions`, part of Composer's runtime).

**Storage**: the existing `StateStore` (Redis, PDO, file). No new store, no new contract method (there is no `delete`:
scratch keys expire, see R3).

**Testing**: PHPUnit `Unit`, `Contract`, `EndToEnd`; no network (Principle III). The privacy corpus gains the new
outputs (heartbeat, summary, check report).

**Constraints**:
- Zero extra model tokens in `check`; zero Slack posts unless `--post-test`.
- A heartbeat or purge failure never changes a run's outcome or exit code (FR-005).
- Heartbeat/trigger writes add at most 2 small `put`s per trigger.
- Purge bounded: once per day per directory, 2 s, 5,000 files.

## Constitution Check

| Principle | Gate | Result |
|---|---|---|
| I. Privacy fails closed | Heartbeat, summary and check report carry counts, enumerated outcomes, times and ids only. Failure `reason` goes through the same short, secret-masked path as `RunResult::summary()`. Privacy corpus extended in T020 before the summary is rendered. | Pass |
| II. Read-only | `check` uses a 5-minute read-only query, the free models-list GET, and `--post-test` posts only to the configured Slack destinations (already allowed). Own state only. | Pass |
| III. Test-first | Every implementation task is preceded by a red test task; no network (HTTP faked). | Pass |
| IV. Code decides | No change to selection or model input. Dry-run changes who receives output, not what is computed. | Pass |
| V. Token discipline | `check` and `--estimate` spend nothing; dry-run model calls are real, labelled billed, and not booked in the daily counters (FR-014). | Pass |
| VI. Config over code | New keys `heartbeat.stale_after`, `heartbeat.weekly_summary` validated at start; no model id or URL hard-coded except the provider's documented models endpoint inside the Anthropic adapter. | Pass |
| VII. Loud failures | `status` and `check` exit non-zero; `--json` prints a line for failed and invalid-config runs; heartbeat/purge faults go to the host log. | Pass |
| VIII. Simplicity | Four core contracts unchanged. Two optional interfaces (`ModelCatalog`, `Purgeable`), each with one implementer and a real reason (provider-specific call; store-specific cleanup). | Pass |

**Four-contract principle**: `LogSource`, `StateStore`, `Notifier`, `Analyzer` keep their signatures. `Doctor` uses
`LogSource::totals()`, `StateStore::{put,get,increment,acquire,release}` and `Notifier::send()` as they are. Anything a
contract cannot answer (model list, purge) is an optional interface checked with `instanceof`; a driver without it
gets a `skip` result in the report (check) or is simply not purged.

**Spec drift to record**: FR-006 says the state check reads back "a counter after an increment" and the edge case says
the scratch key is "removed": `StateStore` has no delete, so the scratch key is given a 60 s TTL and left to expire.

## Project Structure

### Documentation (this feature)

```text
specs/002-operability-tools/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/cli.md
├── checklists/
└── tasks.md
```

### Source Code (changes only)

```text
src/
├── Watcher.php                  # [shared hot file] heartbeat, weekly line, purge call, dry-run/estimate build, RunStats
├── RunStats.php  (new)          # counts for the JSON line
├── RunOptions.php (new)         # dryRun, estimate, out stream
├── RunResult.php                # optional RunStats
├── Heartbeat/Heartbeat.php, HeartbeatStatus.php (new)
├── Check/Doctor.php, CheckReport.php, CheckResult.php (new)
├── Analyze/ModelCatalog.php, AnthropicModelCatalog.php, EstimatingAnalyzer.php (new)
├── Notify/CapturingNotifier.php (new), NoticeRenderer.php (weekly text)
├── State/DryRunStateStore.php, Purgeable.php (new), Keys.php, FileStateStore.php
├── Config/WatchConfig.php, Factory.php
├── Cli/Application.php          # [shared hot file] status, check, run flags
├── Cli/JsonSummary.php (new)
└── Laravel/RunCommand.php, StatusCommand.php (new), CheckCommand.php (new), WatcherServiceProvider.php
bin/watcher

tests/
├── Unit/{Heartbeat,Check,Analyze,State,Notify,Cli,Laravel,Config}/...
└── EndToEnd/{HeartbeatTest,DryRunTest}.php, PrivacyTest.php (extended)
```

**Structure Decision**: same single library. Hot files (`src/Watcher.php`, `src/Cli/Application.php`) are touched by
several stories; the tasks serialise those edits (see tasks.md, Dependencies).

## Complexity Tracking

| Ceiling | Why | Upgrade path |
|---|---|---|
| Dry-run overlay is "swallow", not "buffer": `Spend::today()` sees no in-run spend, so budget gating inside a dry run uses the real counters only | FR-014 says never book spend; a dry run is one run | Add an in-memory counter overlay if a dry run must model its own budget |
| `last_trigger` is written on every trigger, including not-due ones (one `put`) | Spec AC US1.2; lets Host monitoring tell "scheduler fires" from "no run happened" | See R2: restrict to due-but-skipped if a not-due trigger must never touch the store (001 FR-002) |
| Weekly summary is sent by the first due run of the next week, not by a scheduler | The package has no scheduler of its own | None needed |

## Lead decisions (2026-10-24, override earlier text where they differ)

1. **Trigger record**: only a due-but-skipped trigger (`skipped_done`, `skipped_locked`) writes `trig:{service}`. A not-due trigger touches NO state (keeps 001 FR-002 and the lazy store connect: a hourly not-due trigger must survive a state outage). US1 AC2 is satisfied for the case that matters; the last-run heartbeat alone detects a dead job.
2. **Dry run bypasses the due gate**: `--dry-run` runs the window the real run would analyse today, i.e. the one ending at the most recent `run_at` instant <= now (or `--now`), ignoring the due hour and the already-done record, and labels the output. It never ends as `skipped_not_due`.
3. Scratch key with 60 s TTL (no state delete); `check` and `status` get `--json`; `ModelCatalog` and `Purgeable` stay optional interfaces found by `instanceof`; purge takes `flock(LOCK_NB)` on the sidecar and `FileStateStore::locked()` revalidates the inode.
