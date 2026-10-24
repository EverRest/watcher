---

description: "Task list for API Context for Findings (everrest/watcher)"
---

# Tasks: API Context for Findings

**Input**: Design documents from `specs/010-api-context/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (Constitution Principle III). Every test task comes before its implementation task and must fail first
(red). No test touches the network. Gates after each phase: `composer test`, `composer stan`, `composer style`, each exit 0
(read `$?`).

**Organization**: grouped by user story, ordered by dependency. US2 (matching, P1) is pure logic and goes first; US3 (read +
cache, P2) next; US1 (the visible feature, P1) wires them. Foundational first.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallel with other [P] tasks in the same phase (different files, no unfinished dependency)
- **[W]** edits `src/Watcher.php`; **[F]** `src/Config/Factory.php`; **[C]** `src/Config/WatchConfig.php`; **[D]**
  `src/Notify/DigestData.php` / `DigestRenderer.php`; **[K]** `src/Operate/Doctor.php`; **[B]** `src/Analyze/CompactPayload.php`
  / `Budget.php` / `PayloadExtras.php` / `src/Analyze/prompts/*`; **[R]** `src/Operate/RunStats.php` / `JsonSummary.php`. Two tasks with the same letter never run in parallel,
  **across this spec and spec 009**.
- Paths are relative to the repository root

---

## Phase 1: Foundational

- [ ] T001 [P] Test then add `Keys::apiContext($service, $sourceId)` (`api`) and kind `api` in `Keys::ttl()` (returns `$retention`); every kind still has a non-zero TTL; components URL-encoded so a `:` in a service or source id cannot collide in `tests/Unit/State/KeysTest.php`, `src/State/Keys.php`
- [ ] T002 [P] Extend the privacy corpus first (red until the reducer lands): `tests/Fixtures/api_context/pii_seeded.json` (OpenAPI 3.0 with seeded e-mail/id/token values in `examples`, `example`, `description`, `parameters[].schema.default`, `x-` extensions, `servers[].variables`, request/response bodies, and an e-mail inside a `summary`), add the seeds to `tests/Fixtures/privacy/seeded-values.php`; extend `tests/EndToEnd/PrivacyTest.php` and `JsonSummaryPrivacyTest.php` to assert 0 seeded values in the cache entry, model input, notifier payload and `--json` in `tests/Fixtures/api_context/pii_seeded.json`, `tests/Fixtures/privacy/seeded-values.php`, `tests/EndToEnd/PrivacyTest.php`, `tests/EndToEnd/JsonSummaryPrivacyTest.php`
- [ ] T003 [P] Create-or-extend the shared carriers (if spec 009 already landed `DigestExtras`/`PayloadExtras`, add only the fields below): `Notify\DigestExtras` gains `endpointLines` map, `endpointsSkipped` int, `endpointsDropped` bool, `droppedOps` int and `unavailable['api_context']`; `Analyze\PayloadExtras` gains `endpoints` map; both immutable, null/empty = no effect in `tests/Unit/Notify/DigestExtrasTest.php`, `tests/Unit/Analyze/PayloadExtrasTest.php`, `src/Notify/DigestExtras.php`, `src/Analyze/PayloadExtras.php`
- [ ] T004 [D] Test then add the endpoint rendering to `DigestRenderer`: one line under the finding from `endpointLines[$fp]`, footer notes `N endpoint lines skipped (limit L)`, `endpoint lines dropped (token budget)`, `N operations not kept (cache limit)` (from `droppedOps`), `API context unavailable (<token>)`; null/empty extras print nothing (existing `DigestTest`, `MultiChannelTest`, `TemplateReadabilityTest` byte-identical); if `DigestData` has no trailing `?DigestExtras` yet (009 not landed), add it as the last optional argument in `tests/Unit/Notify/DigestRendererTest.php`, `src/Notify/DigestData.php`, `src/Notify/DigestRenderer.php` [shared with spec 009 T004]
- [ ] T005 [P] [R] Test then add `RunStats` counters (`apiEndpointsAttached`, `apiEndpointsSkipped`, `apiCache` in `hit|miss|failed|none`, `apiTokens`) and render the `api_context` object in `JsonSummary` only when configured (default output byte-identical) in `tests/Unit/Operate/JsonSummaryTest.php`, `src/Operate/RunStats.php`, `src/Operate/JsonSummary.php`

**Checkpoint**: unit suite green except the red corpus cases.

---

## Phase 2: User Story 2 - Routes match despite masking (P1)

**Goal**: pure, table-tested extraction and matching.

**Independent Test**: quickstart 6 (the >= 30 case table, contracts/matching.md).

### Tests first

- [ ] T006 [P] [US2] Test `RouteExtractor` (red): method optional and upper-case only; a method-less route needs >= 1 non-empty segment (`retry 3 / 5 failed`, a lone `/` -> no route; `GET /` is a route); a template `null` (withheld) -> null (moved here from the matcher table); bare path needs start/whitespace/quote/`(` before the slash (`a/b`, `10/12`, `<HOST:x>/p` give no route); query and fragment cut; trailing `.,;:)"'` stripped; trailing slash and `//` collapse; segment kinds (`<ID> <N> <TOKEN> <EMAIL> <NAME> <STR> <USER>` = Param; `<PATH>`, `…`, mixed `order-<N>`, `<ID>.json`, `<URL>`, `<HOST:x>` = route unmatchable); first route only; `null` template -> null; never reads anything but the string in `tests/Unit/ApiContext/RouteExtractorTest.php`
- [ ] T007 [P] [US2] Test `RouteMatcher` as a data-provider over `tests/Fixtures/api_context/match-cases.php` (the 35 matcher rows of contracts/matching.md, rows marked S are synthetic templates; the test asserts the row count >= 30 together with the round-trip rows): Literal=Literal case-sensitive, Param=Param, Literal vs Param never matches either way, Mixed template segments never match, segment-count gate, method filter, exactly one operation matches (more than one, equal shape, attaches nothing; no specificity rule), ties attach nothing, no-method needs exactly one operation, `ignore_prefixes` then base path stripped once each, template that already starts with the base is stripped, trailing slash/query ignored; also property-style test: for every "none" row no operation is returned; **and** `RouteRoundTripTest` runs the 7 round-trip rows of contracts/matching.md through the real `Normalizer` -> `RouteExtractor` -> `RouteMatcher` (pins the 2-segment limit: `POST /orders/8812/refund` -> `POST <PATH>/…/<N>/refund` -> none) in `tests/Unit/ApiContext/RouteMatcherTest.php`, `tests/Unit/ApiContext/RouteRoundTripTest.php`, `tests/Fixtures/api_context/match-cases.php`

### Implementation

- [ ] T008 [US2] Implement `Route` (+ `Segment`) and `RouteExtractor` in `src/ApiContext/Route.php`, `src/ApiContext/RouteExtractor.php`
- [ ] T009a [P] [US2] Test `ReducedSpec` (red): `fromCache(array)` rejects a wrong `v` or a malformed row (returns null), `bySegments()` index in `tests/Unit/ApiContext/ReducedSpecTest.php`
- [ ] T009 [US2] Implement `OperationSummary`, `ReducedSpec` (with `bySegments()` index and `fromCache(array): ?self`) and `RouteMatcher` in `src/ApiContext/OperationSummary.php`, `src/ApiContext/ReducedSpec.php`, `src/ApiContext/RouteMatcher.php`

**Checkpoint**: quickstart 6 green.

---

## Phase 3: User Story 3 - Read the description safely, once a day (P2)

**Goal**: JSON OpenAPI -> reduced, masked, capped, cached spec; failures are tokens, never exceptions.

**Independent Test**: quickstart 7-11, 13.

### Tests first

- [ ] T010 [P] [US3] Test `OpenApiReducer` (red) with fixtures `petstore_v2.json`, `orders_v30.json`, `orders_v31.json`, `pii_seeded.json`: version gate (`swagger 2.0`, `openapi 3.0.x`/`3.1.x`; `4.0`, missing -> `unsupported_version`); only the six kept fields reach `OperationSummary` (a reflection test asserts the class has no other property); `$ref` path items ignored; lower-case verbs only; `tags[0]` only; `deprecated === true` only; operationId kept only when summary empty; summary/operationId/tag cut to `summary_cap` then `Redactor::redactText` (e-mail in a summary masked; a `null` redaction drops the value, a null template drops the operation); base path precedence (`base_path` config > v2 `basePath` > v3 `servers[0].url` path; `{variable}` in the URL -> `''`; `base_path` is config and is not masked); a template whose masked form differs from the original (e.g. a 3+-segment template the Normalizer rewrites to `<PATH>/…/x`) or that does not start with `/` DROPS the operation and is counted nowhere in the cache; document order; 100,000-byte bound with `dropped` count (generated 5,000-operation fixture); a realistic fixture at the default `max_bytes` (1 MB) encodes under 100 KB (SC-004); non-array `paths`, non-string keys -> `bad_format`; seeded values appear nowhere in the encoded entry (SC-001) in `tests/Unit/ApiContext/OpenApiReducerTest.php`
- [ ] T011 [P] [US3] Test `OpenApiSource` (red): file: missing/unreadable -> `unreadable`, over `max_bytes` -> `too_large` (also when the file grows after `filesize`), strings with `://`, `php://`, `phar://`, `data:` refused; URL via Guzzle `MockHandler`: `GET` only, `Accept: application/json`, bearer header set only when configured, no body, `Content-Length` over the cap fails before reading, decoded-stream cap catches a gzip bomb, redirects <= 3 and https-only (an `http` redirect is refused), 401/403 -> `unauthorized`, timeout -> `timeout`, 5xx/connect error -> `unreachable`; first byte not `{` (YAML, `---`, `openapi:`) -> `unsupported_format` in < 1 s; JSON depth > 64 / a 2 MB nested-array bomb -> `bad_format` in < 1 s with a peak-memory delta < 32 MB at the default `max_bytes` of 1 MB (measured earlier: 2 MB flat JSON decodes to 144 MB, hence the 1 MB default, research R1); a stream that trickles bytes is aborted by the 15 s wall-clock deadline in the read loop (`timeout`, fake clock); no token, URL or path in any exception message; extend `ArchitectureTest` so `src/ApiContext/OpenApiSource.php` contains `'GET'` and no `POST|PUT|DELETE|PATCH`, and only `https` in its allowed protocols in `tests/Unit/ApiContext/OpenApiSourceTest.php`, `tests/Unit/ArchitectureTest.php`
- [ ] T012 [P] [US3] Test `ApiSpecCache` and `ApiContextReader` (red) with `InMemoryStateStore`, `FrozenClock`, `RecordingStateStore`: miss -> source once -> `put` with TTL = `api_context.ttl`; second call inside the TTL -> 0 source calls (spy); after the TTL -> one read; key `api:{service}:{sourceId}` differs per service and per source and holds no URL/path; a corrupt/old-`v` entry is a miss; source failure -> a `SourceUnavailable` token result, **nothing cached**, no exception; with the feature absent the reader is never constructed (no source open, no `get`, no `put`); `DryRunStateStore` read-through works and writes nothing; `readAt` and `dropped` round-trip; `readFresh()` (cache-skipping read used by the doctor) never calls `get`/`put` in `tests/Unit/ApiContext/ApiSpecCacheTest.php`, `tests/Unit/ApiContext/ApiContextReaderTest.php`
- [ ] T013 [P] [US3] Test the `api_context` config section (red): defaults (`max_bytes` 1,048,576); absent -> `$c->apiContext === null`; `enabled: false` still validates; every row of contracts/config.md throws `InvalidConfig` naming the key path and no value (missing source, scheme, `.yaml`, ttl format/range/`> state.retention`, `max_endpoints` 0/21, `summary_cap`, `max_bytes`, `ignore_prefixes`, unknown key); `auth_token` never in a message in `tests/Unit/Config/WatchConfigTest.php`

### Implementation

- [ ] T014 [US3] Implement `OpenApiReducer` in `src/ApiContext/OpenApiReducer.php`
- [ ] T015 [US3] Implement `OpenApiSource` (file + https GET, decoded-size cap, redirect/timeouts, format sniff, fixed failure tokens) in `src/ApiContext/OpenApiSource.php`
- [ ] T016 [US3] Implement `ApiSpecCache` and `ApiContextReader` (cache-or-fetch plus `readFresh()` that skips the cache, never throws, returns `ReducedSpec` or a failure token + cache status `hit|miss|failed`) in `src/ApiContext/ApiSpecCache.php`, `src/ApiContext/ApiContextReader.php`
- [ ] T017 [C] [US3] Implement the `api_context` section in `WatchConfig` (public `?array $apiContext`, validated even when `enabled: false`; not in `FLAGS`) in `src/Config/WatchConfig.php`
- [ ] T018 [F] [US3] Add `Factory::apiContextReader(WatchConfig, Client, StateStore, Redactor): ?ApiContextReader` (takes a `Redactor`, not a factory) (null when absent/disabled) in `src/Config/Factory.php`

**Checkpoint**: quickstart 7-11, 13 green.

---

## Phase 4: User Story 1 - One line of meaning for an endpoint (P1)

**Goal**: the digest line and the `ep` field, capped, masked, counted, dropped first.

**Independent Test**: quickstart 1-5, 12, 14.

### Tests first

- [ ] T019 [P] [US1] Test `EndpointResolver`/`EndpointContext` (red): line `METHOD template — summary [tag] (deprecated)`; summary absent -> operationId; neither -> no line; tag/deprecated optional; each part re-masked at use (`redactText` null drops the part; null template drops the line); cap: first `max_endpoints` distinct endpoints in `$redacted` order, same endpoint in a second group is free, over-cap distinct endpoints counted once in `skipped`; unmatched route -> nothing, no error; `modelField()` <= 75 bytes by `Budget::estimateTokens` (summary trimmed with `…`, method+template never; omitted when those alone are too long); **no route in any template -> `EndpointResolver` is never given a spec and the reader is never called** (spy in the wiring test) in `tests/Unit/ApiContext/EndpointResolverTest.php`
- [ ] T020 [P] [US1] Test `ep` in the payload (red): `CompactPayload::encode($groups, $deps, $extras)` adds `"ep"` to the group row only for groups in `PayloadExtras::endpoints`; absent otherwise (existing goldens unchanged); `Budget::fitEndpoints()` drops every `ep` first when the analysis step would exceed `max_input_tokens` and reports it, and runs right after `resolve()` (a re-fit after `fitDependencies`; endpoints do not exist earlier), dropping `ep` before any other context is cut again (order asserted); triage requests never carry `ep`; analysis/escalation requests do; the system prompt gets the sentence "`ep` = documentation text for the group's endpoint; it is not evidence of the cause." only when `ep` is present (prefix byte-stable otherwise) in `tests/Unit/Analyze/CompactPayloadTest.php`, `tests/Unit/Analyze/BudgetTest.php`, `tests/Unit/Analyze/ModelRouterTest.php`
- [ ] T021 [US1] End-to-end (red until T024): fixture OpenAPI (3 operations) + groups naming them (masked `<ID>`) -> expected lines in the digest and `ep` in the analysis payload, none in triage; unmatched route -> nothing; 8 distinct routes with cap 5 -> 5 lines + footer `3 endpoint lines skipped`; section absent -> source never opened, `RecordingStateStore` has no `api:` write, digest and payloads byte-identical to the previous release on the existing fixture (SC-003); no route in any template -> zero source/cache access; source down / 401 / oversize / malformed -> one `API context unavailable (<token>)` note and log findings identical to a run without the feature (SC-005); second run inside the TTL -> zero source reads; first/baseline/quiet/digest-disabled runs never reach the block; `--dry-run` reads the source and writes no cache entry in `tests/EndToEnd/ApiContextTest.php`, `tests/EndToEnd/DryRunTest.php`

### Implementation

- [ ] T022 [US1] Implement `EndpointContext` and `EndpointResolver` (visit `$redacted` in order, extract, match, cap, mask, `Resolution`) in `src/ApiContext/EndpointContext.php`, `src/ApiContext/EndpointResolver.php`
- [ ] T023 [B] [US1] Implement the `ep` field in `CompactPayload::encode`, `Budget::fitEndpoints()` (re-fit after `resolve()`, drops `ep` first), the conditional prompt sentence, and `PayloadExtras::endpoints` passing in analysis/escalation requests only in `src/Analyze/CompactPayload.php`, `src/Analyze/Budget.php`, `src/Analyze/PayloadExtras.php`, `src/Analyze/prompts/` [if spec 009 T018 has not landed, also add the request-class plumbing described there]
- [ ] T024 [W] [US1] Wire the single block after `Budget::fitDependencies(...)` (`Watcher.php:632`): constructor takes `?ApiContextReader`; extract routes from `$redacted` templates, stop when none; read the spec; on failure set `DigestExtras::unavailable['api_context']`; `EndpointResolver::resolve`; `Budget::fitEndpoints` right after `resolve()` (a re-fit; drops `ep` first); fill `PayloadExtras::endpoints`, `DigestExtras::endpointLines/endpointsSkipped/endpointsDropped/droppedOps`, `RunStats`; `Watcher::fromConfig` passes the `Factory` reader in `src/Watcher.php`
- [ ] T025 [US1] Verify T021 and T002 go green; run the gates

**Checkpoint**: quickstart 1-5, 12, 14 green; the feature is complete.

---

## Phase 5: Cross-cutting

- [ ] T026 [P] Test `Doctor` check `api_context` (red): absent section -> no check; readable document -> pass with `N operations (M kept), K KB`; each failure token fails with that reason only; the check bypasses the cache (no `get`, no `put` on a `RecordingStateStore`); no `Notifier::send`, no `Analyzer` call; reason holds no URL/path/token in `tests/Unit/Operate/DoctorTest.php`
- [ ] T027 [K] Implement the check in `Doctor` (uses `ApiContextReader::readFresh()` that skips the cache) in `src/Operate/Doctor.php`, `src/ApiContext/ApiContextReader.php`
- [ ] T028 [P] Token-budget fixture (SC-003): 5 attached endpoints add <= 125 input tokens and 0 output tokens; hard maximum 20 -> <= 500; disabled = previous release counts (0 difference) on the existing end-to-end fixture in `tests/Unit/Analyze/BudgetTest.php`, `tests/EndToEnd/CostTest.php`
- [ ] T029 [P] [R] `--json` end-to-end (red then green): `api_context` counts (attached, skipped, cache, tokens) present only when configured in `tests/EndToEnd/RunSummaryTest.php`
- [ ] T030 [P] Docs: `docs/configuration.md` (table from contracts/config.md, JSON-only note, https-only note, how to export a JSON file in CI for YAML-only frameworks), `docs/privacy.md` (new allowlisted field class: endpoint summary/tag/operationId, capped to 80 and masked; owner is responsible for summaries), `docs/operations.md` (`API context unavailable (<token>)`, `check`, cache TTL), `config/watcher.example.php` (commented block), `CHANGELOG.md` (MINOR: new optional section), `docs/roadmap.md` row 010 -> "Planned" in `docs/*`, `config/watcher.example.php`, `CHANGELOG.md`
- [ ] T031 Run `composer test`, `composer stan`, `composer style` (each `echo $?`), the full quickstart table, the architecture and feature-flag tests; record the three counts in the PR description
- [ ] T032 Operator verification on a real framework document (owner-run, not automatable): export `openapi.json` from one real service (e.g. NestJS `/api-json`, Laravel `/docs.json`), run `watcher check`, confirm the operation and kept counts and that a real masked route in a digest finds its line; record the document size, operation count and measured memory, and revisit the 1 MB `max_bytes` default if real documents need more in `specs/010-api-context/research.md`

---

## Dependencies & Execution Order

- Phase 1 first (T001-T003, T005 parallel; T004 [D] after T003).
- Phase 2 (US2) -> Phase 4 needs T008/T009; Phase 3 (US3) -> Phase 4 needs T014-T018. Phases 2 and 3 are independent of each other and may run in parallel (different files) **except** the hot-file tasks.
- **Sequential hot-file order**: `[C]` T017; `[F]` T018; `[W]` T024; `[D]` T004; `[B]` T023; `[K]` T027; `[R]` T005 then T029.
- **Across specs**: spec 009 edits the same hot files. Recommended order: 009 lands first (creates `DigestExtras`, `PayloadExtras`, the request-class plumbing and `Budget::fitMetrics`); 010 then extends them. If 010 lands first, T003/T004/T023 create them and 009's tasks T003/T004/T018 only extend. `Budget::fitEndpoints` runs right after `resolve()` in the block after `fitDependencies` (endpoints do not exist earlier); it is a re-fit and drops `ep` first, so 009's `fitMetrics` stays where 009 puts it; agree the call order in whichever lands second. `RunStats`/`JsonSummary` are hot files too ([R]): spec 009 edits them, so T005/T029 add only their own fields. Conflicts in `Watcher.php`, `Factory.php`, `WatchConfig.php`, `DigestRenderer.php`, `RunStats.php`, `JsonSummary.php` are resolved **by hunk**, never by taking a whole file (`--ours/--theirs`).
- Tests marked [P] sharing a file (`WatchConfigTest.php`, `ArchitectureTest.php`, `PrivacyTest.php`, `DoctorTest.php`, `BudgetTest.php`) add separate test methods and run the file once after each.
- Hand-off: no commit, push or tag unless the owner asks; no `--no-verify`; no AI co-author trailer.
