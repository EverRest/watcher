# Tasks: Multi-Provider Model Layer

**Input**: `specs/016-model-providers/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Prerequisites**: plan.md (required), spec.md (required), research.md, data-model.md, contracts/

**TDD order**: Phase 2 writes the failing tests (contract suite with recorded replies for Anthropic, OpenAI and Gemini; privacy per outgoing body; config rules). Story phases turn them green one adapter at a time. Within each story, a test task comes before the implementation task it covers.

**Gates** (run before any stage is committed; read exit codes): `composer test`, `composer stan`, `composer style`.

**Organization**: Tasks are grouped by user story so each story is independently testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 (choose provider per step), US2 (OpenAI and Gemini), US3 (one transport path per provider)

---

## Phase 1: Setup

**Purpose**: dependency and tooling changes that every later task needs.

- [X] T001 Pin the Anthropic SDK at the exact version 1.3.0 by running `composer require anthropic-ai/sdk:1.3.0` in `composer.json` (plan.md says `~1.3.0`; pin exact now, widen only after the smoke test in T040 passes). Commit `composer.lock` with it.
- [X] T002 Add `"php-http/discovery": true` under `config.allow-plugins` in `composer.json`, then run `composer install --no-interaction` and confirm no plugin prompt appears.
- [X] T003 [P] Record the gate baseline before any change: `composer test; echo "test=$?"`, `composer stan; echo "stan=$?"`, `composer style; echo "style=$?"`. All three must be 0. If not, stop and report.

**Checkpoint**: composer resolves with the pinned SDK, no plugin prompt, gates green on the untouched code.

---

## Phase 2: Foundational (blocking; red tests by design)

**Purpose**: rename the contract, verify the two unverified providers against live documentation, record fixtures, and write every failing test that later phases must turn green. **Phase 2 ends with the new suite red on purpose.** Do not commit a red stage (the gate rule); Phase 2 changes ride with US1.

- [X] T004 Rename the contract: `src/Analyze/MessagesClient.php` becomes `src/Analyze/ModelProvider.php` (interface `ModelProvider`, method signature `create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply` unchanged). Update every reference: `src/Analyze/AnthropicAnalyzer.php`, `src/Config/Factory.php`, `tests/Unit/Analyze/AnthropicAnalyzerTest.php`, `tests/Unit/Analyze/AnthropicAnalyzerTriageTest.php`, `tests/Unit/Analyze/AnthropicHttpClientTest.php`, `tests/Unit/Analyze/ServiceContextTest.php`. Behaviour unchanged; gates green after this task alone.
- [ ] T005 [P] Verify Gemini against the live reference at https://ai.google.dev/api (generateContent, `GenerationConfig`, `ThinkingConfig`, `UsageMetadata`, `FinishReason`). Confirm exact REST field names: `systemInstruction`, `contents`, `generationConfig.responseMimeType`, `generationConfig.responseJsonSchema`, `generationConfig.maxOutputTokens`, `thinkingConfig.thinkingBudget` (value that turns thinking off per model), `usageMetadata.cachedContentTokenCount`, `candidates[0].finishReason`. Whether `candidatesTokenCount` includes thinking tokens. Write results into `specs/016-model-providers/research.md` R3 (replace the three "unverified" bullets with verified facts and their URLs). Do not write adapter code in this task.
- [ ] T006 [P] Verify OpenAI against the live reference at https://developers.openai.com/api/docs (Chat Completions `response_format` with `json_schema` and `strict`, `max_completion_tokens`, `usage.prompt_tokens_details.cached_tokens`, `reasoning_effort` per model, `finish_reason` values). Confirm whether a strict json_schema call can produce a cache hit on the models we would configure. Write results into `specs/016-model-providers/research.md` R3. Caching is not claimed until a hit is shown (plan R3).
- [ ] T007 [P] Create recorded Anthropic replies in `tests/Fixtures/providers/anthropic/`: `ok.json` (valid structured reply with usage and cache fields), `refusal.json`, `max_tokens.json`, `missing_usage.json`, `unauthorised.json` (401), `rate_limited.json` (429), `server_error.json` (500), `non_json.txt`. Copy shapes from the existing `tests/Fixtures/anthropic/` files where they exist; do not invent new fields.
- [ ] T008 [P] Create recorded OpenAI replies in `tests/Fixtures/providers/openai/` with the same case names as T007. Shapes come from the T006 verification only. `ok.json` must include `usage.prompt_tokens_details.cached_tokens`. Depends on T006.
- [ ] T009 [P] Create recorded Gemini replies in `tests/Fixtures/providers/gemini/` with the same case names as T007. Shapes come from the T005 verification only. `ok.json` must include `usageMetadata.cachedContentTokenCount` only if T005 confirmed it. Depends on T005.
- [ ] T010 Write the shared contract suite `tests/Contract/ModelProviderContractTest.php` (red). A PHPUnit data provider runs the same cases against `AnthropicSdkProvider`, `OpenAiProvider`, `GeminiProvider`, each built on a Guzzle `MockHandler` with its own fixtures. Cases: valid structured reply → `ModelReply` with text, usage and normalised stop reason; 401 → `authentication failed`; 429 and 500 → `provider unavailable (HTTP 429)` / `(HTTP 500)`; non-JSON → `provider unavailable (invalid reply)`; refusal → `stopReason = refusal`; max tokens → `stopReason = max_tokens`; missing usage count → `provider unavailable (invalid reply)`. On every failure case assert that the exception message contains no URL, no key string and no body text. Depends on T004, T007, T008, T009. Constructor used by the suite: `new X(ClientInterface $http, string $apiKey, string $baseUrl, int $timeoutSeconds, int $maxRetries)`.
- [ ] T011 [P] Write the privacy test per outgoing body (red) in the existing privacy test (locate with `grep -rn "fixtures/privacy\|Fixtures/privacy" tests/`). Seed the corpus in `tests/Fixtures/privacy` (emails, names, phone numbers, tokens). For each of the three providers, capture the outgoing request body from the MockHandler and assert no seeded value appears in it. Assert that a non-allowlisted field never reaches the adapter (fail closed). Depends on T010 for class names.
- [ ] T012 [P] Write the config rules tests (red) in new file `tests/Unit/Config/WatchConfigProvidersTest.php`:
  - `analysis.providers` accepts only keys `triage`, `analyze`, `escalate` and values `anthropic`, `openai`, `gemini`; unknown key or value fails at load and the message names the setting path.
  - A step's provider falls back to `analysis.provider` when not set in `analysis.providers`.
  - A used provider without a key fails naming `analysis.api_keys.<name>`, never the value.
  - `analysis.api_key` still works and equals `analysis.api_keys.anthropic`.
  - `analysis.providers_options.<name>.base_url` must be `https`; `retries` is an integer 0 to 3, default 0.
  - `analysis.client` absent: OK. `http`: accepted, config notice emitted. Any other value (including `sdk`): refused with the R7 message "analysis.client is no longer a transport choice; the Anthropic transport is the official SDK. Delete the key."
  - A step's model id missing from `analysis.prices` fails at load.
- [ ] T013 [P] Create `tests/Fixtures/config/provider-mix.php`: `analysis.provider = anthropic`, `providers.triage = gemini`, other steps on anthropic, `api_keys` read from env with placeholder model ids and prices. No real key, no real model id.

**Checkpoint**: the suite is red for the expected reasons (missing classes, missing config keys). Each red test names the behaviour it expects.

---

## Phase 3: User Story 1 - Choose a provider per step from configuration (Priority: P1)

**Goal**: each step (triage, analyze, escalate) picks its provider from config; Anthropic goes through the official SDK with an explicit base URL, retries and timeout.

**Independent test**: the Anthropic fixtures give the same outcome, findings and digest as before this feature (SC-002); a config that sends triage to `gemini` loads; `analysis.client = sdk` is refused.

### Tests for US1 (write first)

- [ ] T014 [US1] Test that the SDK request body for the Anthropic `ok` fixture equals the body today's `AnthropicHttpClient` sends (same model, `max_tokens`, `system` blocks with `cache_control`, `thinking: disabled` for triage, `output_config.format`). Put it in `tests/Unit/Analyze/AnthropicSdkProviderTest.php`. Red until T017.
- [ ] T015 [US1] Test in `tests/Unit/Analyze/AnthropicSdkProviderTest.php`: the SDK is built with the configured base URL; with `ANTHROPIC_BASE_URL` set to a sentinel in the environment, the request still goes to the configured URL (spec US3 acceptance 1). Red until T017.
- [ ] T016 [US1] Test in `tests/Unit/Analyze/AnthropicSdkProviderTest.php`: the configured `maxRetries` and timeout reach the SDK client; no SDK default is used (spec US3 acceptance 2). Red until T017.

### Implementation for US1

- [ ] T017 [US1] Create `src/Analyze/AnthropicSdkProvider.php` implementing `ModelProvider` over `anthropic-ai/sdk` with a PSR-18 Guzzle client. Base URL, `maxRetries` and timeout come from the constructor (config). Map SDK exceptions and HTTP status to the `AnalyzerFailed` messages in contracts/model-provider.md rule 8. Map `end_turn`/`max_tokens`/`refusal`/`pause_turn` to `end`/`max_tokens`/`refusal`/`pause`. Keep the key in a closure, as `AnthropicHttpClient` does. Makes T014 to T016 and the Anthropic rows of T010 green.
- [ ] T018 [US1] Run the Anthropic rows of the privacy test (T011) against `AnthropicSdkProvider`; fix any leak it reports. Depends on T017.
- [ ] T019 [US1] Implement the config keys in `src/Config/WatchConfig.php` (and `src/Config/KeyCatalog.php` for env names): `analysis.providers`, `analysis.api_keys`, `analysis.providers_options`, `WATCHER_ANTHROPIC_KEY`, `WATCHER_OPENAI_KEY`, `WATCHER_GEMINI_KEY`, `WATCHER_PROVIDER_TRIAGE`, `WATCHER_PROVIDER_ANALYZE`, `WATCHER_PROVIDER_ESCALATE`. Keep `analysis.api_key` working. Make T012 green. Only the three provider names are accepted for now; OpenAI and Gemini names are accepted by config but have no adapter until US2 (add them to the name list in T027, not here).
- [ ] T020 [US1] Replace the `analysis.client` check in `src/Config/WatchConfig.php` (currently refuses `sdk` with its own message, around line 722): absent OK, `http` accepted with a config notice and ignored, any other value refused with the R7 message. Make the client rows of T012 green.
- [ ] T021 [US1] Add a per-step provider to the request objects: `src/Analyze/TriageRequest.php` and `src/Analyze/AnalysisRequest.php` gain a provider name set by `src/Analyze/ModelRouter.php` from the step name (`triage`, `analyze`, `escalate`). Test first in `tests/Unit/Analyze/ModelRouterTest.php`: escalation calls carry `escalate` even though they use `analyze()`. Depends on T019.
- [ ] T022 [US1] Create `src/Analyze/ProviderRegistry.php`: maps provider name → `ModelProvider` and step → provider name, built by `src/Config/Factory.php`. Registers Anthropic only in this phase. The router resolves the provider per request through it. Depends on T017, T021.
- [ ] T023 [US1] Update `tests/Unit/Analyze/AnthropicAnalyzerTest.php` and `tests/Unit/Analyze/AnthropicAnalyzerTriageTest.php` to the new names only (no behaviour change). Run them: outcome, findings and digest for the existing fixtures must be unchanged (SC-002).

**Checkpoint US1**: `vendor/bin/phpunit --filter 'ModelProviderContract|AnthropicSdkProvider|WatchConfigProviders|ModelRouter'` green for Anthropic; mixed config loads; `analysis.client = sdk` refused.

---

## Phase 4: User Story 2 - Use OpenAI or Gemini with the same guarantees (Priority: P2)

**Goal**: OpenAI and Gemini adapters over the package's Guzzle client, meeting the same contract, privacy, budget and cost rules.

**Independent test**: the shared contract suite and the privacy test pass for all three providers; the token budget and cost line are computed the same way for each.

### Tests for US2 (write first; the rows in T010 and T011 for OpenAI and Gemini already exist and are red)

- [ ] T024 [US2] Write `tests/Unit/Analyze/OpenAiProviderTest.php` (red): request body has `response_format` `json_schema` with `strict` true, the system prompt as the first message, `max_completion_tokens` from `$maxTokens`; with `$thinking = false`, the reasoning setting from T006 is sent; `usage.prompt_tokens_details.cached_tokens` maps to `cacheReadTokens`, `cacheWriteTokens` is 0; `finish_reason` `stop`/`length`/`content_filter` maps to `end`/`max_tokens`/`refusal`.
- [ ] T025 [US2] Write `tests/Unit/Analyze/GeminiProviderTest.php` (red): body has `systemInstruction`, `contents`, `generationConfig.responseMimeType` `application/json`, `generationConfig.responseJsonSchema` equal to the stripped schema, `maxOutputTokens`; with `$thinking = false`, the thinking-off setting from T005 is sent; `usageMetadata` counts map to the four `ModelReply` fields with cache write 0; `finishReason` `STOP`/`MAX_TOKENS`/`SAFETY` maps to `end`/`max_tokens`/`refusal`.

### Implementation for US2

- [ ] T026 [US2] Create `src/Analyze/OpenAiProvider.php` over Guzzle (one POST, bounded reply reader, error mapping shared with the other adapters; no URL, key or body in messages). Constructor takes the same arguments as `AnthropicSdkProvider`. Makes T024 and the OpenAI rows of T010 and T011 green. Cache: report `cached_tokens` as read tokens; do not send any cache field unless T006 showed a hit.
- [ ] T027 [US2] Create `src/Analyze/GeminiProvider.php` over Guzzle, same rules as T026. Thinking off per T005. Put in the docblock whether `candidatesTokenCount` includes thinking tokens, and add a risk entry to plan.md if thinking cannot be turned off for a configured model. Makes T025 and the Gemini rows green. Add `openai` and `gemini` to the provider name list in `src/Config/WatchConfig.php` and register both in `src/Analyze/ProviderRegistry.php` in this task.
- [ ] T028 [US2] Cost and price per provider: write a test in `tests/Unit/Analyze/CostCalculatorTest.php` that the digest cost for an OpenAI reply and a Gemini reply equals reported usage times the configured prices (SC-004), and that a model without a price triggers `UnknownModelPrice` with the existing digest notice (edge case). Implement only what fails. Depends on T026, T027.
- [ ] T029 [US2] Token-budget fixtures for each provider: extend the budget test (`tests/Unit/Analyze/BudgetTest.php` or the nearest existing budget fixture test) so one recorded run runs through each of the three providers with the same window and asserts the same budget decision and the truncation notice when over the cap (quickstart section 2 and plan R8). Depends on T026, T027.
- [ ] T030 [US2] Failure notice for every provider: test that an unreachable provider, a 401 and an invalid reply on OpenAI and Gemini each produce the existing visible failure notice and no "nothing found" digest (FR-015, SC-006). Add to the end-to-end test in `tests/EndToEnd/`. Depends on T026, T027.

**Checkpoint US2**: all three rows of the contract suite and privacy test green; budget and cost fixtures pass for each provider.

---

## Phase 5: User Story 3 - Maintain one transport path per provider (Priority: P3)

**Goal**: Anthropic has one transport (the SDK), the old HTTP client is removed, and a fourth provider needs one adapter and config only.

**Independent test**: a fake fourth provider passes the shared contract with no edit to a core file.

- [ ] T031 [US3] Test first in `tests/Contract/ModelProviderContractTest.php`: add a fake `ModelProvider` named `fake-four` supplied only by test config and the registry's constructor. The shared contract cases run against it (SC-005). Confirm it passes without editing any file under `src/` other than `ProviderRegistry.php` accepting a name→class map. Depends on T022.
- [ ] T032 [US3] Move the behaviour pinned by `tests/Unit/Analyze/AnthropicHttpClientSecretTest.php` (no secret in messages, key held in a closure) into the shared contract as rule 6, then delete `src/Analyze/AnthropicHttpClient.php`, `tests/Unit/Analyze/AnthropicHttpClientTest.php` and `tests/Unit/Analyze/AnthropicHttpClientSecretTest.php`. Replace the remaining `AnthropicHttpClient` reference in `src/Config/Factory.php` with `AnthropicSdkProvider` through the registry. Depends on T017, T022.
- [ ] T033 [US3] Confirm the net code change: count lines under `src/Analyze/` and `src/Config/` before (`git show 520884b:src/Analyze | wc -l`-style check on the touched files) and after; expected net reduction about 30 lines (plan.md scale estimate). Record the count in the PR description; do not fail the task on the estimate.

**Checkpoint US3**: `AnthropicHttpClient` is gone; the fake fourth provider passes the contract; `grep -rn "AnthropicHttpClient\|MessagesClient" src tests` returns nothing.

---

## Phase 6: Polish & cross-cutting concerns

- [ ] T034 [P] Update `docs/configuration.md`: new keys (`analysis.providers`, `analysis.api_keys`, `analysis.providers_options`), env variables, the `analysis.client` rule (absent or `http` only), and the per-step example from contracts/analysis-config.md with placeholder model ids.
- [ ] T035 [P] Update `docs/smoke-test.md`: choose a provider per step; run once per enabled provider; compare the cost line with that provider's console (within about 10%).
- [ ] T036 [P] Update `CHANGELOG.md` under Unreleased: reinstated `anthropic-ai/sdk` (reverses the 0.3.0 removal; reasons, pin, beta status from research.md R1); `analysis.client = sdk` now refused with the replacement message; new per-step providers; `analysis.api_keys`.
- [ ] T037 [P] Remove any leftover `MessagesClient` or `AnthropicHttpClient` mention from code comments and docs (`grep -rn "MessagesClient\|AnthropicHttpClient" src tests docs`).
- [ ] T038 Run the focused checks from quickstart.md: `vendor/bin/phpunit --filter ModelProviderContract`, `vendor/bin/phpunit --filter Privacy`, `php bin/watcher check --config=tests/Fixtures/config/provider-mix.php --json`. Record each exit code.
- [ ] T039 Run the gates and record exit codes (not the tail of output): `composer test; echo "test=$?"`, `composer stan; echo "stan=$?"`, `composer style; echo "style=$?"`. All must be 0 before the stage is committed.
- [ ] T041 [P] Remove the `WATCHER_PRICE_*` variables from the price path: `src/Mcp/SetupCatalog.php` (ask for prices as configuration values, not env names), `docs/configuration.md` (the env table row), `docs/smoke-test.md` (the price variables and the env file block), `docs/examples/smoke.config.php` and `docs/examples/orders-api.loki.config.php` (literal prices in the file, with a comment naming the provider's pricing page). Spec amendment: FR-003 in spec.md, decision of 2026-10-27.
- [ ] T040 Live smoke, costs money, ask-first (spec, quickstart section 5): only after T038 and T039 are green and only with the owner's go-ahead. Run `docs/smoke-test.md` once per enabled provider. Then, and only then, widen the SDK pin in `composer.json` from `1.3.0` to `~1.3.0` if the owner agrees.

---

## Dependencies & execution order

- **Setup (Phase 1)** → **Foundational (Phase 2)** → **US1 (P1)** → **US2 (P2)** → **US3 (P3)** → **Polish**.
- Within Phase 2: T005 and T006 (documentation checks) come before T008 and T009 (fixtures); T004 before T010 and T011.
- US2 needs the `ProviderRegistry` and request routing from US1 (T021, T022).
- US3 needs the Anthropic SDK adapter (T017) and the registry (T022).
- T040 (live smoke) is last and ask-first.

### Parallel opportunities

- Phase 1: T003 alongside T001 and T002.
- Phase 2: T005, T006, T007 in parallel; then T008 and T009 in parallel; T011, T012, T013 in parallel.
- US1: T014 to T016 can be written in parallel (same file, so sequentially in practice).
- US2: T026 and T027 in parallel (different files), after T024 and T025.
- Polish: T034 to T037 in parallel.

## Implementation strategy

- **MVP**: Phases 1 to 3 (US1). Anthropic through the SDK, per-step config, `analysis.client` rule. Mixed Anthropic-only config already works; OpenAI and Gemini names are refused until US2 lands.
- **Then**: US2 for OpenAI and Gemini, each adapter behind the same green contract rows.
- **Then**: US3 cleanup and the fourth-provider check.
- **Commit rule**: the red Phase 2 is not committed alone (gates would fail); it is committed with the US1 stage once its gates exit 0.

## Notes

- Model ids and prices never go in code or fixtures beyond placeholders (Principle VI).
- No test reaches the network (Principle III); recorded replies only.
- Gemini `thinkingBudget` value and OpenAI cache behaviour are decided by T005 and T006 evidence, not by assumption.
