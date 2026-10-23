---

description: "Task list for Scheduled Log Watch (everrest/watcher)"
---

# Tasks: Scheduled Log Watch

**Input**: Design documents from `specs/001-scheduled-log-watch/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED. Constitution Principle III (Test-First, non-negotiable) applies: every test task
comes before its implementation task and must fail first. No test may touch the network.

**Organization**: grouped by user story (spec.md). US1 and US2 are both P1; the shippable MVP is
US1 + US2 (the package must not be enabled anywhere without the privacy guarantee).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1…US5 from spec.md
- Paths are relative to the repository root `/path/to/watcher`

---

## Phase 1: Setup (Shared Infrastructure)

- [x] T001 Create `composer.json`: name `everrest/watcher`, PSR-4 `Watcher\\` → `src/`, `Watcher\\Tests\\` → `tests/`, `php: ^8.1`, require `guzzlehttp/guzzle: ^7`, `anthropic-ai/sdk: ^1.1`, `psr/log: ^1 || ^2 || ^3`, `psr/clock: ^1`; suggest `ext-redis`, `ext-pdo`, `illuminate/support`; require-dev `phpunit/phpunit: ^10.5 || ^11`, `phpstan/phpstan: ^1.11`, `laravel/pint`, `illuminate/support: ^10 || ^11`, `illuminate/console: ^10 || ^11`; scripts `test`, `stan`, `style`; `bin: ["bin/watcher"]`
- [x] T002 [P] Create `phpunit.xml.dist` with suites `Unit` (`tests/Unit`), `Contract` (`tests/Contract`), `EndToEnd` (`tests/EndToEnd`), `failOnRisky`, `failOnWarning`
- [x] T003 [P] Create `phpstan.neon.dist` (level 8, paths `src` `tests`) and `pint.json` (preset `psr12`)
- [x] T004 [P] Create `.gitignore` (`/vendor`, `.phpunit.cache`, `composer.lock` kept), `CHANGELOG.md` with `## Unreleased`
- [x] T005 Run `composer install` against `composer.json`; confirm `composer test`, `composer stan`, `composer style` all exit 0 on the empty tree (read `$?`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: value objects, the four contracts with fakes, config validation, the shared
normalisation table. No user story starts before this phase is green.

### Tests first

- [x] T006 [P] Test `Level` ordering and parsing (`DEBUG|debug|warn|WARNING|err|CRITICAL` → enum; unknown → `info`) in `tests/Unit/LevelTest.php`
- [x] T007 [P] Test `Window` (end − duration = start; ISO-8601 duration `PT24H` default; equality by start/end) in `tests/Unit/Schedule/WindowTest.php`
- [x] T008 [P] Test `Normalizer` replacement table in order: emails → `<EMAIL>`, URLs → `<URL:host>` only for a single-label host, a declared dependency or a host ending with a `dependency_domains` entry, otherwise `<URL>` (path/query/credentials/port always removed; IP host → `<IP>`; `john-smith.example.com` → `<URL>`), UUIDs → `<ID>`, hex ≥ 8 chars → `<ID>`, IPv4/IPv6 → `<IP>`, JWT-like → `<TOKEN>`, card-like numbers → `<PAN>`, other digits → `<N>`, quoted strings > 32 chars → `<STR>`, whitespace collapsed (research R7) in `tests/Unit/Reduce/NormalizerTest.php`
- [x] T009 [P] Test `LineParser`: orders-api text line regex from research R7 (`[dt] channel.LEVEL [APP]: msg {ctx} {extra}`) and JSON lines (`level|severity|status`, `message|msg`, `exception.class|error.kind`, `exception.trace|error.stack`); unparseable line → level `info`; frames capped at 5; format detected per line (mixed text and JSON in one stream) in `tests/Unit/Source/LineParserTest.php`
- [x] T010 [P] Test `WatchConfig::fromArray()` validation per `contracts/configuration.md`: required keys, `growth_factor > 1`, `0 < confidence_threshold < 1`, `top_m ≤ top_n`, regexes compile, unknown top-level key rejected, error names the key and never contains a secret value in `tests/Unit/Config/WatchConfigTest.php`
- [x] T011 [P] Write `StateStoreContractTest` (abstract): get/put round-trip, expiry honoured (inject clock), `acquire` returns false while held and true after TTL, `release` only by owner, `increment` atomic and expiring in `tests/Contract/StateStoreContractTest.php`
- [x] T012 [P] Write `FileStateStoreTest extends StateStoreContractTest` in `tests/Contract/FileStateStoreTest.php`
- [x] T013 [P] Build the privacy corpus (Principle I: exists before any outbound code): text and JSON log lines seeded with known emails, full names, phone numbers (UA/EU/US formats), JWTs, bearer tokens, IBAN/card-like numbers, IPs, names inside quoted strings and inside exception messages; list of seeded values in `tests/Fixtures/privacy/corpus.log`, `tests/Fixtures/privacy/seeded-values.php`

### Implementation

- [x] T014 [P] Implement `Level` enum (PSR-3 order `debug…emergency`) in `src/Level.php`
- [x] T015 [P] Implement `Window` in `src/Schedule/Window.php`; all time comes from `Psr\Clock\ClockInterface` (PSR-20, no own clock contract — Principle VIII) with a system-clock default; create `FrozenClock implements ClockInterface` in `tests/Fakes/FrozenClock.php`
- [x] T016 [P] Implement `Normalizer` as the single pattern table shared by Reduce and Privacy in `src/Reduce/Normalizer.php`
- [x] T017 [P] Fix `Normalizer`: bare FQDN host names (≥ 2 labels, alphabetic last label, not a known file extension such as `php`, `json`, `log`) follow the URL host rule — single label / declared dependency / `dependency_domains` suffix kept as `<HOST:name>`, anything else `<HOST>`; red test first with `Could not resolve host: john-smith.example.com` and `olena-shevchenko.example.org` from the privacy corpus, plus a non-host such as `config.php` and `1.2.3` staying untouched in `tests/Unit/Reduce/NormalizerTest.php`, `src/Reduce/Normalizer.php`
- [x] T018 [P] Implement `LogEvent` (fields per data-model: `time`, `level`, `message`, `errorType`, `frames` "max 5 kept", `fields`, `labels`) and `LineParser` in `src/Source/LogEvent.php`, `src/Source/LineParser.php`
- [x] T019 [P] Fix `LineParser` to accept both text formats — `[dt] channel.LEVEL [APP]: msg {ctx} {extra}` and Laravel's default `[dt] channel.LEVEL: msg {ctx} {extra}` — red test first for the second format (currently falls back to Info with the whole line as message) in `tests/Unit/Source/LineParserTest.php`, `src/Source/LineParser.php`
- [x] T020 Implement `WatchConfig` (immutable, nested readonly sections) and `InvalidConfig` in `src/Config/WatchConfig.php`, `src/Config/InvalidConfig.php`
- [x] T021 [P] Add `redaction.min_group_events_for_text` (integer ≥ 1, default 3, FR-044) to `WatchConfig` with tests (default, override, 0 and non-integer rejected) in `tests/Unit/Config/WatchConfigTest.php`, `src/Config/WatchConfig.php`
- [x] T022 [P] Declare contracts exactly as `contracts/php-interfaces.md`: `LogSource` (`totals`, `lines`), `ReadFilter`, `StreamSelector`, `SourceUnavailable` in `src/Source/`; `StateStore` in `src/State/StateStore.php`; `Analyzer`, request/result types in `src/Analyze/`; `Notifier`, `Message`, `Destination`, `DeliveryFailed` in `src/Notify/` — DONE for source and notify contracts; the `Analyzer` contract and its types move to the US1 task right after `RedactedGroup`
- [x] T023 Implement `FileStateStore` (one JSON file per key, `flock` + write-rename, expiry stored inside; class docblock states "single runner only") in `src/State/FileStateStore.php`
- [x] T024 [P] Create test fakes `FakeLogSource`, `FakeAnalyzer` (records every request payload), `FakeNotifier` (records every message), `InMemoryStateStore` in `tests/Fakes/`, plus `InMemoryStateStoreTest extends StateStoreContractTest` in `tests/Contract/InMemoryStateStoreTest.php` so the fake cannot drift from real stores — DONE for `FakeLogSource`, `FakeNotifier`, `FrozenClock`, `InMemoryStateStore`; `FakeAnalyzer` moves with the `Analyzer` contract
- [x] T025 Implement `RunOutcome` enum (`skipped_not_due, skipped_locked, skipped_done, baselined, quiet, reported, failed`) and `RunResult` (`exitCode()`: 0, failed → 1, invalid config → 2) in `src/RunOutcome.php`, `src/RunResult.php`

**Checkpoint**: `composer test` green (Unit + Contract), `composer stan` clean.

---

## Phase 3: User Story 1 — Daily digest of new and growing problems (P1) 🎯 MVP part 1

**Goal**: one run reads a window, groups events, selects new/grown/severe groups, gets findings
from one analysis step, dedupes, and posts one digest.

**Independent Test**: two consecutive windows through `Watcher::run()` with `FakeLogSource`,
`FakeAnalyzer`, `FakeNotifier`, `InMemoryStateStore`; the digest lists exactly the new and grown
groups with all required fields (quickstart scenarios 1–4).

### Tests first

- [x] T026 [P] [US1] Test `Fingerprinter`: `sha256(level|errorType|frame0|tokenCount|firstTwoWords)` 12-hex prefix, independent of the mined template and of cluster volume (a name differing between events must not change it; the same problem at 1 event and at 5 events → same fingerprint); `user=123` and `user=456` → same fingerprint; different level → different in `tests/Unit/Reduce/FingerprinterTest.php`
- [x] T027 [P] [US1] Test `TemplateMiner` (FR-044): events clustered by (level, errorType, token count, first frame); a token position whose values differ between events of a cluster becomes `<*>` ("Failed to send welcome mail to Anna Kowalski" ×3 with different names → "Failed to send welcome mail to <*>"); a cluster with fewer than `min_group_events_for_text` events yields `null` template ("message withheld: low volume") so a one-off name never leaves; deterministic regardless of event order; over the privacy corpus none of the seeded names appear in any mined template in `tests/Unit/Reduce/TemplateMinerTest.php`
- [x] T028 [P] [US1] Test `Reducer` folds a stream into `ProblemGroup`s (count, firstSeen/lastSeen, highest level, ≤ `sample_size` samples kept, frames ≤ 5) without holding all events (generator input of 100k events, memory delta bounded) in `tests/Unit/Reduce/ReducerTest.php`
- [x] T029 [P] [US1] Test `GroupStatus`: `new` when previousCount null or 0, `grown` when `count ≥ growth_factor × previousCount`, else `unchanged` in `tests/Unit/Reduce/GroupStatusTest.php`
- [x] T030 [P] [US1] Test `Selector` table: candidates = new ∪ grown ∪ level ≥ `min_level` ∪ level-anomaly groups ∪ top-N by volume (watch rules added in US4); reasons recorded (`new, grown, level, level_anomaly, top_volume`); rest summarised as `N more groups, M events`; zero candidates → empty in `tests/Unit/Select/SelectorTest.php`
- [x] T031 [P] [US1] Test `OutputValidator` against `contracts/analyzer-output.schema.json`: rejects unknown fingerprint, unknown enum, missing field, confidence outside 0–1; truncates `title` > 80, `evidence` > 240, `suspected_cause` > 240, `suggested_action` > 200 with an ellipsis in `tests/Unit/Analyze/OutputValidatorTest.php`
- [x] T032 [P] [US1] Test `AnthropicAnalyzer::analyze()` with a mocked PSR-18 client (Guzzle `MockHandler` → SDK): request uses `output_config.format` json_schema with `maxLength/minimum/maximum` stripped, model from config, `max_tokens` from config; response mapped to `Finding` + `Usage` (all four usage fields); system prompt contains the rules "do not state facts absent from the input" and "label causes as hypotheses" (FR-024) in `tests/Unit/Analyze/AnthropicAnalyzerTest.php`
- [x] T033 [P] [US1] Test `DigestRenderer` output matches `contracts/slack-messages.md`: header line, severity order critical → info, count desc, trend `new`/`↑ from N`, fingerprint shown, "Hypothesis:" prefix; minor line holds `noise` and `warn`/`info` below `confidence_threshold`, never `error`/`critical`; level-anomaly line ("info ×6 vs previous window", FR-038); both truncation lines ("truncated: N events read" for `event_cap`, FR-036, and "truncated: N groups dropped" for budget, FR-021); baseline notice text "baseline recorded: N groups, M events"; ≤ 40 blocks, section text ≤ 2,800 chars, never split in `tests/Unit/Notify/DigestRendererTest.php`
- [x] T034 [P] [US1] Test `SlackWebhookNotifier` posts `blocks` + fallback `text` ≤ 4,000 chars to the webhook (Guzzle `MockHandler`), non-2xx → `DeliveryFailed` in `tests/Unit/Notify/SlackWebhookNotifierTest.php`
- [x] T035 [P] [US1] Test `EventAssembler` and exception extraction (FR-045) with SYNTHETIC fixtures in the real Laravel/Monolog shapes: (a) a message line followed by `[stacktrace]` and `#0 /var/www/html/app/Services/X.php(42): App\\Services\\X->run()` / `#1 {main}` continuation lines → ONE event; (b) a one-line record whose context holds `{"exception":"[object] (Illuminate\\Database\\QueryException(code: 40001): SQLSTATE[40001]: Deadlock ... at /var/www/html/app/Repositories/QuotaRepository.php:88)"}` → errorType `Illuminate\\Database\\QueryException`, frames[0] `/var/www/html/app/Repositories/QuotaRepository.php:88`; (c) message of the form `RuntimeException: Name mismatch in /var/www/html/app/Foo.php:12` → errorType + frame; (d) `#N` frames capped at 5; (e) a stream with a JSON-object line between multi-line records does not swallow it; (f) nothing extractable → no errorType, no frames; (g) the same fixtures through `LokiSource::lines()` (entries arrive one line per entry) yield assembled events, and `eventCap` counts assembled events in `tests/Unit/Source/EventAssemblerTest.php`, `tests/Unit/Source/LineParserExceptionTest.php`, `tests/Contract/LokiSourceTest.php`
- [x] T036 [P] [US1] Write `LogSourceContractTest` (abstract): `totals()` returns level → count, `versions()` returns version → first seen (or `[]` when the label is absent), `lines()` yields `LogEvent`s lazily, stops at `event_cap` and reports truncation, maps auth failure to `SourceUnavailable` in `tests/Contract/LogSourceContractTest.php`
- [x] T037 [P] [US1] Write `LokiSourceTest extends LogSourceContractTest` with recorded fixtures for instant metric query `sum by (level) (count_over_time({…}[24h]))` and `query_range` pages of ≤ 5000 lines, bearer and basic+tenant (`X-Scope-OrgID`) auth in `tests/Contract/LokiSourceTest.php`, `tests/Fixtures/loki/`
- [x] T038 [P] [US1] Test level anomaly (FR-038): previous totals `info: 1000`, current `info: 6000` with `growth_factor` 3 → second `lines()` call for level `info` only, capped at `anomaly_cap` (20,000); its groups get reason `level_anomaly`; no second pass when no previous snapshot or growth below factor in `tests/Unit/Select/LevelAnomalyTest.php`
- [x] T039 [US1] End-to-end privacy layer 1 (Principle I — written before any outbound code): run `tests/Fixtures/privacy/corpus.log` through `Watcher::run()`; every `FakeAnalyzer` payload and `FakeNotifier` message has keys ⊆ the `RedactedGroup` allowlist, and no `fields`, `labels` or raw sample value from the corpus appears anywhere in them or in `InMemoryStateStore` in `tests/EndToEnd/PrivacyTest.php`
- [x] T040 [US1] End-to-end: scenario 0 (info total ×6 vs previous window → second pass reads info lines ≤ `anomaly_cap`, digest line "info ×6 vs previous window"), scenario 1 (first run → `baselined`, one baseline notice "baseline recorded: N groups, M events", 0 analyzer calls) and scenario 2 (second run with one new error group → `reported`, digest with fingerprint) in `tests/EndToEnd/DigestTest.php`
- [x] T041 [US1] End-to-end: scenario 3 (same input next window → `quiet`, 0 analyzer calls, no message) and scenario 4 (group grows ×4 → `↑ from N`); repeat run for the same window → `skipped_done`, no second message (FR-028) in `tests/EndToEnd/DigestTest.php`

### Implementation

- [x] T042 [P] [US1] Implement `Fingerprinter` in `src/Reduce/Fingerprinter.php`
- [x] T043 [P] [US1] Implement `ProblemGroup` (fields per data-model) and `GroupStatus` in `src/Reduce/ProblemGroup.php`, `src/Reduce/GroupStatus.php`
- [x] T044 [US1] Implement `TemplateMiner` (cluster by level/errorType/token count/first frame, mask varying positions with `<*>`, withhold text below `min_group_events_for_text`) in `src/Reduce/TemplateMiner.php`; the `Reducer` (next task) feeds it and `ProblemGroup::$template` becomes `?string`
- [x] T045 [US1] Implement streaming `Reducer` in `src/Reduce/Reducer.php`
- [x] T046 [US1] Implement `RedactedGroup` as the only type the analyzer and renderer accept, with exactly the allowlisted fields `fingerprint, template, level, errorType, frames, count, previousCount, status, watchMatches, firstSeen, lastSeen` (layer 1 of Principle I; layer 2 comes in US2) in `src/Privacy/RedactedGroup.php`
- [x] T047 [US1] Declare the `Analyzer` contract and its types exactly as `contracts/php-interfaces.md` and data-model.md (`Analyzer`, `TriageRequest`, `AnalysisRequest`, `TriageResult`, `AnalysisResult`, `Finding`, `Usage`, `AnalyzerFailed`), requests holding only `RedactedGroup`s, in `src/Analyze/`; create `tests/Fakes/FakeAnalyzer.php` that records every request payload verbatim (the privacy tests scan it) and can return canned results or fail, with small tests
- [x] T048 [US1] Implement `Candidate`, `CandidateReason`, `Selector` in `src/Select/`
- [x] T049 [P] [US1] Implement `OutputValidator` + provider-schema stripper in `src/Analyze/OutputValidator.php`, `src/Analyze/ProviderSchema.php`
- [x] T050 [US1] Implement `AnthropicAnalyzer` as a thin wrapper over `anthropic-ai/sdk` (analyze step only for now), prompt text in `src/Analyze/prompts/analyze.md` forbidding facts absent from input and labelling causes as hypotheses, in `src/Analyze/AnthropicAnalyzer.php`
- [x] T051 [P] [US1] Implement `DigestRenderer` (incl. the baseline notice) and `SlackWebhookNotifier` in `src/Notify/`
- [x] T052 [US1] Implement `LokiSource` (totals via `GET /loki/api/v1/query`, lines via `query_range` with server-side filter `level ≥ read_min_level` OR watch rules `|~`, forward paging, cap) in `src/Source/LokiSource.php`
- [x] T053 [US1] Implement `EventAssembler` (a line starting a new record — `[timestamp]`-prefixed text or a JSON object — begins an event, any other line is appended to the previous event as a continuation) in `src/Source/EventAssembler.php`; extend `LineParser` to extract `errorType`, `frames` (≤ 5) and the origin file:line from the forms in FR-045, never from or into `fields` values that leave the service; make `LokiSource::lines()` assemble entries before parsing and count assembled events against `eventCap`
- [x] T054 [US1] Implement state keys from data-model (`snap:`, `verdict:`, `reported:`, `run:`, `lock:`, `spend:`) in `src/State/Keys.php`, with a test that every write uses `state.retention` except `spend:` (2 days) and `lock:` (`lock_ttl`) in `tests/Unit/State/KeysTest.php`
- [x] T055 [US1] Implement `Watcher::fromConfig()` / `run()` pipeline: lock → RunRecord check → totals + lines → level-anomaly second pass (FR-038) → reduce → status vs previous snapshot → baseline (FR-035) or select → analyze → dedupe (`reported`, FR-025) → render → send → write snapshot/RunRecord; release lock in `finally` in `src/Watcher.php`

**Checkpoint**: T038–T041 green (incl. privacy layer 1). Do not enable anywhere before US2.

---

## Phase 4: User Story 2 — Personal data never leaves the service (P1) 🎯 MVP part 2

**Goal**: guarantee that no seeded personal value reaches the analyzer, Slack or storage.

**Independent Test**: privacy corpus run; every captured analyzer payload, Slack message and stored
value is free of seeded values (quickstart scenario 5, SC-002 = 100%).

### Tests first

- [x] T056 [US2] Extend `tests/EndToEnd/PrivacyTest.php` to layer 2: no value from `tests/Fixtures/privacy/seeded-values.php` (including those embedded in messages and exception texts) appears in any `FakeAnalyzer` payload, `FakeNotifier` message or `InMemoryStateStore` value (SC-002 = 100%)
- [x] T057 [P] [US2] Test `Redactor` layer 2: pattern scan on the template replaces leftovers with typed placeholders; `redaction.extra_patterns` applied as `<NAME>`; a pattern that throws or a non-UTF-8 value → field dropped (fail closed), never copied raw in `tests/Unit/Privacy/RedactorTest.php`
- [x] T058 [P] [US2] Test that `LogEvent::$fields`, `labels` and samples are never reachable from `RedactedGroup` (reflection on the class: only the allowlisted properties exist) in `tests/Unit/Privacy/RedactedGroupTest.php`

### Implementation

- [x] T059 [US2] Implement `Redactor` (allowlist projection + Normalizer-table scan + extra patterns, fail closed) producing `RedactedGroup` in `src/Privacy/Redactor.php`
- [x] T060 [US2] Route every group through `Redactor` before Selector output reaches the analyzer or renderer; persist templates only inside redacted `Finding`s in `src/Watcher.php`

**Checkpoint**: MVP = US1 + US2 green; quickstart scenarios 1–5 pass.

---

## Phase 5: User Story 3 — AI spend stays small and visible (P2)

**Goal**: tiered routing, verdict reuse, budgets and a usage footer.

**Independent Test**: `FakeAnalyzer` records model tier and input size per request; routing table,
caps and footer are asserted (quickstart scenarios 12–14).

### Tests first

- [x] T061 [P] [US3] Test `ModelRouter`: all candidates go to triage in one request; only `interesting` go to analyze, at most `top_m`; escalation only for severity ≥ error AND confidence < `confidence_threshold`, once per finding, at most `escalation_cap` per run in `tests/Unit/Analyze/ModelRouterTest.php`
- [x] T062 [P] [US3] Test `Budget`: estimate = UTF-8 bytes / 3; when over `max_input_tokens[step]` drop lowest-priority candidates and report count; daily `Spend` ≥ `daily_budget_usd` → triage only + "budget exhausted" in `tests/Unit/Analyze/BudgetTest.php`
- [x] T063 [P] [US3] Test verdict reuse: group with stored verdict and `count < growth_factor × verdict.atCount` is not sent to any model; above it → re-triaged in `tests/Unit/Analyze/VerdictCacheTest.php`
- [x] T064 [P] [US3] Test `CompactPayload`: each template sent once and referenced by index, unindented JSON, short keys, ≤ 5 frames; fixture of 500 groups stays under the configured `max_input_tokens` after truncation in `tests/Unit/Analyze/CompactPayloadTest.php`
- [x] T065 [P] [US3] Test `AnthropicAnalyzer::triage()` request shape (triage schema `{verdicts:[{g,v,why≤100}]}`), and that the stable system prefix carries `cache_control: {type: "ephemeral"}`; usage cache fields mapped in `tests/Unit/Analyze/AnthropicAnalyzerTriageTest.php`
- [x] T066 [P] [US3] Test `CostCalculator` from `analysis.prices` (input, output, cache_read, cache_write per MTok) and footer line `tokens in / out / cached · model per step · ≈ $` in `tests/Unit/Analyze/CostCalculatorTest.php`
- [x] T067 [P] [US3] Test `BudgetAlerts` (FR-046): table of (before, after, limit, thresholds) → highest newly crossed percent or none (24→26% = 25; 49→80% = 75 only; already announced 50 then 55% = none; 91% = 90); cost and token counters independent; each (counter, percent) announced once per day (state key `alert:…`), the next day announces again; a run with no model call changes nothing; limit null for tokens → no token alerts; the notice text names counter, percent, absolute value and limit; the digest footer shows the day's total and percentage in `tests/Unit/Analyze/BudgetAlertsTest.php`
- [x] T068 [P] [US3] Test config rules for FR-046: `analysis.prices` required for every configured model (missing → InvalidConfig naming `analysis.prices.<role>`), `budget_alert_percents` integers 1–99 ascending (default 25/50/75/90), `daily_token_limit` null or positive integer in `tests/Unit/Config/WatchConfigTest.php`
- [x] T069 [US3] End-to-end scenarios 12 (budget exhausted), 13 (truncation line), 14 (single escalation, cap respected) in `tests/EndToEnd/CostTest.php`

### Implementation

- [x] T070 [P] [US3] Implement `CompactPayload` in `src/Analyze/CompactPayload.php`
- [x] T071 [P] [US3] Implement `Budget`, `CostCalculator`, `Usage` aggregation, `Spend` via `StateStore::increment` in `src/Analyze/`
- [x] T072 [US3] Implement `BudgetAlerts` and make `Spend` keep both counters (`usd`, `tokens`) via `StateStore::increment`; add `daily_token_limit`, `budget_alert_percents` and required `prices` validation to `WatchConfig`; compute alerts after every model call, send ONE notice (highest newly crossed percent) to the notice destination, mark `alert:` keys with a 2-day TTL, and show the day's total and percentage in the digest footer in `src/Analyze/BudgetAlerts.php`, `src/Analyze/Budget.php`, `src/Config/WatchConfig.php`, `src/Notify/DigestRenderer.php`
- [x] T073 [US3] Add `triage()` to `AnthropicAnalyzer` with `src/Analyze/prompts/triage.md`, `cache_control` on the stable prefix (verify the SDK passes it; if not, use the SDK's raw-parameter option) in `src/Analyze/AnthropicAnalyzer.php`
- [x] T074 [US3] Implement `ModelRouter` (triage → analyze → escalate) and verdict reuse via `verdict:` keys in `src/Analyze/ModelRouter.php`, `src/Analyze/VerdictCache.php`
- [x] T075 [US3] Wire router, budget and footer into the pipeline; `RunResult` carries `Usage` in `src/Watcher.php`, `src/Notify/DigestRenderer.php`

---

## Phase 6: User Story 4 — One package, any service, any environment (P2)

**Goal**: second source, all three stores, tags and watch rules, mute list, `run_at` gate,
framework adapter and plain CLI.

**Independent Test**: same build, two configs (Loki vs Datadog, different model maps) both produce
digests; quickstart scenarios 6–9.

### Tests first

- [x] T076 [P] [US4] Write `DatadogSourceTest extends LogSourceContractTest` with fixtures for `POST /api/v2/logs/analytics/aggregate` (`compute count total`, `group_by facet status`) and `POST /api/v2/logs/events/search` (`page.limit` 1000, cursor → `meta.page.after`), headers `DD-API-KEY` + `DD-APPLICATION-KEY`, base `https://api.<site>`, 429 with `X-RateLimit-Reset` retried ≤ 3 times then `SourceUnavailable` in `tests/Contract/DatadogSourceTest.php`, `tests/Fixtures/datadog/`
- [x] T077 [P] [US4] Write `RedisStateStoreTest` (skips without `WATCHER_TEST_REDIS`) and `PdoStateStoreTest` (SQLite in-memory) extending `StateStoreContractTest` in `tests/Contract/`
- [x] T078 [P] [US4] Test `RunGate`: `run_at` 06:00 + timezone; trigger at 05:00 → not due; at 06:00–06:59 → due with window ending at 06:00 local; DST change day handled in `tests/Unit/Schedule/RunGateTest.php`
- [x] T079 [P] [US4] Test stream tags build the Loki selector `{app="orders-api",env="stg"}` and the Datadog query `app:orders-api env:stg`; watch rules in explicit form only — `template` regex, `error_type` regex, `field` + `equals`|`matches` evaluated locally — make a warning group a candidate with reason `watch_rule`, never filter others out, and only the rule name appears in `watchMatches`; config with a free-form rule or two matcher keys is rejected in `tests/Unit/Select/WatchRulesTest.php`
- [x] T080 [P] [US4] Test `MuteList`: fingerprint entries and matcher entries (same explicit form as watch rules) exclude groups from candidates and from analyzer input; counted as "N muted"; entry with past `until` no longer applies in `tests/Unit/Select/MuteListTest.php`
- [x] T081 [P] [US4] Test `RunCommand` (`watcher:run --now=`) returns `RunResult::exitCode()` and config is read from `config/watcher.php`; invalid config → exit 2 with the key name and no external call (FR-015) in `tests/Unit/Laravel/RunCommandTest.php`
- [x] T082 [US4] End-to-end scenarios 6 (watch rule), 7 (mute then expiry), 8 (not due → no external call), 9 (two concurrent runners → one `reported`, one `skipped_locked`) in `tests/EndToEnd/FlexibilityTest.php`

### Implementation

- [x] T083 [P] [US4] Implement `DatadogSource` in `src/Source/DatadogSource.php`
- [x] T084 [P] [US4] Implement `RedisStateStore` (`SET NX EX`, `INCRBYFLOAT`+`EXPIRE`, compare-and-delete Lua release) in `src/State/RedisStateStore.php`
- [x] T085 [P] [US4] Implement `PdoStateStore` (table `watcher_state(key VARCHAR(191) PK, value TEXT, expires_at BIGINT)`, opportunistic purge, insert-as-lock) and `PdoStateStore::createTableSql()` in `src/State/PdoStateStore.php`
- [x] T086 [P] [US4] Implement `RunGate` in `src/Schedule/RunGate.php`
- [x] T087 [P] [US4] Implement `WatchRules`, `MuteList` and selector integration in `src/Select/`
- [x] T088 [US4] Implement driver factories (source, state, notifier) from `WatchConfig` in `src/Config/Factory.php`
- [x] T089 [P] [US4] Implement `WatcherServiceProvider`, `RunCommand`, publishable `config/watcher.php` in `src/Laravel/`
- [x] T090 [P] [US4] Implement plain CLI `bin/watcher run --config=… [--now=…]` in `bin/watcher` (exit codes 0/1/2 as `RunResult::exitCode()`; invalid config → 2), with `tests/Unit/CliTest.php`

---

## Phase 7: User Story 5 — Failures are loud, never silent (P3)

**Goal**: every failure produces a notice or a non-zero exit.

**Independent Test**: source, analyzer, store and Slack fail in turn; each yields the notice from
`contracts/slack-messages.md` and the right exit code (quickstart scenarios 10–11).

### Tests first

- [x] T091 [P] [US5] Test failure notices rendering ("source failed: …", "analysis failed: …", "budget exhausted …"; baseline notice already covered in US1); no secret, raw line or field value in any notice in `tests/Unit/Notify/NoticeRendererTest.php`
- [x] T092 [P] [US5] Test notice destination: `notify.notice` used when set, falls back to `notify.digest` (FR-037) in `tests/Unit/Notify/DestinationTest.php`
- [x] T093 [US5] End-to-end: scenario 10 (invalid output twice → one retry → `failed` + "analysis failed"), scenario 11 (source 401 → `failed` + "source failed", exit 1), provider unavailable after SDK retries (5xx/529) → `failed` + "analysis failed: provider unavailable" with the snapshot still written, Slack failure → host logger error + exit 1, state store failure → exit 1, empty window distinguished from unreachable source in `tests/EndToEnd/FailureTest.php`

### Implementation

- [x] T094 [US5] Implement `NoticeRenderer` and notice destination fallback in `src/Notify/`
- [x] T095 [US5] Map `SourceUnavailable`, `AnalyzerFailed` (after one retry), `DeliveryFailed`, store exceptions to `RunOutcome::failed` + notice/host log in `src/Watcher.php`
- [x] T096 [US5] Reliability follow-ups from the US3 wiring review, test first in `tests/EndToEnd/CostTest.php`: (a) when `ModelRouter::route()` throws after some calls already completed, the usage of the completed calls is still priced and added to `Spend` (record per call, not once at the end — e.g. an `Analyzer` decorator that reports each `Usage` to `Spend` as it returns); (b) `BudgetAlerts::check` claims its `alert:` keys before the notice is sent: if the send fails, the claims are released (add `BudgetAlerts::release(Alert)`) so the alert is retried next run instead of being lost for the day; implement in `src/Watcher.php`, `src/Analyze/BudgetAlerts.php`

---

## Phase 8: User Story 6 — Findings come with their context (P2)

**Goal**: dependency context, deploy version, service context, log and code links.

**Independent Test**: two fake services (A calls `http://core-api/…`, core-api logs a new `Lock wait
timeout` group) plus a release change; A's digest names core-api, marks "first seen after <release>",
and every finding has a log link and code links at the deployed commit (quickstart 15–19).

### Tests first

- [x] T097 [US6] Extend the privacy corpus and `tests/EndToEnd/PrivacyTest.php` before any US6 outbound code (Principle I): add to `tests/Fixtures/privacy/corpus.log` URLs carrying personal data (personal subdomain `john-smith.example.com`, `user:pass@host`, email and token in query), a dependency stream with seeded PII, and a `service_context` containing a seeded email; add the new values to `seeded-values.php`; assert none of them reaches the dependency-context part of any `FakeAnalyzer` payload, the service-context prefix, deploy fields, log links or code links
- [x] T098 [P] [US6] Test `DependencyResolver`: declared deps from `dependencies`; hosts from `<URL:host>` in templates; every run reads each declared dep's `totals()` once; candidate referencing a declared host, or a dep whose error total grew ≥ `growth_factor` vs its previous window → dependency lines requested; undeclared host → listed; quiet day → exactly one totals query per dep and zero dependency `lines()` calls in `tests/Unit/Context/DependencyResolverTest.php`
- [x] T099 [P] [US6] Test `DependencyContextReader`: for a triggered dep one `lines()` (totals already read) with level ≥ error capped at `dependency_read_cap` (5,000), top 5 groups by count, redacted through `Redactor`, previous totals from `snap:{service}:dep:{name}:…`; no previous dep snapshot → growth trigger off, context without new/grown marks, snapshot written; source override honoured in `tests/Unit/Context/DependencyContextReaderTest.php`
- [x] T100 [P] [US6] Test `DeployInfo`: release/commit from config stored in each snapshot; release change → new groups marked "first seen after <release>"; with `version_label` set, the deploy moment comes from a `count by (<label>)` fixture (Loki) / `group_by version` fixture (Datadog) in `tests/Unit/Context/DeployInfoTest.php`
- [x] T101 [P] [US6] Test `LogLinkBuilder`: Grafana Explore URL (datasource uid, stream tags, level, `|= "<literal>"`, window) and Datadog `logs?query=…&from_ts=…&to_ts=…`; literal = longest placeholder-free run of the redacted template ≤ 60 chars; no seeded privacy value from the corpus ever appears in a link in `tests/Unit/Context/LogLinkBuilderTest.php`
- [x] T102 [P] [US6] Test `CodeLinkBuilder`: `path_map` `/var/www/html/` → `app/`; GitLab `{web_url}/-/blob/{commit}/{path}#L{line}` and GitHub `{web_url}/blob/{commit}/{path}#L{line}`; vendor/unmapped path → no link; no commit → no link in `tests/Unit/Context/CodeLinkBuilderTest.php`
- [x] T103 [P] [US6] Test `service_context`: ≤ 1,000 chars enforced by config validation, passed through the redaction scan, placed in the stable (cached) prefix of every analysis request in `tests/Unit/Analyze/ServiceContextTest.php`
- [x] T104 [US6] End-to-end scenarios 15–19 (dependency named, undeclared host listed, after-release mark, log link, code links) in `tests/EndToEnd/ContextTest.php`

### Implementation

- [x] T105 [P] [US6] Implement `DependencyResolver` and `DependencyContextReader` in `src/Context/`
- [x] T106 [P] [US6] Implement `DeployInfo` in `src/Context/DeployInfo.php` on top of `LogSource::versions()`; implement `versions()` in `src/Source/LokiSource.php` (`count by (<label>)`) now and in `src/Source/DatadogSource.php` (`group_by version`) — the Datadog half needs US4 T083; until then `LokiSource` is the only source and `LogSourceContractTest` covers both
- [x] T107 [P] [US6] Implement `LogLinkBuilder` and `CodeLinkBuilder` in `src/Context/`
- [x] T108 [US6] Add dependency context, deploy info and service context to the analysis request (`CompactPayload`, `prompts/analyze.md`), and release header, "after <release>", "undeclared dependency" line, log and code links to `DigestRenderer` in `src/Analyze/`, `src/Notify/DigestRenderer.php`
- [x] T109 [US6] Wire context into the pipeline (after selection, before analysis; dependency reads count toward the token budget) in `src/Watcher.php`

---

## Phase 9: Polish & Cross-Cutting

- [x] T110 [P] Write `config/watcher.example.php` with neutral names only (`orders-api`, `billing`, `auth`, `svc.cluster.local`, `https://git.example.com/group/orders-api`; never a real consumer's names — Principle VI) and documented defaults, including default models `claude-haiku-5-5` (triage), `claude-sonnet-5-5` (analyze), `claude-opus-5-5` (escalate) and their prices — the only place model ids appear besides README
- [x] T111 [P] Write `README.md`: install, read-only credentials per source, scheduling (hourly trigger + `run_at`), state drivers (file = single runner only), privacy guarantees, cost model, exit codes
- [x] T112 [P] Add architecture tests: no model id string (`claude-`) under `src/`; no consumer-specific string (`orders-api`, `tenant-a`, `example`) under `src/` or `config/` (Principle VI); `LogSource` implementations issue only GET/read-only POST query endpoints (Loki `query`, `query_range`; Datadog `analytics/aggregate`, `events/search`) — FR-004, Principle II — in `tests/Unit/ArchitectureTest.php`
- [ ] T113 Run the full `specs/001-scheduled-log-watch/quickstart.md` (scenarios 1–14) and gates: `composer test`, `composer stan`, `composer style`, each exit 0
- [ ] T114 Manual smoke with an Operator (quickstart §3) against one orders-api environment; compare the first digest with `orders-api/docs/context/logs/dev-notes` for SC-001; record result in `CHANGELOG.md`

---

## Dependencies & Execution Order

```text
Phase 1 Setup → Phase 2 Foundational → US1 → US2 ═► MVP
                                         └──► US3 (needs US1 analyzer + pipeline)
                                         └──► US4 (needs US1 pipeline; stores/sources independent)
                                         └──► US5 (needs US1 pipeline)
                                         └──► US6 (needs US1 pipeline + US2 Redactor; the Datadog half of T106 needs US4 T083)
                                                         → Phase 8 Polish
```

- US2 depends on US1 (`RedactedGroup`, pipeline) and must land before any real-environment use.
- US3, US4, US5, US6 are independent of each other after US1 (US6 also needs US2); each touches
  `src/Watcher.php` in one small wiring task (T075, T088, T095, T109) — do those sequentially.
- Within a story: tests → value objects → services → wiring.

## Parallel Examples

- **Phase 2**: T006–T013 together; then T014–T018, T022, T024 together.
- **US1**: T026–T038 together, then T039 (privacy layer 1, must be red until the pipeline exists); then T042, T043, T049, T051 together; T045 → T046 → T048 → T050 → T052 → T055.
- **US3**: T061–T066 together; then T070, T071 together.
- **US4**: T076–T081 together; then T083–T087, T089, T090 together.
- **US6**: T097 first (privacy corpus extension, red); then T098–T103 together; then T105–T107 together; T108 → T109.

## Implementation Strategy

1. Setup + Foundational, gates green.
2. US1 → US2 = MVP; validate quickstart 1–5; stop and review with the owner.
3. US3 (cost) next, since every real run spends money.
4. US4 (Datadog, Redis/PDO, adapter, `run_at`) to deploy into orders-api environments.
5. US5 hardening.
6. US6 context (dependencies, deploy version, links), then Polish and the SC-001 comparison.
