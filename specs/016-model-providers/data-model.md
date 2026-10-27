# Data Model: Multi-Provider Model Layer

No persisted data is added. Everything below is configuration or in-memory per call.

## Provider

A named model service. Closed set in this release.

| Field | Values | Source |
|---|---|---|
| name | `anthropic`, `openai`, `gemini` | `analysis.provider`, `analysis.providers.<step>` |
| api key | string, secret | `analysis.api_keys.<name>` or `WATCHER_<NAME>_KEY`; Anthropic also accepts `analysis.api_key` |
| base URL | fixed per provider in code for Anthropic (`https://api.anthropic.com`), configurable with an explicit value | `analysis.providers_options.<name>.base_url` (default shown) |
| timeout | seconds, 10 to 180 | derived from `schedule.lock_ttl` (existing rule) |
| retries | integer, default 0 | `analysis.retries` (SDK `maxRetries` for Anthropic; the analyzer's own second attempt is unchanged) |

Rules: a provider used by at least one step must have a key; a key for an unused provider is ignored.

## Step assignment

| Field | Type | Rule |
|---|---|---|
| step | `triage` \| `analyze` \| `escalate` | closed set (existing) |
| provider | Provider name | `analysis.providers.<step>` if set, else `analysis.provider` |
| model | string | `analysis.models.<step>` (existing); no default in code |
| price | `[input, output, cache_read, cache_write]` USD per million tokens | `analysis.prices.<model>` (existing); a used model without a price stops the run as today (`UnknownModelPrice`) |

Invariant: each `model` appears in `analysis.prices`. The provider does not appear in the price key.

## ModelRequest (what a provider receives)

Built by the analyzer from the reduced payload. A provider adapter cannot add fields.

| Field | Type | Notes |
|---|---|---|
| model | string | the step's model id |
| maxTokens | int | per-step cap (existing `max_output_tokens` rules, including the retry doubling) |
| system | string | the stable prefix (system prompt, schema text, rules); the cache target |
| user | string | the compact payload for this call |
| schema | array | the stripped schema from `ProviderSchema` (same for all providers) |
| thinking | bool | `false` for triage (adapters map it to each provider's "no reasoning" setting) |

## ModelReply (what the analyzer receives)

Provider-neutral. Field names and types are those of the current class; only the meaning of `stopReason` is pinned down.

| Field | Type | Normalisation |
|---|---|---|
| text | ?string | first text block; null when none |
| stopReason | ?string | one of `end`, `max_tokens`, `refusal`, `pause`, or null (unknown or absent) |
| model | string | the model id the provider echoed (may differ, e.g. a dated alias) |
| inputTokens | int >= 0 | the provider's input count, uncached part included as the provider reports it |
| outputTokens | int >= 0 | the provider's output count |
| cacheReadTokens | int >= 0 | 0 where the provider reports none |
| cacheWriteTokens | int >= 0 | 0 for OpenAI and Gemini (no write counter) |

Stop-reason mapping per adapter:

| Normalised | Anthropic | OpenAI (Chat Completions) | Gemini |
|---|---|---|---|
| `end` | `end_turn` | `stop` | `STOP` |
| `max_tokens` | `max_tokens` | `length` | `MAX_TOKENS` |
| `refusal` | `refusal` | `content_filter` | `SAFETY` |
| `pause` | `pause_turn` | none | none |
| null | anything else | anything else | anything else |

## Usage (existing)

Carries the requested model id (the price key), the returned model, and the four token counts, summed over attempts. Cost is `CostCalculator`: unchanged.

## Validation rules

- Config: unknown provider name, a step's model missing from prices, a missing key for a used provider, `analysis.client` other than absent or `http` (R7): all fail at load, naming the key path, never the value.
- Reply: a provider reply that lacks text, usage or a count where one is required is an invalid reply and follows the existing retry rule.
