---

description: "Task list for Read-only MCP Server (everrest/watcher)"
---

# Tasks: Read-only MCP Server

**Input**: `specs/015-mcp-readonly/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Tests**: REQUIRED (Constitution III). In each task the test is written first and must fail first; the implementation
turns it green. No test touches the network or a real model; the server is driven through `php://memory` streams.

**Format**: `[ID] [P?] [Story] Description`. Paths relative to the repository root. `[P]` = different files, no unfinished
dependency, safe to run in parallel with other `[P]` tasks of the same phase.

**SHARED FILES, SEQUENTIAL** (spec 013, 014 and other agents edit these; run these tasks one at a time, in this order,
each rebased on the then-current file; re-read the file before editing; T021 is **SEQ after Lanes A, C, B and G** have
merged into the base it is rebased on; other tasks rebase on whatever 013/014 have merged by then):

| Order | Task | Shared file(s) touched |
|---|---|---|
| 1 | T017 | `src/Analyze/OutputValidator.php` (extract the command filter) |
| 2 | T018 | `src/Config/WatchConfig.php`, `src/Config/Factory.php` |
| 3 | T020 | `src/State/Keys.php` |
| 4 | T021 | `src/Watcher.php`, `src/Operate/EstimatingAnalyzer.php` (accessor for the figures) |
| 5 | T022 | `src/Operate/Doctor.php` |
| 6 | T019 | `src/Cli/Application.php`, `src/Laravel/McpCommand.php`, `src/Laravel/WatcherServiceProvider.php` |
| 7 | T043 | `src/Reduce/Normalizer.php` (extract the secret-shape matcher, behaviour unchanged) |
| 8 | T054 | the file that holds the `observe.file.path` classifier (extract, behaviour unchanged) |
| 9 | T051 | `src/Config/WatchConfig.php`, `src/Config/Factory.php`, new `src/Config/KeyCatalog.php` (unknown-key checks read it; `mcp.groups`) |
| 10 | T052 | `src/Watcher.php`, `src/RunLedger.php`, `src/RunOutcome.php`, `src/RunResult.php`, `src/Operate/{Heartbeat,Doctor,CheckResult,JsonSummary}.php`, `src/Observe/{EventSchema,EventFactory}.php`, `src/Fleet/{ServiceResult,FleetRunner}.php` (registry constants) |
| 11 | T053 | `src/Cli/Application.php`, `src/Laravel/McpCommand.php` (`--setup`) |

Rows 1-6 are the original order. **Rows 7-11 (amendment 2026-10-25) come after rows 1-6 and after Lanes A, C, B and G**,
each rebased on the then-current file with the file re-read first; T052 is **SEQ after T021 and T022** (same hunks).
Nothing else may edit these files in this spec. `src/Mcp/*` is new and owned by this spec.

**Gate command**: `composer test; echo $?` then `composer stan; echo $?` then `composer style; echo $?`. Read the exit codes.

**No commits, pushes or tags** unless the owner asks. `composer.json` `require` does not change (SC-008).

---

## Phase 0: Gate and decisions

- [X] T001 Run the gate command on the current tree and record the exit codes. Re-read the shared files above. Owner decisions O1-O5 are recorded in spec.md (2026-10-24); no open question remains. Owner decisions O6-O12 (2026-10-25) are recorded in spec.md as well. Decide the schema-validation helper for
  tests (the small walker of plan.md; no dependency). Create `tests/Unit/Mcp/` and
  `tests/Fixtures/mcp/`. (FR-005)
- [X] T002 Read the published MCP specification pages for `initialize` and the stdio transport; record in
  `research.md` R1 the revisions checked, their dates and the final `PROTOCOL_VERSIONS` list. Docs-only. (O5, FR-003)
  Also read the `resources` and `prompts` pages and confirm the shapes recorded in R11 (amendment 2026-10-25; extends the same
  task, no new ID).

## Phase 1: Pure building blocks (all `[P]`, new files only)

- [X] T003 [P] [US4] `tests/Unit/Mcp/JsonRpcTest.php` and `ServerTest.php` (red): one message per line; parse error ->
  `-32700` with `id: null`; batch array -> `-32600`; line over 64 KB discarded to the newline with `-32600` and the server
  continues; request before `initialize` (except `ping`) -> `-32600 not_initialized`; `initialize` echoes a known
  `protocolVersion` or answers the newest; `tools/list`, `ping`; notifications get no output (unknown ones too); methods
  `sampling/*`, `logging/*`, `completion/*`, `roots/*`, `elicitation/*` -> `-32601`, and `resources/*`, `prompts/*` -> `-32601`
  while the `docs` group is off (served with it on: T040); invalid `id` types never echoed;
  stdout receives only protocol lines (a stray `echo` in a tool is caught by an output-buffer assertion); EOF ends the
  loop with 0. Then `src/Mcp/{JsonRpc,Server}.php`. (FR-001..FR-005, US4 AC2-AC4)
- [ ] T004 [P] [US4] `tests/Unit/Mcp/ToolRegistryTest.php` and `ToolSchemaDriftTest.php` (red): the registry lists exactly the
  tools of `contracts/tools.schema.json` for the enabled groups (T041; originally 5, or 6 with `mcp.allow_dry_run`), each with `readOnlyHint: true` and a static
  description; argument validation (unknown property, wrong type, `limit` 0 and 21, `fp` malformed) -> `-32602` naming the
  property and never echoing the value; the allowlists in code equal the schema's properties per tool (drift test, FR-021);
  every schema string is either typed (pattern/enum), `x-text` with `maxLength`, `x-static` or `x-generated` with `maxLength`. Then `src/Mcp/{Tool,ToolRegistry}.php`. (FR-014, FR-021, FR-041)
- [ ] T005 [P] [US3] `tests/Unit/Mcp/OutputGuardTest.php` (red) over `tests/Fixtures/mcp/hostile-strings.php` (at least 40
  strings: instruction override in English/Ukrainian/Russian, fake system and tool-result tags, markdown links and
  images, `curl|sh`, `rm -rf`, `sudo`, backticks, `$(...)`, `paste ... token`, URLs with credentials and query,
  `www.` and bare hosts, e-mail, zero-width, bidi, U+E0000 tag characters, C0/C1 controls, 10 000-character strings,
  JSON-in-JSON, `[withheld]` look-alikes): after the guard no output has a URL/host/e-mail/command span (replaced by
  `[withheld]`), no format or control character, none longer than its cap (cut with `…`); a scanner exception returns
  null; a typed validator turns a mismatch into null. Then `src/Mcp/OutputGuard.php` (uses `Redactor` and the shared
  command filter after T017; until T017 the test uses the filter in place and is re-run). (FR-022, FR-023, SC-002)
- [X] T006 [P] [US3] `tests/Unit/Mcp/EnvelopeTest.php` (red): the exact `notice` sentence (FR-026); `untrusted_data: true`;
  `now` in `...Z` form; built by `json_encode` (a hostile string with quotes, braces and `</tool_result>` round-trips as
  a string and the decoded structure equals the typed array); 16 KB cap drops findings from the end and sets
  `truncated`, never cuts inside a string; errors use only the closed codes and fixed messages. Then `src/Mcp/Envelope.php`. (FR-020, FR-025, FR-026)
- [X] T007 [P] [US4] `tests/Unit/Mcp/ReadOnlyStateStoreTest.php` (red): `get` passes through; `put`, `increment`, `acquire`,
  `release` each throw `ReadOnlyViolation` (the interface has no others) and the class is not `Purgeable`; the exception message holds no key or value. Then
  `src/Mcp/ReadOnlyStateStore.php`. (FR-010)
- [ ] T008 [P] [US5] `tests/Unit/Mcp/DryRunGuardTest.php` (red), a table: no confirm / `confirm_cost` as string `"true"`, `1`,
  `null` -> `cost_not_confirmed`; exhausted spend -> `daily_limit_reached`; estimate above remaining ->
  `would_exceed_daily_limit`; the escalation allowance is added (a fixture where triage+analyze fit but escalation does not -> refused); an escalation model without a price -> refused; estimate within remaining -> allowed and the counter grows by the upper bound;
  N allowed runs never exceed the starting remaining (SC-005); token limit handled the same way; `busy` while running
  and cleared after an exception; `estimate_only` needs no confirmation and does not touch the counter. Fake `Spend` and
  estimator. Then `src/Mcp/DryRunGuard.php`. (FR-035, SC-004, SC-005)
- [ ] T009 [P] [US6] `tests/Unit/Mcp/HygieneSourceTest.php` (red): the default implementation returns null; the tool maps null
  to `available: false, reason: hygiene_not_enabled`. Then `src/Mcp/HygieneSource.php` and `Tools/HygieneTool.php`. (FR-034, R7)

## Phase 2: Shared-file changes (SEQUENTIAL, in the table's order)

- [ ] T017 [US3] **Touches `src/Analyze/OutputValidator.php`.** Existing tests for the command denylist stay green and
  unchanged; add a test that the extracted pure function (`CommandFilter::withhold(string): string`) is the one the
  validator calls. Extract the span-replacing logic into a small class and call it from both places (no second copy).
  Re-run T005. (FR-022, plan risk 3)
- [ ] T018 [US1] **Touches `src/Config/WatchConfig.php`, `Factory.php`.** `tests/Unit/Config/McpConfigTest.php` (red):
  `mcp.allow_dry_run` default false; strict boolean naming `mcp.allow_dry_run`; `WATCHER_MCP_ALLOW_DRY_RUN` via `Env`; any other `mcp` key
  rejected naming `mcp.<key>`; no secret in the section. Then the config and the factory path that builds the read-only
  store, `Spend`, `VerdictCache`, `Doctor` for the server. Rebase on the current files first. (FR-040)
- [ ] T020 [US2] **Touches `src/State/Keys.php`.** Test in the key-expiry table (red): `Keys::LASTREPORT` has the
  `state.retention` expiry and key `lastrep:{key}`; the shared state-store contract suite round-trips a record on every
  driver. Then the constant and builder. Re-read `Keys.php` first. (FR-050)
- [ ] T021 [US2] **Touches `src/Watcher.php`.** `tests/EndToEnd/LastReportTest.php` (red, before any edit): a real run that
  reports 3 findings stores one record with the window, outcome, window, outcome, counts and 3 finding refs (`fp`, `severity`, `title`) in digest order
  (`title` scrubbed, <= 80; no token or cost figure, no kind or status); a quiet run stores counts and no findings; a failed run stores outcome `failed`; a dry run
  writes only to the buffer and the real store is untouched; the record contains no template, line, value, frame, path
  (privacy corpus: seeded values absent from the stored JSON); an unwritable store does not change the outcome (existing
  rule: state faults are logged, run result unchanged). Then `Watcher` builds the record from the values the digest uses
  (not from spec 013; owner decision O1), **SEQ after Lanes A, C, B and G**, writes it with one `put` on a real run and, in a dry run, returns it on the
  run result without writing; `EstimatingAnalyzer` gets a read accessor for the per-step figures that `printEstimate` prints
  (test: the accessor equals the printed table). (FR-050, FR-036, O1)
- [ ] T022 [US1] **Touches `src/Operate/Doctor.php`.** Additions to the doctor tests (red): with `readOnly: true` the
  `state` check performs no `put`, `increment`, `acquire` (a throwing store passes) and reports `read-only probe`, an
  unreadable store `unreadable`; with `readOnly: false` behaviour is byte-identical to today; no `--post-test` path is
  reachable from the read-only entry point. Then the flag. (FR-031, R6)
- [ ] T019 [US1] **Touches `src/Cli/Application.php` and the Laravel adapter.** `tests/Unit/Cli/McpCommandTest.php` (red):
  `mcp` accepts `--config` only (every other option exit 2); invalid config exits 2 with a stderr line naming the setting
  and an empty stdout (SC-007); a `fleet` config is a usage error; the file state driver that cannot be opened read-only
  is refused with a message (FR-012); stdin EOF exits 0; the adapter registers `watcher:mcp` (`src/Laravel/McpCommand.php`, provider). Then `src/Mcp/McpCommand.php`
  and the wiring. (FR-006, FR-012)

## Phase 3: Tools and end-to-end

- [ ] T010 [US1] `tests/Unit/Mcp/StatusToolTest.php` (red): master switch off and `heartbeat.enabled` off -> `disabled` with a spy store seeing no call, other tools `available:false / disabled` (FR-007); healthy, stale, failed and unknown heartbeats map to the
  `state` values and exit semantics of `watcher status`; spend and remaining figures; `last_run.reason` is a text field
  (hostile reason -> filtered, capped at 200); an unreadable store -> `state_unavailable`. Then `Tools/StatusTool.php`. (FR-030, US1 AC1-AC2)
- [ ] T011 [US1] `tests/Unit/Mcp/CheckToolTest.php` (red; depends on T022): the seven checks in order; a fake notifier is
  never constructed or called; no model call; a reason outside the vocabulary becomes `unavailable`; `config` fail makes
  the rest `skip`; any check exception -> `fail` with `internal error (<class>)`. Then `Tools/CheckTool.php`. (FR-031, US1 AC3)
- [ ] T014 [US2] `tests/Unit/Mcp/LatestReportToolTest.php` and `FindingToolTest.php` (red; depends on T020): report
  fields and `limit` (default 10, max 20); `available: false / no_report`; `watcher_finding` returns the allowlist of
  data-model.md for a stored verdict, `found: false` for unknown and for expired (identical bodies), malformed fingerprint
  never reaches the store (a spy store sees no call); `mute_suggestion` is the fixed sentence plus the fingerprint, with
  a hostile title in the same finding not appearing in it; `tpl` and any key outside the allowlist, put into the stored
  record by the test, never appear. Then `Tools/{LatestReport,Finding}Tool.php`, `LastReport.php`. (FR-032, FR-033, US2)
- [ ] T012 [US3] `tests/EndToEnd/McpInjectionTest.php` (red): seed the hostile corpus into verdict, last-report and
  heartbeat records; call every tool through the server; assert on the decoded and raw output the rules of User Story 3
  (AC1-AC7) and SC-002; the privacy corpus of spec 001 seeded values are absent from stdout **and** stderr (SC-003); a
  control run with `OutputGuard` bypassed proves the test sees leaks. (FR-022, SC-002, SC-003)
- [ ] T013 [US4] `tests/Unit/Mcp/NoWriteStaticTest.php` (red): reads `src/Mcp/**/*.php` and fails if it references a
  notifier class, a mutating `StateStore` method, `file_put_contents`, `fopen` with a write mode, `unlink`, `mkdir`,
  `curl_*`, `stream_socket_server`, `Guzzle` outside the allowed entry points (`Tools/CheckTool.php`,
  `Tools/DryRunTool.php`), or `.env` access; a deliberately bad fixture proves it fails. (FR-011, FR-012, FR-015, SC-001)
- [ ] T015 [US4] State-store contract, read-only variant (red): the shared contract suite gets a read-only mode; every
  driver (file, SQL, Redis fake) returns what was written by a writer instance through `ReadOnlyStateStore`, and reading an
  expired entry neither deletes nor writes (file driver mtime and byte hash unchanged). A driver that fails is refused by
  T019. (FR-010, FR-012, R4)
- [ ] T016 [US5] `tests/EndToEnd/McpDryRunTest.php` (red): with a store whose mutators throw, `watcher_dry_run` completes
  (the dry run lives on `DryRunStateStore`); real model calls are counted by a fake analyzer: zero in every refusal path
  of T008 and in `estimate_only`; one confirmed run returns the report shape with `dry_run: true` and `estimate_usd`, no
  rendered digest text, nothing posted (fake notifier), the real store byte-identical; the second call is refused when
  the first consumed the remaining budget; the tool is absent from `tools/list` and `-32602` on call when
  `mcp.allow_dry_run` is false. Also: stdout holds only protocol lines across a dry run and an estimate (the `Watcher` output callable is a discarding closure; assert with a captured `STDOUT` stand-in), and the structured result comes from the run result, never from printed text. Then `Tools/DryRunTool.php` calling `Watcher` with `RunOptions::dryRun()`. If the pipeline
  writes through the real store, stop and report: it is a spec 002 defect (plan risk 1). (FR-035, FR-036, US5, SC-004)
- [ ] T023 [US6] Wire `HygieneTool` into the registry and add the contract entry once spec 006 defines its report
  (`tools.schema.json`, `tools.md`). Until then `available: false` only (T009). (FR-034)

## Phase 4: Documentation and gate

- [ ] T030 [P] Docs: `docs/privacy.md` gets a section "MCP server (spec 015)": what is returned, what never is (template,
  lines, values, frames), the envelope, caps, `[withheld]`, read-only guarantee, last-report record (key, expiry, content)
  in "What is stored", the injection limit (R2a, R2c) under "Limits you must know". `docs/configuration.md`: `mcp.allow_dry_run`
  row and env. `README.md`: spec table row 015, command list. `docs/roadmap.md`: row 015. `CHANGELOG.md`: entry under
  Unreleased. (Principle I: a change touching what leaves the service extends the privacy docs)
- [ ] T031 [P] `docs/mcp.md` from `quickstart.md`: setup, one entry per service, store hardening, assistant profile advice
  (no shell/file tools or per-call approval), what the tools return, how to read `untrusted_data`, cost of the dry run.
- [ ] T032 End-to-end smoke (`tests/EndToEnd/McpProcessTest.php`): start `bin/watcher mcp` with `proc_open` and a fake
  config on a temp file store, send initialize/list/status, assert one JSON line per response, exit 0 on EOF, nothing on
  stderr. Then run the gate command and read the exit codes; run `composer deps-check` if present. (SC-006, SC-008)

## Phase 5 (amendment 2026-10-25): protocol additions, groups, setup mode

Original IDs T001-T032 keep their meaning. New IDs start at T040. Same rules: test first and red, no network, no model, `php://memory`.

- [ ] T040 [P] [US11] `tests/Unit/Mcp/ServerResourcesPromptsTest.php` (red): with the `docs` group on, `initialize` declares
  `resources` and `prompts` (`subscribe:false`, `listChanged:false`) and `resources/list`, `resources/read`,
  `resources/templates/list` (empty), `prompts/list`, `prompts/get` are routed; with it off the capabilities are absent and these
  methods are `-32601`; `resources/subscribe`, `sampling/*`, `logging/*`, `completion/*`, `roots/*`, `elicitation/*` stay
  `-32601`; no `nextCursor`; bad params -> `-32602` fixed message, value not echoed. Then the router entries in `src/Mcp/Server.php`
  (about 60 lines). (FR-003, FR-092, US4 AC3)
- [ ] T041 [P] [US7] `tests/Unit/Mcp/ToolRegistryGroupsTest.php` and the extended `ToolSchemaDriftTest` (red): default groups give 13
  tools, 14 with `mcp.allow_dry_run`; `['inspect']` gives the original 5; `--setup` gives 8 (onboarding + docs); a tool of an off group is
  absent and `-32602` on call; every new tool has `readOnlyHint: true`, `destructiveHint: false`, a static description, and only
  `watcher_rollout_check` has `openWorldHint: true`; allowlists equal `tools.schema.json` for all 14 tools; every schema string is typed,
  `x-text`, `x-static` or `x-generated` with `maxLength`; the `group` field of the schema equals the registry's. Then the group logic in
  `ToolRegistry` and the `Tool::group()` method. (FR-060, FR-014, FR-021)

## Phase 6 (amendment): security first (secret guard, path policy)

- [X] T042 [P] [US8] `tests/Unit/Mcp/SecretGuardTest.php` over `tests/Fixtures/mcp/secret-corpus.php` (red): at least 30 secret-shaped
  values (provider API keys with known prefixes, Slack and Discord webhooks, Telegram bot tokens, `Bearer`/`Basic` values, JWT, PEM,
  `https://user:pass@host`, Redis/PDO DSNs with a password, long mixed-class random runs, the same values with surrounding words,
  percent-encoded and with zero-width characters) are all `looksSecret`; at least 30 near-misses are not (service names such as
  `orders-api`, `WATCHER_ENABLED`, time zones, label values, fingerprints, model-id-like words, file paths, plain English, a 20-letter
  word); the guard throws `SecretRefused` with the fixed message and holds no value. Then `src/Mcp/SecretGuard.php` on top of T043. (FR-064, SC-010)
- [ ] T043 [US8] **Touches `src/Reduce/Normalizer.php`** (SEQ row 7). The existing Normalizer tests stay green and unchanged; add a
  test that the extracted pure matcher (`SecretShapes::matches(string): bool`) is the one the Normalizer calls (no second copy of
  the prefix/JWT/PEM/entropy rules). Add the webhook and bot-token shapes to the table with their own corpus rows (privacy corpus
  extension, Principle I). (FR-064)
- [ ] T054 [US8] **Touches the file with the `observe.file.path` classifier** (SEQ row 8). Existing observe-sink tests stay green and
  unchanged; a test that the extracted `FilePolicy` (owner, mode, not group/world-writable unless sticky, absolute, no `..`) is the
  function both `observe.file.path` and `Mcp\PathPolicy` call. If the logic is private and entangled, extract only the pure check. (FR-067)
- [ ] T044 [P] [US8] `tests/Unit/Mcp/PathPolicyTest.php` over `tests/Fixtures/mcp/path-corpus.php` (red; needs T054): at least 20 hostile
  paths (`..`, `%2e%2e`, absolute outside, backslashes, NUL, 2000 characters, directory, device, `.phtml`, case variants, a symlink
  to `/etc/passwd`, a symlink inside the root to a file inside, a symlink chain, a file world-writable, a file owned by another user
  when testable) give `path_not_allowed` before any read (a spy reader sees no call); roots are compared after `realpath` (the
  `/srv/app` vs `/srv/app-evil` prefix trap); `ConfigFileReader` stops at 256 KB + 1 and returns `file_too_large` without parsing;
  an unreadable file is `file_unreadable`. Then `src/Mcp/{PathPolicy,ConfigFileReader}.php`. (FR-067, SC-014)
- [ ] T045 [US8] `tests/EndToEnd/McpSecretRefusalTest.php` (red): the secret corpus is passed through every string input of every
  onboarding and docs tool (`service`, `environment`, `timezone`, `stream_tags` keys and values, `env_names`, `path`, `query`, `key`,
  `code`) and every prompt argument, through the server over `php://memory`: each is refused with `secret_refused` (tools) or
  `-32602` with the fixed message (prompts); a refused call performs no file access (spy reader) and no state access; the corpus
  values are absent from the raw stdout, the decoded output and stderr (SC-003 style); properties named `token`, `api_key`,
  `webhook`, `password`, `secret` are `-32602` naming the property without the value; an env-name that is not `WATCHER_*` is refused;
  a control run with the guard bypassed proves the test sees a leak. Extends the privacy corpus in the same change (Principle I).
  Then the guard wiring in `ToolRegistry` and the prompt path. (FR-063..FR-066, SC-010)

## Phase 7 (amendment): shared-file hunks (SEQUENTIAL, rows 9-11 of the table) and the two derived tables

- [ ] T051 [US11] **Touches `src/Config/WatchConfig.php`, `Factory.php`, new `src/Config/KeyCatalog.php`.**
  `tests/Unit/Config/KeyCatalogDriftTest.php` and `McpGroupsConfigTest.php` (red, before any edit): (a) every key path set by
  `config/watcher.example.php` and by each file in `docs/examples/` is in the catalog and the validator rejects an unknown sibling at every
  level of the catalog (generated probe); (b) every catalog default equals the value of the loaded `WatchConfig` for a minimal config; (c)
  every backticked key row of `docs/configuration.md` is in the catalog and every non-wildcard catalog key is documented there (both
  ways); (d) `related` entries and `docs` anchors resolve; (e) `mcp.groups`: default `[inspect, onboarding, docs]`, comma list from
  `WATCHER_MCP_GROUPS` via `Env::list`, unknown/duplicate/empty/non-list rejected naming `mcp.groups`; any other `mcp` key still
  rejected naming it. Then the catalog, the unknown-key checks reading it (no message text changes: the existing configuration tests
  are the proof and stay unchanged), and the `mcp.groups` setting. Rebase on the current files first. If extraction would change
  behaviour, stop and report (plan risk 7). (FR-083, FR-085 a-c, FR-095, SC-012)
- [ ] T052 [US11] **Touches the files of row 10.** `tests/Unit/OutcomeRegistryDriftTest.php` (red, before any edit): (a) every
  `RunOutcome` case is registered; (b) a static scan fails on a string literal as the code argument of `fail(`, `failWithNotice(`,
  `setCode(` and on a literal in `Heartbeat`/`Doctor`/`EventSchema`/`ServiceResult` code lists (the failure message names file and
  code); (c) each end-to-end golden scenario of `RefactorGoldenTest` and the doctor/heartbeat/fleet tests produce only registered
  codes (the test collects `reason`, `check_reason`, event `code`); (d) every registry entry has an emit site found by the scan or a
  `reserved_by` in the reservation list (`k8s_ingest_failed` -> `spec-008`), and the reservation list is asserted; (e) `related`
  and `docs` resolve; (f) `OutcomeRegistry::explain('truncated:memory')` resolves to the family. Step 1 of the task is the
  inventory of emit sites (R18 lists the files known today; record the final list here). Then the registry with constants and the
  mechanical replacement of literals; behaviour unchanged (existing tests unchanged and green). **SEQ after T021, T022 and Lanes A, C, B, G.**
  (FR-084, FR-085 d-e, SC-013)
- [ ] T053 [US7] **Touches `src/Cli/Application.php`, `src/Laravel/McpCommand.php`.** `tests/Unit/Cli/McpSetupModeTest.php` (red):
  `--setup` and `--config` together or neither is exit 2 with a stderr line and empty stdout; `--setup` starts with no configuration,
  no state store, no HTTP client constructed (spy factory), serves 8 tools, `service: null` in the envelope; allowed root is the
  current directory (`chdir` in the test); every other option is exit 2; the adapter registers `watcher:mcp` with `--setup`. Then the
  wiring in `McpCommand`. After T019. (FR-061, cli.md)

## Phase 8 (amendment): setup copilot tools

- [ ] T056 [P] [US7] `tests/Unit/Mcp/SetupPlannerTest.php` (red): golden plans for six representative combinations
  (`tests/Fixtures/mcp/plans/*.golden.json`) and a structural test over all 54 combinations of host x source x notifier x state: fixed core
  order of FR-070; every step has `collect`, `where_to_find`, `verify`, `rollback`; every `env_vars` entry is `WATCHER_*`; every
  `verify.expect` is a registered `OutcomeRegistry` code; commands match the host (`php artisan watcher:check` for Laravel, `vendor/bin/watcher
  check --config=<config>` otherwise); Loki steps name the label browser, the proxy URL form and the Viewer role; Datadog names
  `logs_read_data`; Redis names the database index / prefix; `file` carries the single-runner note; `features` append after step 9;
  the text contains no URL with a credential, no model id, no price; a missing/unknown/extra property is `-32602`; the same
  input twice is byte-identical. `watcher_first_day`: the six steps in order with expected codes `baselined`, `reported`/`quiet`,
  `skipped_done`. Then `SetupPlanner`, `SetupCatalog`, `Tools/{SetupPlan,FirstDay}Tool.php`. (FR-070, FR-073, US7, US12 AC7)
- [ ] T058 [P] [US10] `tests/Unit/Mcp/ConfigGeneratorTest.php` (red): for all 54 combinations the snippet is valid PHP
  (`token_get_all($code, TOKEN_PARSE)`), and with the listed variables set to dummy values by the test it loads through
  `WatchConfig::fromArray` without `InvalidConfig` (no file written by the server: the test writes its own temp file); `enabled` reads
  `WATCHER_ENABLED` with default false; every credential is a `getenv`/`Env`/`env()` call; `env_vars` have `secret` flags and no value field
  (schema check); no model id or price literal in the snippet; hostile inputs (quotes, `$`, backticks, `?>`, newlines, NUL, Unicode
  look-alikes, 10 000 characters) are `-32602` and never reach output; the `file` state adds the single-runner note; an
  `env_names` override must match `WATCHER_*`; identical input gives identical bytes. Then `ConfigGenerator` and `Tools/GenerateConfigTool.php`. (FR-072, US10, SC-011)
- [ ] T059 [P] [US9] `tests/Unit/Mcp/ConfigFileValidatorTest.php` over `tests/Fixtures/mcp/config/*.php` (red; needs T044, T051): valid file
  -> `valid:true` and `flags_off` incl. `enabled`; unknown key (`selection.min_levl`) -> `unknown_key` with the key path and
  `did_you_mean`; missing price -> `invalid_value` naming `analysis.prices`; literal secrets at `analysis.api_key`,
  `notify.digest.webhook`, `notify.digest.bot_token`, `source.loki.auth.token` -> `plain_text_secret` with the key path, and the literal
  is absent from the raw and decoded output; syntax error and a throwing file -> `config_file_error` with no exception message; 300 KB
  -> `file_too_large`; symlinks in/out of the root as in T044; a counting HTTP client proves zero requests and a spy notifier zero calls;
  output holds no string literal of the file other than key paths (a differential test over all fixtures). Then `ConfigFileValidator`,
  `SecretLiteralScan` (when `token_get_all` is missing the scan is skipped and the result says `secret_scan: skipped`, never silently) and
  `Tools/ValidateConfigTool.php`. (FR-066, FR-071, US9)
- [ ] T060 [P] [US12] `tests/Unit/Mcp/RolloutInspectorTest.php` (red; needs T044): PHP below the floor fails with `php 8.2 required`
  (version injected); extension matrix by state driver with an injected `extension_loaded` callable (`redis` for phpredis/auto,
  predis class fallback, `pdo` + driver, `zlib` warn); Composer constraint fixtures (missing, `^0.1`, allows installed, excludes
  installed); config absent/present/invalid; master switch `enabled=false` is `warn`-free info with reason `enabled=false`, `true`
  -> `enabled=true`; `connect:false` -> a spy store sees zero calls; `connect:true` -> exactly one `get` through `ReadOnlyStateStore`
  (mutators throw), unreadable -> `unreadable`, and `connect:true` with a path outside the roots is `path_not_allowed` with no
  connection. Then `RolloutInspector` and `Tools/RolloutCheckTool.php`. (FR-074, US12 AC8)

## Phase 9 (amendment): docs, search, explain, prompts

- [ ] T061 [P] [US11] `tests/Unit/Mcp/DocsIndexTest.php` (red): the allowlist names exist in the repository (`docs/mcp.md` after T031) and none of them is in
  `.gitattributes` `export-ignore` (reads the file); `resources/read` returns the file bytes unchanged; the path corpus of T044
  adapted to URIs (`watcher-docs://../.env`, `%2e%2e`, absolute, backslash, NUL, other scheme, case variants, a name that is a prefix
  of an allowed one) gives `-32602 resource_not_found` and a spy filesystem sees no read outside the allowlist; a stripped root
  (temp dir with some files missing) gives a shorter `resources/list`, `docs_not_installed` for search, and no failure; files over 512 KB are skipped;
  search is deterministic (score, file, position), at most 10 results, snippet <= 240, response <= 16 KB, every returned `file` + `anchor` exists as a heading in the file,
  the query limits (2..200, 8 terms) -> `-32602`; served files contain no full-length secret-shaped string (placeholders with `...` allowed); the first
  search builds the index in under 1 s. Then `DocsAllowlist`, `DocsIndex`, `Tools/SearchDocsTool.php` and the resources routing. (FR-080..FR-082, FR-086, US11, SC-014, SC-017)
- [ ] T062 [P] [US11] `tests/Unit/Mcp/ConfigExplainerTest.php` and `ExplainOutcomeTest.php` (red; needs T051, T052): one known key per
  catalog `type`; a wildcard map entry (`source.stream_tags.app`); `secret` flag true for the credential keys; unknown key ->
  `found:false`, at most three `did_you_mean`, none equal to the input, input not echoed; `selection.growth_factor` default equals the
  validator's; outcome codes `disabled`, `skipped_done`, `baselined`, `zero_events`, `lock_lost`, `analysis_refused`,
  `state_unavailable`, `k8s_ingest_failed:oomkilled` (reserved, `reserved_by: spec-008`), `truncated:deadline` (family); an unregistered
  code is `found:false`; no code lists all codes (bounded, no prose); secret-shaped `key`/`code` is refused. Then `ConfigExplainer`, `Tools/{ExplainConfig,ExplainOutcome}Tool.php`. (FR-083, FR-084, US11 AC1-AC4)
- [ ] T063 [P] [US12] `tests/Unit/Mcp/PromptCatalogTest.php` with `tests/Fixtures/mcp/prompts/*.golden` (red): `prompts/list` shows the
  four prompts when their tools are enabled and omits a prompt whose tools are off (`['docs']` only: `onboard-new-service` is absent);
  `cost-too-high` omits the dry-run step when `watcher_dry_run` is not enabled; `prompts/get` renders the golden text for each
  argument combination; enumerations validated (unknown -> `-32602` naming the argument); a secret-shaped argument -> `-32602` with the
  fixed message; every text contains the standing sentence and names only tools present in `tools/list` for the same groups; no state, log
  or file data is in the text (the renderer takes enumerations only). Then `PromptCatalog` and the routing. (FR-090, FR-091, US12, SC-018)

## Phase 10 (amendment): drift gates, conversation test, docs, gate

- [ ] T064 [US11] `tests/Unit/Mcp/ContractDriftTest.php` (red): the `## watcher_*` headings of `contracts/tools.md` equal the registry's tool
  names; the resource table equals `DocsAllowlist`; the prompt table equals `PromptCatalog`; the closed error-code list of `tools.md` equals the
  `Envelope` set; each `docs` reference of `SetupCatalog`, `KeyCatalog`, `OutcomeRegistry` and `PromptCatalog` resolves to an existing anchor
  of an allowlisted file (excluding `docs/mcp.md` until T066 is done: then included). Then fix whichever side drifted. (FR-085, SC-012, SC-013, SC-018)
- [ ] T065 [US7] `tests/EndToEnd/McpOnboardingConversationTest.php` (red; the worked example of the quickstart as a script): in `--setup` mode over
  `php://memory`: initialize, `tools/list` (8 tools), `prompts/get onboard-new-service`, `watcher_setup_plan`, `watcher_generate_config`
  (the test writes the snippet to its own temp file under the working directory), `watcher_validate_config` on it with the dummy env set by
  the test, `watcher_rollout_check` (no `connect`), `watcher_first_day`, `watcher_explain_outcome baselined`, a deliberate secret paste (refused),
  an unknown config key (explained as unknown); assert no refusal in the happy path, one refusal for the paste, protocol-only stdout, empty stderr,
  and a scan of every frame with the secret corpus and `SecretGuard` finds nothing. (US7 AC7, SC-015)
- [ ] T066 [P] Docs for the amendment: `docs/mcp.md` (T031) gains "Setup copilot" and "Ask the package" sections and the `--setup` entry; `docs/configuration.md`
  gets the `mcp.groups` and `mcp.allow_dry_run` rows, the `WATCHER_MCP_GROUPS` variable and a one-line note that `KeyCatalog` is the
  source of the key list; `docs/privacy.md` MCP section (T030) gains: the setup tools handle no log or model text, the secret rule and what a
  pasted secret means (A13), the path policy and "the config file is executed" (R14), no model call and no network except `connect`; `docs/README.md`
  gets a `mcp.md` row; `README.md` spec row 015 and the command list (`watcher mcp --config` / `--setup`); `CHANGELOG.md` Unreleased entry (Added: groups, setup
  mode, eight tools, resources and prompts; Changed (internal): outcome registry and key catalog, no behaviour change); `docs/roadmap.md` row 015 stays the status
  source. Must follow T062 so the examples are real. (Principle I: a change that touches what leaves the service extends the privacy docs)
- [ ] T067 Gate for the amendment: `composer test; echo $?`, `composer stan; echo $?`, `composer style; echo $?`, read each exit code; run the three drift tests
  (T051, T052, T064) by name; `git diff --stat composer.json composer.lock` is empty (SC-016); `composer deps-check` if present; re-run the
  checklist `checklists/requirements.md` and tick or explain each line; grep the pack for "six"/"five" tool counts.

## Dependencies

```text
T001 -> everything              T002 -> T003
T003, T004, T005, T006, T007, T008, T009 : parallel
T017 -> T005 final run          T018 -> T019, T016
T020 -> T021 -> T014            T022 -> T011
T007 -> T015 -> T019            T008 + T016 -> dry run
T010..T016, T023 -> T012 -> T032
T030, T031 after the behaviour is fixed (after T016)

Amendment (2026-10-25):
T040, T041 : parallel, new files            T041 -> T045 -> T056, T058, T059, T060, T061, T062, T063
T043 -> T042 final run                      T054 -> T044 -> T059, T060
T042, T044 -> T045                          T018 -> T051 -> T059, T062
T021, T022 -> T052 -> T056 (expect codes), T062         T019 -> T053 -> T065
T040 -> T061, T063 (routing)                T056..T063 -> T064 -> T065 -> T066 -> T067
T066 after T062 and after T031 (docs/mcp.md exists)
```
