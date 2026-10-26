# Implementation Plan: Read-only MCP Server

**Branch**: `015-mcp-readonly` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

## Summary

One new command, `watcher mcp`, serves up to fourteen read-only tools (three groups: `inspect`, `onboarding`, `docs`; amended
2026-10-25) plus resources and prompts over stdio with a hand-written JSON-RPC loop. The server is a
thin reader over classes that already exist (`HeartbeatStatus`, `Doctor`, `Spend`, `VerdictCache`, the estimate path of
`--dry-run`) on a state store that refuses writes. Every string that came from a log or a model leaves through one
`OutputGuard` and one `Envelope`. The only changes to the run itself are one small last-report record (R3, owner decision
O1) and, for the 2026-10-25 amendment, the replacement of string-literal codes by `OutcomeRegistry` constants (R18) and the
extraction of `Config\KeyCatalog` (R17); neither changes behaviour. The amendment adds a setup copilot (plan, validate,
generate, first day, rollout check), documentation resources with a plain-text search, and two code-derived explain tables
(`KeyCatalog`, `OutcomeRegistry`) guarded by drift tests; it never receives or returns a secret (R12). No dependency is added.

## Technical Context

**Language**: PHP ^8.2 (the package floor since 0.1.2; corrected from ^8.1), no framework in the core; `ext-json` only. `ext-intl` stays optional (as in `docs/privacy.md`); `ext-tokenizer` is used only behind `function_exists('token_get_all')` (plain-text-secret scan, R14).
**New dependencies**: none (FR-005). JSON Schema validation in tests is a 40-line walker over the few keywords used (type, enum, pattern, required,
additionalProperties, min/max); decided in T001.
**Testing**: PHPUnit; no network (the Guzzle `MockHandler` and fakes of 002); the server is driven through
in-memory streams (`php://memory`), never a real process, except one smoke test in T032 that starts `bin/watcher mcp`
with `proc_open` and a fake config.
**Target**: any host that runs `bin/watcher`; the Laravel adapter exposes `watcher:mcp`.
**Constraints**: stdout is protocol only; 16 KB response cap; 64 KB line cap; no write to any store or file.

## Constitution Check

| Principle | Status | How |
|---|---|---|
| I Privacy fails closed | pass | Allowlisted output per tool; text goes through the `Redactor` second scan, a scan failure drops the field; no template, line, field value, frame or path leaves; the privacy corpus is extended (T012, SC-003). Stored items older than this feature are re-scanned at output. |
| II Read-only | pass, stricter | Writes nothing, not even own state: `ReadOnlyStateStore`, no notifier, no file write (FR-010..013). The one added write is by the *run* (last-report record), its own state. |
| III Test-first | pass | Every task starts red. Mandatory suites: injection corpus, privacy corpus, read-only (throwing store), dry-run refusal table, shared state-store contract (read-only variant). No network. |
| IV Code decides, model judges | pass | The server calls no model except inside a confirmed dry run; what it shows is chosen by code (allowlist, caps, limit). The mute suggestion is code-built. |
| V Cost discipline | pass | Default tools make zero model calls. The cost-bearing tool is off by default, needs a strict confirmation, checks the daily limit with the estimate's upper bound and an in-process counter (R5). |
| VI Configuration and contracts | pass | One setting (`mcp.allow_dry_run`), validated at start, naming the key. One seam (`HygieneSource`) because the spec names two states. Nothing consumer-specific. |
| VII Failures loud | pass | Every failure is a typed error code or a JSON-RPC error; "no report" (`available:false`) is distinct from "failed". Store errors are `state_unavailable`, never an empty success. |
| VIII Simplicity | pass | No SDK, no transport abstraction. Resources/prompts are five more router entries (R11). `Tool` is a contract because fourteen implementations exist. The command filter is extracted and shared (T017), not copied; `SecretGuard` reuses the Normalizer rules, not a second table. Search is lexical, no embeddings (R16). New classes (`SetupPlanner`, `ConfigGenerator`, `ConfigFileValidator`, `RolloutInspector`, `ConfigExplainer`, `DocsIndex`, `PromptCatalog`) each own one tool group's logic; `KeyCatalog` and `OutcomeRegistry` replace scattered strings, they do not add a layer. |
| I (amendment) | pass | The new tools handle no log text and no model text. The secret rule (R12) is structural: no credential property, no value field, `WATCHER_*` names only, guard before schema; the privacy corpus is extended with the secret corpus (T045, SC-010). Docs served from an allowlist, never a built path (FR-081). |
| II (amendment) | pass | No write, no state, no model, no network except `connect: true` (R13). The one new surface, including a user-chosen PHP file, is bounded by the path policy (R14, FR-067). |
| III (amendment) | pass | Every task starts red; mandatory new suites: secret corpus, path corpus, generator round-trip, drift gates (keys, defaults, docs, codes), prompt goldens. No network, no model. |
| IV (amendment) | pass | Answers are chosen by code (tables, index). No model in the server. |
| VI (amendment) | pass | One new setting (`mcp.groups`) validated at start; the catalog states no model id or price; nothing consumer-specific (examples use `orders-api` only where the shipped docs already do). |
| VII (amendment) | pass | An unknown key or code is `found: false`, never a guess; a stripped install says `docs_not_installed`; a refused secret is a typed error, not a silent drop. |

No complexity-tracking entries.

## Project Structure

```text
specs/015-mcp-readonly/   spec.md plan.md research.md data-model.md quickstart.md tasks.md checklists/ contracts/
src/Mcp/                  McpCommand Server JsonRpc Tool ToolRegistry Envelope OutputGuard ReadOnlyStateStore
                          LastReport HygieneSource DryRunGuard Tools/{Status,Check,LatestReport,Finding,Hygiene,DryRun}Tool
                          (2026-10-25) SecretGuard PathPolicy ConfigFileReader SetupPlanner SetupCatalog ConfigGenerator
                          ConfigFileValidator SecretLiteralScan RolloutInspector ConfigExplainer DocsAllowlist DocsIndex
                          PromptCatalog Tools/{SetupPlan,ValidateConfig,GenerateConfig,FirstDay,RolloutCheck,SearchDocs,ExplainConfig,ExplainOutcome}Tool
src/Config/KeyCatalog.php (new; WatchConfig reads it)     src/OutcomeRegistry.php (new; emit sites use its constants)
src/State/Keys.php        + LASTREPORT                       (shared file)
src/Watcher.php           + build/write the last-report record, expose it and the estimate figures on the run result (shared file)
                          + OutcomeRegistry constants at every fail()/failWithNotice()/setCode() call (shared file, T052)
src/RunLedger.php, src/RunOutcome.php, src/RunResult.php, src/Operate/{Heartbeat,JsonSummary}.php, src/Observe/{EventSchema,EventFactory}.php,
src/Fleet/{ServiceResult,FleetRunner}.php   + registry constants / registration of their codes (shared files, T052)
src/Config/WatchConfig.php + unknown-key checks read KeyCatalog (shared file, T051)
src/Operate/Doctor.php    + readOnly flag for the state check (shared file)
src/Config/WatchConfig.php, Factory.php   + mcp section       (shared files)
src/Cli/Application.php   + 'mcp' command                     (shared file)
src/Laravel/McpCommand.php + registration in WatcherServiceProvider (beside CheckCommand, StatusCommand)
tests/Unit/Mcp/, tests/EndToEnd/McpTest.php, tests/Fixtures/mcp/hostile-strings.php
tests/Fixtures/mcp/{secret-corpus,path-corpus}.php, tests/Fixtures/mcp/prompts/*.golden, tests/Fixtures/mcp/config/*.php
tests/Unit/Config/KeyCatalogDriftTest.php, tests/Unit/OutcomeRegistryDriftTest.php
docs/privacy.md docs/configuration.md docs/mcp.md README.md CHANGELOG.md docs/roadmap.md   (docs tasks)
```

## Shared files and ordering

Specs 013 and 014 and Lanes A, C, B, G edit `src/Watcher.php`, `src/State/Keys.php`, `src/Operate/Doctor.php`, `src/Config/*`, `src/Notify/*` and
`src/Cli/*`. Spec 014 touches the schedule path of `Watcher`. 015's edits to those files are small and come **after**
013's T014/T016/T017 (and 014 if merged first), each rebased on the then-current file with the file re-read first.
Everything under `src/Mcp/` is new and owned by this spec. The 2026-10-25 amendment adds shared-file hunks that are all **SEQ after
the original six** and after Lanes A, C, B and G (T051 `WatchConfig`, T052 `Watcher`/`RunLedger`/`Heartbeat`/`Doctor`/event/fleet files,
T053 `Application`); they change no behaviour and are pinned by the unchanged existing tests. The `src/Watcher.php` hunk (T021) is the latest task and is marked SEQ after Lanes A (decompose), C, B and G; the
last-report record is built from the values the digest uses and does not depend on spec 013 (owner decision O1).

## Risks

1. **Dry run writes through the real store** (lock, run record). R4/T016. `DryRunStateStore` should prevent it; the test proves it. If it does not: fix in spec 002's code first.
1a. **Dry run prints to stdout**: solved by the output callable (R5a), pinned by T016.
2. **File state driver side effects on read** (purge on read). T015; fallback: refuse to start with that driver.
3. **Command filter location**: the denylist and the `[withheld]` replacement live in `src/Analyze/OutputValidator.php` (private, applied to three fields by `text($v,$path,$max,advice)`); T017 extracts it with its tests
   unchanged. If it is entangled with severity logic, extract only the pure span-replacing function.
4. **MCP revision drift**: the client may require a newer revision for features not used here; mitigated by R1 (fixed
   list, answer with a supported version).
5. **Injection is not solved by filters** (R2c): documented as a limit, with the profile advice in T031.
6. **006 is Draft**: tools depend on 006 only through `HygieneSource`; nothing depends on 013.
7. **KeyCatalog extraction changes behaviour** (R17): the inline checks of `WatchConfig` are entangled with value checks. Mitigation: the catalog
   drives only the unknown-key rejection and the explanation; the existing configuration tests stay unchanged and green; if a
   message text would change, stop and report.
8. **Executing a user-chosen config file** (R14): bounded by the path policy; the path corpus (SC-014) is the proof; if the shared
   file classifier of `observe.file.path` cannot be reused, extract it first (T040), do not copy it.
9. **Secret guard false negatives** (A6): a secret that matches no shape would pass the guard but still cannot reach an output
   (no value field) or a snippet (strict patterns). The residual is a secret echoed *back by the client* in its own words; out of the
   package's control.
10. **Registry gate noise** (R18): a new code added in a parallel spec fails the static test until registered. Intended: the
    failure message names the file and the code.
11. **Docs drift in the setup plan**: plan steps cite docs anchors; a docs rewrite fails the anchor check of T064 instead of
    shipping a dangling reference.
12. **MCP revision drift** for resources/prompts shapes (R11): T002 confirms; only the oldest common shape is emitted.

## Ordering of work

Phase 0 gate -> Phase 1 pure blocks in parallel -> Phase 2 shared-file changes in order -> Phase 3 tools and end-to-end ->
Phase 4 docs and gate. Amendment (2026-10-25), appended as Phases 5-10 so existing IDs keep their meaning: Phase 5 protocol,
groups and setup mode -> Phase 6 security (secret guard, path policy) -> Phase 7 shared-file hunks (SEQ) and the two
tables -> Phase 8 setup tools -> Phase 9 docs tools, resources, prompts -> Phase 10 drift gates, docs and quickstart.
Details and tests in [tasks.md](tasks.md).
