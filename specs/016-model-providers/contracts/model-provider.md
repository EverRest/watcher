# Contract: ModelProvider

The one seam between the analyzer and any model service. Replaces `MessagesClient`.

## Interface

```php
namespace Watcher\Analyze;

interface ModelProvider
{
    /**
     * One structured-output call, never retried here.
     *
     * @param array<string, mixed> $schema
     *
     * @throws AnalyzerFailed 'authentication failed' or 'provider unavailable (...)'; nothing from the request or the reply is carried
     */
    public function create(string $model, int $maxTokens, string $system, string $user, array $schema, bool $thinking): ModelReply;
}
```

The signature is the current `MessagesClient::create` unchanged. Only the name changes.

## Rules every implementation must follow

1. **Input is the reduced payload only.** The arguments are the complete outgoing content. An implementation MUST NOT read other data (logs, configuration of other steps, environment for content).
2. **Output is `ModelReply`.** No provider-specific field leaves the adapter. Stop reasons are normalised as in `data-model.md`.
3. **Structured output** is requested with `$schema`. The reply's JSON is validated by the analyzer, not by the adapter.
4. **Caching** is requested only where the provider supports it (`system` is the stable prefix). Where it does not, the adapter sends the same content without any cache field.
5. **Thinking off** when `$thinking` is false: each adapter maps this to its provider's "no reasoning" setting. Where a provider cannot turn reasoning off, the adapter says so in its docblock and the plan's risk list gets an entry.
6. **Secrets** are never in an exception message, a log line or a `__debugInfo` output. The key is held as the existing `AnthropicHttpClient` holds it (in a closure).
7. **Timeouts and retries** come from the constructor arguments, which come from configuration. No hidden SDK default.
8. **Failures** map to `AnalyzerFailed` with one of: `authentication failed` (HTTP 401 or 403), `provider unavailable (HTTP <code>)`, `provider unavailable (<exception class>)`, `provider unavailable (invalid reply)`. A refusal is a successful `ModelReply` with `stopReason = refusal`; the analyzer decides what to do with it.

## Implementations (this release)

| Class | Transport | Notes |
|---|---|---|
| `AnthropicSdkProvider` | `anthropic-ai/sdk` ~1.3.0 with a PSR-18 Guzzle client | base URL explicit; `maxRetries` from config; SDK exceptions mapped to the failure messages above |
| `OpenAiProvider` | Guzzle, POST to the configured base URL | Chat Completions, `response_format` json_schema; reads `usage.prompt_tokens_details.cached_tokens` |
| `GeminiProvider` | Guzzle, POST to the configured base URL | `responseJsonSchema`; reads `usageMetadata` counts |
| test fake | in memory | used by the shared contract test and the end-to-end run |

## Shared contract test

One test class runs the same cases against every implementation, each with its own recorded fixtures:

- valid structured reply → `ModelReply` with text, usage and normalised stop reason;
- 401 → `authentication failed`;
- 429 and 5xx → `provider unavailable (HTTP …)`;
- non-JSON or oversized body → `provider unavailable (invalid reply)`;
- refusal → `stopReason = refusal`;
- max-tokens cut-off → `stopReason = max_tokens`;
- a reply with a missing usage count → `provider unavailable (invalid reply)`;
- no URL, key or body text in any `AnalyzerFailed` message (asserted on every failure case).
