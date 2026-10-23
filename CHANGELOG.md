# Changelog

## Unreleased

### Added
- US1, scheduled digest: `Watcher::fromConfig()` pipeline LogSource -> Reducer -> Redactor -> Selector -> Analyzer -> Slack; fingerprinting of log lines into groups with counts, first-run baseline, comparison with the previous window, dedupe of already-reported groups.
- US2, privacy: allowlist-only payload, normalised templates, text withheld for clusters below `redaction.min_group_events_for_text`, pattern scan with `redaction.extra_patterns`, model output scrubbed before caching and display, privacy corpus tests.
- US3, cost control: triage / analyze / escalate routing with per-tier model ids, token limits and prices, verdict cache, bounded escalation, input budget truncation, daily USD and token caps, alerts at 25/50/75/90 percent, cost footer in every digest.
- US4, failures and flexibility: Loki and Datadog sources, Redis / PDO / file state stores (shared contract suite), `InvalidConfig` with exit code 2, failure notices with exit code 1, `watcher run` CLI and Lumen/Laravel `watcher:run` command with a publishable `config/watcher.php`.
- US5, noise control: `mute` list (fingerprint or matcher, optional end date) and named `watch_rules`; level-anomaly second pass when a level's total grew.
- US6, context: dependency context, deploy info and log/code link builders (`src/Context`), configured through `dependencies`, `deploy`, `links`, `repo`.
- Scheduling: `schedule.run_at` gate (host triggers hourly), per-window run record and lock for idempotent runs.
- `config/watcher.example.php`, `README.md` and architecture tests (`tests/Unit/ArchitectureTest.php`).

### Changed
- Hardening: strict validation of configuration (unknown keys rejected, regexes compiled up front, prices required per model), state tables never created implicitly, every stored item and lock carries an expiry.

### Security
- Read-only by construction: sources issue only Loki `query` / `query_range` GETs and Datadog `aggregate` / `events/search` POSTs, enforced by an architecture test.
- Secrets never appear in error messages, notices or model input; Slack text is escaped so log content cannot ping, link or format.
- No model ids, no consumer-specific names in `src/` or `config/`, no framework imports in the core, all checked by tests.

### Documentation

- `docs/`: step-by-step first live smoke run, operations guide, configuration reference and a data-flow / privacy description; a minimal smoke config under `docs/examples/`.

### Changed (CLI)

- `bin/watcher` and `watcher:run` print a one-line result (`outcome`, `reason`, tokens, cost) and failure messages go to stderr; previously only the exit code was visible.
