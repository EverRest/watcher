# Research: Multi-Provider Model Layer

Decisions taken while planning. Each: decision, why, rejected alternative. Checked on 2026-10-27.

## R1. Anthropic transport: the official `anthropic-ai/sdk`

**Decision.** Use `anthropic-ai/sdk` `~1.3.0` for every Anthropic call. The base URL is set explicitly from configuration (default `https://api.anthropic.com`). The SDK's `ANTHROPIC_BASE_URL` environment fallback is never used. Retries are set explicitly (`maxRetries` from config, default `0`, because the analyzer already makes two attempts); the timeout is explicit (`lock_ttl / 8`, as today).

**Facts checked (Packagist metadata, GitHub API, 2026-10-27).**

- `anthropic-ai/sdk` 1.3.0, published 2026-10-23, Apache-2.0, PHP `^8.1`, repository `anthropics/anthropic-sdk-php` (Anthropic's organisation, 194 stars, pushed 2026-10-23, not archived).
- Its `require` brings: `open-telemetry/api`, `php-http/discovery`, `psr/http-client`, the PSR-18 and PSR-17 implementation placeholders, `psr/http-message`, `standard-webhooks/standard-webhooks`.
- The SDK's own docs (platform.claude.com) describe the PHP library as beta and recommend Guzzle 7 as the HTTP client.

Sources: https://repo.packagist.org/p2/anthropic-ai/sdk.json, https://api.github.com/repos/anthropics/anthropic-sdk-php, https://platform.claude.com/docs/en/cli-sdks-libraries/sdks/php

**Why.** The owner asked for the maximum official SDK use. The SDK is maintained by Anthropic, so request fields and error classes follow the API. Our `AnthropicHttpClient` (127 lines) is replaced by the SDK call plus one adapter that maps the SDK response to `ModelReply`.

**Principle VIII (written reason for the new dependency tree).** The SDK adds six runtime packages. Two of them matter: `php-http/discovery` brings back the Composer plugin prompt that 0.1.3 removed, and `open-telemetry/api` is pulled in although we do not use it. Both costs are accepted by the owner's choice. The `allow-plugins` entry for `php-http/discovery` is set in `composer.json` so installs stop prompting.

**Reversal of 0.3.0.** 0.3.0 removed `analysis.client = sdk` for two recorded reasons. The first, the `ANTHROPIC_BASE_URL` fallback, is removed by the explicit base URL in R1. The second, dependency weight and the plugin prompt, is not removed: it is the accepted cost. The CHANGELOG entry for 0.4.0 must say so.

**Rejected.**
- Keep `AnthropicHttpClient` and skip the SDK. It contradicts the owner's choice and leaves the transport code in the repo.
- Use the SDK as an optional `suggest`. Then the default provider would need an extra install, which is what 0.1.3 reversed for the same reason.

## R2. OpenAI and Gemini: third-party SDKs fail the checks, fallback to Guzzle adapters

**Decision.** Do not depend on `aisdk/openai` or `aisdk/google`. Write the OpenAI and Gemini adapters over the package's Guzzle client, as the spec's fallback rule says.

**Facts checked (Packagist p2 JSON and GitHub API, 2026-10-27).**

| Check | `aisdk/openai` / `aisdk/google` | Result |
|---|---|---|
| Owner | GitHub org `phpaisdk` created 2026-05-12, 50 repos, empty description. Not OpenAI or Google. | fail |
| Version | `0.8.0` (0.x, pre-1.0). Repo `phpaisdk/openai` created 2026-06-30, 0 stars, 0 open issues. | fail |
| PHP | `php: ^8.3`. Our floor is `^8.2` and CI tests 8.2 until Dec 2026. | fail: would drop 8.2 |
| License | MIT | pass |
| Dependencies | `aisdk/core ^0.8.0`, `aisdk/openai-compatible ^0.8.0` | adds two more 0.x packages |

Sources: https://repo.packagist.org/p2/aisdk/openai.json, https://repo.packagist.org/p2/aisdk/google.json, https://api.github.com/repos/phpaisdk/openai

The owner-check and the PHP floor fail on their own. The package description says "Official", but nothing ties that label to OpenAI or Google.

**Why Guzzle adapters.** Each adapter is a single class of about 100 lines: one POST, one reply mapping, the same bounded reply reader and error mapping that `AnthropicHttpClient` has today. No new dependency.

**Rejected.**
- `aisdk/openai` and `aisdk/google`: fail the checks above.
- Other community packages (`openai-php/client`, `google-gemini-php/client`): not checked in this plan. Not official either; the same objection applies. Listed only so the owner knows they were not considered.

## R3. Capability matrix (what each provider gives us)

| Capability | Anthropic | OpenAI | Gemini |
|---|---|---|---|
| Structured output | `output_config.format` json_schema (today) | `response_format` `json_schema` with `strict` (Chat Completions) | `responseMimeType` `application/json` + `responseJsonSchema` |
| Prompt caching | explicit `cache_control` on the system block; cache read and creation tokens reported | automatic for prefixes of at least 1024 tokens; `usage.prompt_tokens_details.cached_tokens` reported; no cache-write counter | implicit caching on Gemini 2.5 and later (default on); `usageMetadata.cachedContentTokenCount` reported; minimum 2048 tokens for 2.5, 4096 for 3.x |
| Cache write tokens | yes | not reported: map to 0 | not reported: map to 0 |
| "No thinking" for triage | `thinking: disabled` (today) | model-dependent: `reasoning_effort` where supported | model-dependent: `thinkingConfig` where supported |
| Stop reasons | `end_turn`, `max_tokens`, `refusal`, `pause_turn` | `stop`, `length`, `content_filter` | `STOP`, `MAX_TOKENS`, `SAFETY` and others |

Sources (from search results, not fetched in full): https://developers.openai.com/docs/guides/prompt-caching (the 1024 threshold and `cached_tokens` field), https://ai.google.dev/gemini-api/docs/caching (2.5 implicit caching and minimums; the REST field name `cachedContentTokenCount` appears in two sources, not in the reference page). The tasks phase re-checks both against the live docs.

**Unverified, to check in tasks before the adapter is written:**

- OpenAI: a community report says a strict `json_schema` can keep cache-write tokens at 0 on some newer models. Caching is not claimed for OpenAI structured calls until a recorded fixture shows a hit.
- Gemini: whether the output token count includes thinking tokens (billed) and how to turn thinking off per model. The triage step must not pay for thinking.
- Gemini: `responseJsonSchema` accepts the stripped schema we send today. The tasks phase verifies it on a recorded fixture.

## R4. Per-step provider selection

**Decision.** `analysis.provider` stays the default for all steps. A new `analysis.providers` map (`triage`, `analyze`, `escalate`) overrides it per step. Each step's model id stays in `analysis.models`. Every model id must exist in the provider's price table (`analysis.prices`). A step's provider is carried by its request (`TriageRequest`, `AnalysisRequest`), set by the router from the step name.

**Why this shape.** `escalate` calls `analyze()` with its own model (`ModelRouter` lines 163 and 150–157), so the provider cannot be picked from the call kind alone. The step name must travel with the request.

**Rejected.** Encode the provider in the model id (`openai:gpt-…`). It makes the model id no longer the provider's id, and the existing price keys and `check` probes would need rewriting.

## R5. Keys per provider

**Decision.** `analysis.api_key` keeps its meaning (the Anthropic key) for compatibility. New `analysis.api_keys` map `anthropic|openai|gemini` → key, with environment variables `WATCHER_ANTHROPIC_KEY`, `WATCHER_OPENAI_KEY`, `WATCHER_GEMINI_KEY`. A provider that is used by a step must have a key, or config load fails naming the key path (not the value).

## R6. Shared provider contract

**Decision.** Rename `MessagesClient` to `ModelProvider` and keep its single method shape. `ModelReply` already carries no provider-specific fields (text, normalised stop reason, model, input, output, cache read, cache write). Stop reasons are normalised in each adapter to `end`, `max_tokens`, `refusal`, `pause`, or `null`.

**Why a contract.** Principle VIII: a contract exists where more than one implementation is named. This spec names three, plus a test fake.

## R7. `analysis.client`

**Decision (my reading of the owner's answer "accept, reject with explanation").**

- Absent: fine.
- `http`: accepted and ignored, with a config notice that it no longer selects a transport. Every existing config has this value (it was the default).
- Any other value (including `sdk`): refused at config load, with a message that names the replacement: "analysis.client is no longer a transport choice; the Anthropic transport is the official SDK. Delete the key."

**Open for the owner:** whether a deprecation notice for `http` is wanted, or silence.

## R8. Token expectation (Principle V)

**Expected increase: 0 input tokens and 0 output tokens per run** for the same configuration. The prompts, the stripped schema and the payload are unchanged. The Anthropic request body is the same as today.

**Cost may differ, not tokens.** OpenAI and Gemini cache only from their thresholds (1024 and 2048 tokens). A short system prompt does not cache on those providers, so the cost line reports more full-price input for them. The token-budget fixtures run for each provider.

## R9. Privacy

The privacy pipeline runs before any provider is called (unchanged). The privacy corpus test (`tests/Fixtures/privacy`) is run against the request each provider adapter receives, not only the one the Anthropic path builds. This is the Principle I change-rule.

## R3 live-doc check (2026-10-27, WebFetch)

**OpenAI (developers.openai.com/api/docs/guides/prompt-caching).**
- Minimum cacheable prompt: "1,024 tokens for GPT-5.6 and later"; for earlier models the minimum "varies by request settings" (not a fixed 1024).
- Cached-token field on the Responses API: `usage.input_tokens_details.cached_tokens`. The Chat Completions field `usage.prompt_tokens_details.cached_tokens` (used in the plan) is not on this page: confirm on the Chat Completions reference before the adapter relies on it.
- Cache writes are reported on the Responses API as `input_tokens_details.cache_write_tokens`, a field this plan does not map. Keep cacheWriteTokens = 0 for Chat Completions until verified.
- The page lists `text.format` (structured output) among settings that change the cached prefix; it does not say how a schema changes hit rates. The community report stays unverified.

**Gemini.**
- `ai.google.dev/gemini-api/docs/thinking` and `.../structured-output` describe the Interactions API (`thinking_level`, `response_format` with `mime_type` and `schema`), not `generateContent`. The REST field names `thinkingConfig.thinkingBudget`, `generationConfig.responseJsonSchema` and `finishReason` values are NOT verified.
- `usageMetadata.cachedContentTokenCount`, `candidatesTokenCount`, `thoughtsTokenCount` are documented on the generate-content reference (verified in part: `cachedContentTokenCount`, `thoughtsTokenCount` described; whether `candidatesTokenCount` includes thoughts is not stated there). The Interactions thinking page shows `total_thought_tokens` reported separately from output tokens, which suggests thoughts are not in the output count; not confirmed for generateContent.
- Unsupported JSON Schema keywords (Interactions): minLength, maxLength, minProperties, maxProperties, uniqueItems, contains, minContains, maxContains are not enforced; minItems and maxItems are. The analyzer already validates these in code (OutputValidator), so the stripped schema stays valid.

Open for T005 (blocked on the generate-content reference's GenerationConfig section, which WebFetch cannot page): the thinking-off field and value, and the exact generationConfig field names.

## R3 verified against official machine-readable sources (2026-10-27)

Sources: the Gemini discovery document `https://generativelanguage.googleapis.com/$discovery/rest?version=v1beta` and the OpenAI OpenAPI spec `https://raw.githubusercontent.com/openai/openai-openapi/master/openapi.yaml`. These replace the earlier search-snippet and guide-page checks, which were partial.

**Gemini (generateContent).**
- `generationConfig`: `responseMimeType`, `responseJsonSchema`, `maxOutputTokens` exist with these names.
- `thinkingConfig` (`ThinkingConfig`): `thinkingBudget` and `thinkingLevel`. The description of `thinkingConfig` says: "An error will be returned if this field is set for models that don't support thinking." The reference does NOT document `thinkingBudget: 0` as "thinking off". So the adapter sends no `thinkingConfig`. Triage on Gemini may therefore think and pay for thought tokens. Open: a per-model setting that turns thinking off, verified against the model list.
- `usageMetadata`: `promptTokenCount` "includes the number of tokens in the cached content"; `cachedContentTokenCount` is the cached part; `candidatesTokenCount` is "across all the generated response candidates"; `thoughtsTokenCount` is separate; `totalTokenCount` = prompt + tool-use prompt + thoughts + candidates. So output tokens are `candidatesTokenCount + thoughtsTokenCount`, and input tokens are `promptTokenCount - cachedContentTokenCount`. The adapter does both.
- `finishReason` values include `STOP`, `MAX_TOKENS`, `SAFETY`, plus many others (mapped to null when not listed in the adapter).

**OpenAI (Chat Completions).**
- `max_completion_tokens` exists.
- `response_format` `json_schema` with `strict` (default false) exists. The adapter sends `strict: false` because the stripped schema lacks `additionalProperties: false`.
- `reasoning_effort` accepts `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`. "Not all reasoning models support every value." The adapter sends `none` for the non-thinking step. A model that rejects the parameter fails the call loudly (400), which the operator must avoid by choosing a model.
- `finish_reason`: `stop`, `length`, `content_filter` (plus others).
- `usage.prompt_tokens` includes the cached part; `prompt_tokens_details.cached_tokens` is the cached part. The adapter sends uncached input = prompt − cached.

**Still open.**
- Gemini: a way to make triage non-thinking per model (see above).
- OpenAI: whether a strict schema prevents cache writes (only a community report; OpenAI reports no cache-write count on Chat Completions, so cache writes stay 0).
