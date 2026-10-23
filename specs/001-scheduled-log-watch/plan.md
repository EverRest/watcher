# Implementation Plan: Scheduled Log Watch

**Branch**: `001-scheduled-log-watch` | **Date**: 2026-10-23 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-scheduled-log-watch/spec.md`

## Summary

`everrest/watcher` is a framework-agnostic PHP package that a service runs hourly. It decides
whether a run is due (`run_at`), takes a lock, reads per-level totals and server-filtered lines for
the window from Loki or Datadog, folds the lines into fingerprinted problem groups, redacts them
through an allowlist and a pattern scan, and selects candidates in deterministic code. It then routes
the candidates through a cheap triage model, a mid-tier analysis model and a bounded strong-model
escalation, using structured JSON output. Findings are deduplicated against state with a 30-day TTL
(Redis, PDO or file), and one Slack digest is posted, with a token and cost footer. Failures always
produce a notice. A Lumen/Laravel adapter exposes `watcher:run`. Research decisions are in
[research.md](research.md).

## Technical Context

**Language/Version**: PHP ^8.1 (first consumer runs 8.2)

**Primary Dependencies**:
- Runtime: `guzzlehttp/guzzle ^7`, `anthropic-ai/sdk ^1.1`, `psr/log ^1 || ^2 || ^3` and
  `psr/clock ^1`.
- Suggested: `ext-redis` and `ext-pdo`.
- Adapter only: `illuminate/support ^10 || ^11` and `illuminate/console`.
- Dev: `phpunit/phpunit ^10.5 || ^11`, `phpstan/phpstan ^1.11` (level 8) and `laravel/pint`.

**Storage**: StateStore with three drivers: Redis (`ext-redis`), PDO with one table (MySQL,
PostgreSQL, SQLite), and file (single runner). No raw logs are stored.

**Testing**: PHPUnit suites `Unit`, `Contract` (state stores, log sources against recorded HTTP
fixtures) and `EndToEnd` (fakes). No network. The privacy corpus is mandatory.

**Target Platform**: Linux containers (Kubernetes CronJob or framework scheduler); CLI only.

**Project Type**: Library (composer package) with a CLI entry point and a framework adapter.

**Performance Goals**:
- A daily run for one service finishes in under 5 minutes at the 200k event cap.
- Memory stays under 256 MB: lines are streamed, and at most `sample_size` events are kept per group.

**Constraints**:
- Default cost is ≈ $0.05 per run, with a $0.10 target (SC-004) and a hard daily cap.
- At most 1 digest per window.
- The digest stays within 50 Slack blocks.
- No personal data leaves the service (Principle I).

**Scale/Scope**:
- 1 service per installation, about 10 services per environment, 3–4 environments.
- Up to about 1M events per day per service (totals are counted server-side), of which up to
  200k lines are read.
- About 50–500 groups per window.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Pre-research | Post-design |
|---|---|---|---|
| I. Privacy fails closed | Only `RedactedGroup` fields reach the analyzer and notifier, and that type cannot carry other fields. Redaction is code with two layers, and the privacy corpus suite is mandatory. No raw line is persisted. Everything has a TTL. | Pass | Pass: `RedactedGroup` (data-model) has a fixed field set; the normalisation and redaction patterns are one table (R7) |
| II. Read-only | Sources use read-only scopes (`logs_read_data`, a Loki read token). The only outbound writes are Slack and our own state. | Pass | Pass: there are no write calls in the contracts |
| III. Test-first | Tests run without network, with contract suites for stores and sources, routing and budget fixtures, and an end-to-end run. | Pass | Pass: quickstart scenarios 1–14 map to tests |
| IV. Code decides | The Selector, mute list, baseline and verdict reuse are all code. Output is schema-validated and retried once, and unknown fingerprints are rejected. | Pass | Pass: `output_config.format` with code-side enforcement of length and range (R3) |
| V. Token discipline | Zero calls when nothing is new, tiered routing, a local size estimate, per-step `max_tokens`, a daily cap, and a usage footer. Caching only within a run (R3). | Pass | Pass |
| VI. Config over code | No model ids or backends in the core. Contracts exist only for source, store, notifier and analyzer. The core has no framework. | Pass | Pass: model defaults are only in README and the example config |
| VII. Loud failures | Every outcome is in the `RunOutcome` state machine, and failed runs exit with a code ≠ 0 plus a notice. | Pass | Pass |
| VIII. Simplicity | Four contracts, everything else concrete. Runtime dependencies are Guzzle and the official Anthropic SDK; the SDK replaces our own retry, error and parsing code (R3). | Pass | Pass |

Analyze pass (2026-10-23) resolved: privacy corpus moved before the first outbound code (I), time
via PSR-20 instead of an own `Clock` contract (VIII), FR-038 level anomaly, explicit watch-rule
matchers, "minor" never hides `error`/`critical`.

Spec drift recorded: FR-036 was reworded after research. The backend counts **by level**, and
patterns are grouped locally on server-filtered lines, because the Loki patterns API is off by
default and no Datadog patterns API is verified.

## Project Structure

### Documentation (this feature)

```text
specs/001-scheduled-log-watch/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── php-interfaces.md
│   ├── configuration.md
│   ├── analyzer-output.schema.json
│   └── slack-messages.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
src/
├── Watcher.php              # entry point: due? → lock → pipeline → RunResult
├── RunResult.php, RunOutcome.php
├── Config/                  # WatchConfig + validation (FR-014, FR-015)
├── Schedule/                # RunGate: run_at/timezone/window → due? + Window
├── Source/                  # LogSource contract, LokiSource, DatadogSource, LineParser (text + JSON)
├── Reduce/                  # Normalizer (R7 table), Fingerprinter, Reducer, ProblemGroup
├── Privacy/                 # Redactor → RedactedGroup (allowlist + pattern scan, fail closed)
├── Select/                  # Selector (new/grown/level/watch/top-N/mute), Candidate
├── Analyze/                 # Analyzer contract, AnthropicAnalyzer, ModelRouter, Budget, OutputValidator, prompts/
├── State/                   # StateStore contract, RedisStateStore, PdoStateStore, FileStateStore
├── Notify/                  # Notifier contract, SlackWebhookNotifier, DigestRenderer
├── Context/                 # DependencyResolver, DependencyContextReader, DeployInfo, LogLinkBuilder, CodeLinkBuilder
└── Laravel/                 # WatcherServiceProvider, RunCommand (watcher:run)
bin/watcher                  # plain-PHP CLI
config/watcher.example.php

tests/
├── Unit/                    # mirrors src/
├── Contract/                # StateStoreContractTest (3 drivers), LogSourceContractTest (2 sources, HTTP fixtures)
├── EndToEnd/                # quickstart scenarios 1–14 with fakes
└── Fixtures/                # privacy corpus, recorded Loki/Datadog/Anthropic responses
```

**Structure Decision**: a single composer library. Folders are pipeline stages, so each stage is
tested on its own. The Laravel adapter sits inside the same package (`src/Laravel`) and is
autoloaded only when the host uses it. `illuminate/*` is listed under `suggest`/`require-dev`, so
the core stays free of the framework.

## Complexity Tracking

No constitution violations. Two deliberate ceilings:

| Ceiling | Why | Upgrade path |
|---|---|---|
| Local token estimate (bytes / 3) instead of `count_tokens` | It saves a request per step (Principle V) | Switch to `count_tokens` if estimates miss by more than 20% in practice |
| Groups below `read_min_level` are read only when their level total jumps (FR-038, `anomaly_cap` 20k) | It keeps reads bounded at 1M events per day while still catching info bursts | Lower `read_min_level`, raise `anomaly_cap`, or add a Loki patterns-API path behind `LogSource` |
