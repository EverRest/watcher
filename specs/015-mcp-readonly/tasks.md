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
each rebased on the then-current file; re-read the file before editing; start only after 013 T014/T016/T017 are merged,
or rebase on them):

| Order | Task | Shared file(s) touched |
|---|---|---|
| 1 | T017 | `src/Analyze/OutputValidator.php` (extract the command filter) |
| 2 | T018 | `src/Config/WatchConfig.php`, `src/Config/Factory.php` |
| 3 | T020 | `src/State/Keys.php` |
| 4 | T021 | `src/Watcher.php`, `src/Operate/EstimatingAnalyzer.php` (accessor for the figures) |
| 5 | T022 | `src/Operate/Doctor.php` |
| 6 | T019 | `src/Cli/Application.php`, `src/Laravel/McpCommand.php`, `src/Laravel/WatcherServiceProvider.php` |

Nothing else may edit these files in this spec. `src/Mcp/*` is new and owned by this spec.

**Gate command**: `composer test; echo $?` then `composer stan; echo $?` then `composer style; echo $?`. Read the exit codes.

**No commits, pushes or tags** unless the owner asks. `composer.json` `require` does not change (SC-008).

---

## Phase 0: Gate and decisions

- [ ] T001 Run the gate command on the current tree and record the exit codes. Re-read the shared files above. Confirm the
  owner's answers to O1-O5 (spec.md); T020-T021 and T016 depend on O1/O2/O3. Decide the schema-validation helper for
  tests (reuse `opis/json-schema` if 013 added it, else the small walker of plan.md). Create `tests/Unit/Mcp/` and
  `tests/Fixtures/mcp/`. (FR-005)
- [ ] T002 Read the published MCP specification pages for `initialize` and the stdio transport; record in
  `research.md` R1 the revisions checked, their dates and the final `PROTOCOL_VERSIONS` list. Docs-only. (O5, FR-003)

## Phase 1: Pure building blocks (all `[P]`, new files only)

- [ ] T003 [P] [US4] `tests/Unit/Mcp/JsonRpcTest.php` and `ServerTest.php` (red): one message per line; parse error ->
  `-32700` with `id: null`; batch array -> `-32600`; line over 64 KB discarded to the newline with `-32600` and the server
  continues; request before `initialize` (except `ping`) -> `-32600 not_initialized`; `initialize` echoes a known
  `protocolVersion` or answers the newest; `tools/list`, `ping`; notifications get no output (unknown ones too); methods
  `resources/*`, `prompts/*`, `sampling/*`, `logging/*`, `completion/*` -> `-32601`; invalid `id` types never echoed;
  stdout receives only protocol lines (a stray `echo` in a tool is caught by an output-buffer assertion); EOF ends the
  loop with 0. Then `src/Mcp/{JsonRpc,Server}.php`. (FR-001..FR-005, US4 AC2-AC4)
- [ ] T004 [P] [US4] `tests/Unit/Mcp/ToolRegistryTest.php` and `ToolSchemaDriftTest.php` (red): the registry lists exactly the
  tools of `contracts/tools.schema.json` (5, or 6 with `mcp.dry_run`), each with `readOnlyHint: true` and a static
  description; argument validation (unknown property, wrong type, `limit` 0 and 21, `fp` malformed) -> `-32602` naming the
  property and never echoing the value; the allowlists in code equal the schema's properties per tool (drift test, FR-021);
  every schema string is either typed (pattern/enum) or `x-text` with `maxLength`. Then `src/Mcp/{Tool,ToolRegistry}.php`. (FR-014, FR-021, FR-041)
- [ ] T005 [P] [US3] `tests/Unit/Mcp/OutputGuardTest.php` (red) over `tests/Fixtures/mcp/hostile-strings.php` (at least 40
  strings: instruction override in English/Ukrainian/Russian, fake system and tool-result tags, markdown links and
  images, `curl|sh`, `rm -rf`, `sudo`, backticks, `$(...)`, `paste ... token`, URLs with credentials and query,
  `www.` and bare hosts, e-mail, zero-width, bidi, U+E0000 tag characters, C0/C1 controls, 10 000-character strings,
  JSON-in-JSON, `[withheld]` look-alikes): after the guard no output has a URL/host/e-mail/command span (replaced by
  `[withheld]`), no format or control character, none longer than its cap (cut with `…`); a scanner exception returns
  null; a typed validator turns a mismatch into null. Then `src/Mcp/OutputGuard.php` (uses `Redactor` and the shared
  command filter after T017; until T017 the test uses the filter in place and is re-run). (FR-022, FR-023, SC-002)
- [ ] T006 [P] [US3] `tests/Unit/Mcp/EnvelopeTest.php` (red): the exact `notice` sentence (FR-026); `untrusted_data: true`;
  `now` in `...Z` form; built by `json_encode` (a hostile string with quotes, braces and `</tool_result>` round-trips as
  a string and the decoded structure equals the typed array); 16 KB cap drops findings from the end and sets
  `truncated`, never cuts inside a string; errors use only the closed codes and fixed messages. Then `src/Mcp/Envelope.php`. (FR-020, FR-025, FR-026)
- [ ] T007 [P] [US4] `tests/Unit/Mcp/ReadOnlyStateStoreTest.php` (red): `get` passes through; `put`, `increment`, `acquire`,
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
  `mcp.dry_run` default false; strict boolean naming `mcp.dry_run`; `WATCHER_MCP_DRY_RUN` via `Env`; any other `mcp` key
  rejected naming `mcp.<key>`; no secret in the section. Then the config and the factory path that builds the read-only
  store, `Spend`, `VerdictCache`, `Doctor` for the server. Rebase on the current files first. (FR-040)
- [ ] T020 [US2] **Touches `src/State/Keys.php`.** Test in the key-expiry table (red): `Keys::LASTREPORT` has the
  `state.retention` expiry and key `lastrep:{key}`; the shared state-store contract suite round-trips a record on every
  driver. Then the constant and builder. Re-read `Keys.php` first. (FR-050)
- [ ] T021 [US2] **Touches `src/Watcher.php`.** `tests/EndToEnd/LastReportTest.php` (red, before any edit): a real run that
  reports 3 findings stores one record with the window, outcome, counts, figures and 3 finding refs in digest order
  (`title` scrubbed, <= 80); a quiet run stores counts and no findings; a failed run stores outcome `failed`; a dry run
  writes only to the buffer and the real store is untouched; the record contains no template, line, value, frame, path
  (privacy corpus: seeded values absent from the stored JSON); an unwritable store does not change the outcome (existing
  rule: state faults are logged, run result unchanged). Then `Watcher` builds the record from the values the digest uses
  (or from 013's `RunObservation` when merged), writes it with one `put` on a real run and, in a dry run, returns it on the
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
  (the dry run lives on `BufferedStateStore`); real model calls are counted by a fake analyzer: zero in every refusal path
  of T008 and in `estimate_only`; one confirmed run returns the report shape with `dry_run: true` and `estimate_usd`, no
  rendered digest text, nothing posted (fake notifier), the real store byte-identical; the second call is refused when
  the first consumed the remaining budget; the tool is absent from `tools/list` and `-32602` on call when
  `mcp.dry_run` is false. Also: stdout holds only protocol lines across a dry run and an estimate (the `Watcher` output callable is a discarding closure; assert with a captured `STDOUT` stand-in), and the structured result comes from the run result, never from printed text. Then `Tools/DryRunTool.php` calling `Watcher` with `RunOptions::dryRun()`. If the pipeline
  writes through the real store, stop and report: it is a spec 002 defect (plan risk 1). (FR-035, FR-036, US5, SC-004)
- [ ] T023 [US6] Wire `HygieneTool` into the registry and add the contract entry once spec 006 defines its report
  (`tools.schema.json`, `tools.md`). Until then `available: false` only (T009). (FR-034)

## Phase 4: Documentation and gate

- [ ] T030 [P] Docs: `docs/privacy.md` gets a section "MCP server (spec 015)": what is returned, what never is (template,
  lines, values, frames), the envelope, caps, `[withheld]`, read-only guarantee, last-report record (key, expiry, content)
  in "What is stored", the injection limit (R2a, R2c) under "Limits you must know". `docs/configuration.md`: `mcp.dry_run`
  row and env. `README.md`: spec table row 015, command list. `docs/roadmap.md`: row 015. `CHANGELOG.md`: entry under
  Unreleased. (Principle I: a change touching what leaves the service extends the privacy docs)
- [ ] T031 [P] `docs/mcp.md` from `quickstart.md`: setup, one entry per service, store hardening, assistant profile advice
  (no shell/file tools or per-call approval), what the tools return, how to read `untrusted_data`, cost of the dry run.
- [ ] T032 End-to-end smoke (`tests/EndToEnd/McpProcessTest.php`): start `bin/watcher mcp` with `proc_open` and a fake
  config on a temp file store, send initialize/list/status, assert one JSON line per response, exit 0 on EOF, nothing on
  stderr. Then run the gate command and read the exit codes; run `composer deps-check` if present. (SC-006, SC-008)

## Dependencies

```text
T001 -> everything              T002 -> T003
T003, T004, T005, T006, T007, T008, T009 : parallel
T017 -> T005 final run          T018 -> T019, T016
T020 -> T021 -> T014            T022 -> T011
T007 -> T015 -> T019            T008 + T016 -> dry run
T010..T016, T023 -> T012 -> T032
T030, T031 after the behaviour is fixed (after T016)
```
