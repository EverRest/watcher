# Changelog

## Unreleased

### Added (spec 004, baselines and templates; backend patterns deferred)
- `baseline.*` config: `previous` (default), `rolling` and `weekday` comparison modes, `windows`, `max_age`, `exclude`, `since`; digest lines name the basis and any fallback; new group status `expected`.
- `grouping.*` config: similarity-based template clustering (`similarity`, `message_cap`); digest notices `Grouping limit reached` and `Grouping time budget reached` (counts only). Fingerprints are unchanged; templates may read differently.
- DST-safe candidate windows (`RunGate::endBack`), `start` stored in snapshots, missed-day tolerance.
- Not included: `grouping.backend_patterns` (T023) stays rejected until the owner decides.

### Added (spec 003, fleet mode and correlation)
- `watcher fleet:run`, `fleet:check`, `fleet:status` (Laravel/Lumen: `watcher:fleet`): several services from one job over one state store and one analyzer, each isolated, exit code = worst (`skipped_time` and failed fleet posts are exit 1). Config: `fleet`, `services`, `defaults`, `budget`, `digest`, `notify`, `time_budget`, `correlation`, `timezone`; example in `docs/examples/watcher-fleet.php`.
- Shared fleet budget (fleet cap checked before every model call, notices that name the cap, fleet thresholds 25/50/75/90) and `digest: combined` (one message for the whole fleet).
- Cross-service correlation: a consumer finding whose template references a service that has a new or grown finding in the same window is annotated `likely caused by the <origin> finding <fingerprint> (hypothesis)`, and one additive message names the origin and its consumers. Decided in code, no model call; nothing is withheld from any digest; a failed correlated post is loud (host log, exit 1).
- `fleet:run --dry-run` now also prints the combined digest and the correlated finding.
- Internal: `Watcher` takes an optional `FleetSeam` (budget, annotations, dry-run digest hold); `DigestData::$annotations`. Single-service behaviour is unchanged.

### Changed
- Privacy hardening (review P1-P4, owner decision): frame paths are rewritten before they are sent (below a `repo.path_map` root, mapped to the repository path; otherwise from the first `vendor`/`app`/`src` directory, last 3 segments); digit runs inside identifiers are masked unless well known (`Http2`, `utf8`, ...); `extra_patterns` run on the raw message before digits become `<N>`; a JSON `message` keeps only its first line; absolute paths inside messages become `<USER>` / `<PATH>/…/<last two>`. `PATH` and `USER` are now reserved `extra_patterns` names. **Fingerprints and templates of messages containing paths or matching `extra_patterns` change once; no live state exists, so nothing needs migrating.** `CodeLinkBuilder` accepts the repository-relative frames the Redactor now emits.

### Added
- Spec 002, operability: `watcher check [--post-test] [--json]` (doctor over source, provider auth, models, prices, state, notifier), `watcher status [--json]` (heartbeat age and state; exit 0 ok, 1 stale/failed, 3 unknown), `watcher run --dry-run [--estimate]` (nothing posted or recorded; `--estimate` makes no model call) and `--json` (one summary line on stdout, human text on stderr). Laravel/Lumen: `watcher:check`, `watcher:status`, and `--dry-run --estimate --json` on `watcher:run`.
- Breaking for embedders of `Cli\Application`: the run factory is now `Closure(array, RunOptions): callable(?DateTimeImmutable): RunResult`; `check` and `status` factories are optional constructor arguments.
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
