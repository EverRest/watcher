# Changelog

## Unreleased
- Own Anthropic client (lane H), review fixes: the `check` models probe no longer follows redirects (the `x-api-key` header would travel with them), verifies TLS and bounds the reply; non-integer or negative cache token counts in a model reply are an invalid reply instead of silently counting as 0. Product release label **v0.1.64** (`VERSION`).
- **BREAKING:** the Anthropic SDK is no longer installed. `anthropic-ai/sdk` moved to `suggest` and `require-dev`, `php-http/discovery` left `require` (the Composer plugin prompt is gone; `allow-plugins` stays for dev installs), and the default client is the built-in `AnthropicHttpClient`. To roll back, set `analysis.client = sdk` and `composer require anthropic-ai/sdk`. `check` now tries `x-api-key` first and `provider_auth` passes on it alone. Product release label **v0.1.63** (`VERSION`).
- Own Anthropic client (lane H), step 3: `analysis.client` (`http` default, `sdk` rollback) picks the client in `Factory::analyzer`; `sdk` without `anthropic-ai/sdk` installed is an `InvalidConfig` naming `analysis.client` at config load. Product release label **v0.1.62** (`VERSION`).
- Own Anthropic client (lane H), step 2: `AnthropicHttpClient` (Guzzle, fixed `https://api.anthropic.com/v1/messages`, `x-api-key`, no redirects, TLS verified, bounded reply, no client retry, errors name only the class or HTTP code) with recorded fixtures for 401, 429, 500, 529, refusal, max_tokens, pause_turn and a non-JSON body. Not wired yet. Product release label **v0.1.61** (`VERSION`).
- Own Anthropic client (lane H), step 1: the analyzer now talks to a `MessagesClient`; the SDK call moved unchanged into `SdkMessagesClient`. No behaviour change. Product release label **v0.1.60** (`VERSION`).
- Notices and digests (Lane D review leftovers): model text can no longer format (`*`, `_`, `~`) or spoof `:emoji:` on any channel; IDN, IDNA-dot and `xn--` hosts are defanged while code identifiers such as `Mailer.send` stay copyable; failure notices mask every configured credential (`WatchConfig::secrets()`) and Authorization-like values; the digest gets a "read quality" line (unknown levels, orphan trace lines, cut multi-line messages) from the optional `ReadDiagnostics` interface (Loki, Datadog); `Retry` no longer chains Guzzle exceptions (their message carries the URL). Product release label **v0.1.1** (`VERSION`).

### Security
- Raised dependency floors to versions that are advisory-free and that CI actually passes on: `guzzlehttp/guzzle` ^7.15.2, `psr/log` ^2.0 || ^3.0 (the 1.x line was incompatible with `StderrLogger`), and `php-http/discovery` ^1.12 is now a direct requirement (the SDK alone allowed 1.9, which breaks it).
- CI also tests the lowest (PHP 8.1, `--prefer-lowest`) and highest (PHP 8.5) dependency sets, because consumers ignore `composer.lock`, and audits the resolved runtime set.
- GitHub Actions are pinned to commit SHAs, checkouts no longer persist credentials, the Composer cache is exact-key only, Dependabot is enabled for Actions and Composer.
- The release workflow refuses a tag that is not on `main`, marks `-rc`/`-beta` tags as pre-releases, verifies the tag and attaches a build-provenance attestation for the source archive.
- README: decline the unneeded `php-http/discovery` Composer plugin (`allow-plugins.php-http/discovery false`).
- Added `SECURITY.md`, CODEOWNERS, secret-scanning path exclusions and a repository-settings checklist; documented the supported PHP/Laravel matrix.
- The pre-commit hook handles file names with spaces and globs (they were skipped by the secret scan) and refuses an empty or blocklisted author e-mail.
- `bin/watcher` exits with a clear error when no Composer autoloader is found.
- Input hardening (security reviews 1 and 2): credentials are masked by key name (`password=`, `secret`, `token`, DSN, JSON, query, `X-…-Token` headers) regardless of length or repetition; text is canonicalised before the rules (bounded fixed point: format characters removed, NFKC when ext-intl is present, ideographic dots, nested percent and HTML-entity decoding); IDN hosts, `user@host`, quoted local parts and relative-URL query values are masked; a line that cannot be scanned becomes `<UNSCANNABLE>` per event (`Normalizer::normalizeOrUnscannable`) and its group shows no text; lines are cut at 8 KB and records bounded (200 lines / 64 KB), split tokens and unparseable structured tails are withheld, only continuation-shaped lines continue a record, regex failures are visible and counted (`truncated_lines`, `truncated_structured`, `regex_failures` in the digest); host logs carry the exception class plus a masked message; Slack webhook URLs are validated at run time; `SlackText::plain` no longer restores control sequences; the stderr logger strips terminal control characters; Slack, Loki and Datadog requests never follow redirects and verify TLS; Redis accepts an ACL `username`; config objects dump as `[redacted]` and secret parameters are `#[\SensitiveParameter]`; validators use the `D` flag; privacy docs corrected for retention, fail-closed, disk, host and email masking and fleet output. **Breaking:** `http://` Loki and Slack URLs are refused unless the host is loopback and `allow_insecure` is set.
- Model use hardened (security review 5): an error-or-higher or watch-rule group can no longer be dropped or settled by a model; triage verdicts for such groups are overruled and an analysis that reports nothing for them is not remembered; triage must return exactly one verdict per group; the model input is wrapped in nonce-tagged untrusted-data delimiters and templates are capped at 300 characters; `related_checks` accepts only exact fingerprints or declared dependency names and command-shaped or URL text in advice becomes `[withheld]`; finding severity and kind are bounded by the logged level; the budget reservation covers both attempts of a step; a model refusal is not retried and the window is recorded as failed (`analysis_refused`), the second failure of a window also stops retries; `top_m` ranks by watch rule, level, growth, then novelty (capped) with a salted tie-break. New digest lines say when triage was overruled, the analysis reported nothing, new groups were capped or earlier verdicts were reused.
- Availability and state (security review 4): `schedule.run_deadline` (`WATCHER_RUN_DEADLINE`, default `lock_ttl` minus a margin) stops reading and skips the model at expiry and says so; the run lock is renewed between steps and before each model call; reading stops at 80% of `memory_limit`; template storage is capped (64 tokens per message, `grouping.memory_budget_mb`); a shutdown handler releases the lock after a PHP fatal; quiet runs that were truncated, overflowed, dropped oversized lines, hit unscannable events or read nothing for three windows send one counts-only notice; Loki paging has a page cap, a wall-time budget and a cursor guard; lines over 64 KB are dropped; backend replies over 16 MB or deeper than 64 JSON levels are refused; the file state store refuses symlinks and group- or other-writable directories and writes 0600; PDO retries a first-write duplicate-key race once and refuses keys over 191 characters; `state.redis.read_timeout` added; failure notices are capped at 3 per window and a delivered digest is not re-sent after a failed state write; fleet services get `fleet.service_time_budget`; dry runs show cumulative in-memory spend and refuse to start when the daily cap is spent; `@` is no longer allowed in `service`.

### Docs
- Installation: note about the `php-http/discovery` Composer plugin prompt (a dependency of the Anthropic SDK).

### Added (spec 012, feature flags and environments)
- **Breaking (opt-in):** the master switch `enabled` (`WATCHER_ENABLED`) defaults to **false**. A config without it makes `run` return the new outcome `disabled` (exit 0, `watcher: disabled (set enabled=true / WATCHER_ENABLED=1)` on stderr; nothing external or stored is touched, not even a heartbeat). Add `'enabled' => true` (or `WATCHER_ENABLED=1`) to every existing config, fleet entry or fleet `defaults`.
- One boolean flag per feature, in the config array and as a `WATCHER_*` variable, all defaulting to on (today's behaviour): `heartbeat.enabled`, `purge.enabled`, `dependencies.enabled`, `deploy.enabled`, `links.logs.enabled`, `links.code.enabled`, `service_context.enabled`, `watch_rules.enabled`, `mute.enabled`, `level_anomaly.enabled`, `selection.escalation.enabled`, `analysis.triage.enabled`, `baseline.enabled`, `grouping.enabled`, `budget_alerts.enabled`, `notify.notice.enabled`, `notify.digest.enabled`, fleet `enabled`. Table: `docs/configuration.md`. A switched-off feature does nothing. Flags are strict booleans (`'false'` is rejected with the key path).
- `environment` (`WATCHER_ENVIRONMENT`): state keys become `<service>@<environment>`; shown in the digest header and the JSON summary. Fleet validation rejects a duplicate `service@environment`.
- `Watcher\Config\Env` (`bool`, `int`, `string`, `float`, `list`) for plain-PHP configs; an invalid value throws `InvalidConfig` naming the variable, never its value. Used by `config/watcher.php`, `config/watcher.example.php` and `docs/examples/*.php`.
- `status` reports `disabled` (exit 0, no store read) for a disabled watcher or heartbeat; `check` validates the config of a disabled watcher and skips the rest; in a fleet a disabled service is `disabled`, not an error.
- `service_context` also accepts `['enabled' =>, 'text' =>]`; `notify.notice` may be `['enabled' => false]` alone. New config sections: `purge`, `level_anomaly`, `budget_alerts`.
- No flag for redaction, the normalizer or the allowlist (architecture test) and none for read-only behaviour; `analysis.enabled` is not provided.
- With `enabled` true and no other new key, output and state are unchanged.

## 0.1.0 — 2026-10-24

First public release (pre-1.0: the API may change; not yet validated against live systems).

everrest/watcher reads one service's logs on a schedule (Loki or Datadog), fingerprints errors into groups, decides in plain code which deserve attention, asks Anthropic models (triage / analyze / escalate) to judge only those, and posts one digest to Slack, Telegram or Discord. Read-only: it never acts on a service and never stores raw log lines.

- Scheduled digest, privacy allowlist and redaction, cost control with daily caps (spec 001).
- Operability: `check`, `status`, `run --dry-run [--estimate] [--json]` (spec 002).
- Fleet mode and cross-service correlation (spec 003).
- Advice on findings: `how_to_check` (what to look at to confirm or refute the hypothesis, never reproduction steps) and `related_checks` (other groups or declared dependencies, entries citing anything not in the model input are dropped), shown as `Check:` and `Related:` in Slack, Telegram and Discord through the existing escapers; flag `analysis.advice.enabled` (`WATCHER_ADVICE_ENABLED`, default on). About +100 output tokens per finding.
- Rolling and weekday baselines, better message templates (spec 004).
- Telegram and Discord notifiers (spec 011).
- Feature flags and environment toggles (spec 012).
- Laravel/Lumen adapter with auto-discovery; framework-free CLI `vendor/bin/watcher`.

Pre-1.0: the API may change between minor versions.

### Added (tooling)
- `composer test` runs ParaTest in parallel (`composer test:serial` keeps plain PHPUnit); `composer coverage` / `make coverage` write Cobertura, Clover and HTML reports under `build/coverage/` (pcov or Xdebug).
- README badges (CI, last successful release, version, PHP, coverage, license) generated by `scripts/badges.php`, refreshed by the pre-commit hook; `make badges`, `make test-serial`, `docs/development.md`.
- CI: Composer cache, `composer validate --strict`, coverage job with artifact; `release.yml` creates a GitHub Release for a `v*` tag only after the full gate passes.

### Added (spec 011, more notification channels)
- `notify.digest` / `notify.notice` accept `driver: slack` (default, unchanged), `telegram` (`bot_token`, `chat_id`, optional `thread_id`) or `discord` (`webhook`). Digest and notices may go to different channels; a digest failure is announced on the notice channel when it is another destination.
- Long digests are split at section boundaries (Telegram 4000, Discord 2000 characters, at most 10 posts, numbered `(i/n)`); a 429 waits the stated `retry_after` within `min(60, lock_ttl / 10)` seconds. Model text is inert on both channels (no live markup, links or mentions; Discord `allowed_mentions.parse` is `[]`).
- Fleet: a service's `notify.digest` / `notify.notice` replace the fleet default wholesale; `digest: combined` needs a `notify.digest` of any driver.
- `watcher check` validates each destination's shape per driver (no network, no value printed); `--post-test` posts one labelled message per distinct destination.
- Bot token, chat id and webhooks are secrets: masked in failure reasons and the stored heartbeat, absent from logs, summaries and exceptions. New egress hosts: `api.telegram.org`, `discord.com` (`discordapp.com`).
- MINOR, additive; Slack configs need no change and there is no upgrade step. Internal: `Factory::notifier($http, $lockTtl = 900)` returns a `RoutingNotifier`; `WatchConfig::destination()`, `notifySecrets()`, `destinationFrom()`.

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
