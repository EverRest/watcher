# Contract: analysis configuration (changed keys)

Only keys that change are listed. Everything not listed behaves as today.

| Key | Type | Default | Rule |
|---|---|---|---|
| `analysis.provider` | `anthropic` \| `openai` \| `gemini` | `anthropic` | default provider for every step. Was: `anthropic` only. |
| `analysis.providers` | map step → provider name | empty | optional per-step override; keys are `triage`, `analyze`, `escalate`; unknown keys fail at load |
| `analysis.api_key` | string (secret) | none | Anthropic key, kept for compatibility; same as `analysis.api_keys.anthropic` |
| `analysis.api_keys` | map provider name → string (secret) | empty | `anthropic`, `openai`, `gemini`; a used provider without a key fails at load |
| `analysis.providers_options` | map provider name → `{base_url, retries}` | per-provider defaults | `base_url` must be `https`; `retries` integer 0 to 3, default 0 |
| `analysis.client` | string | absent | absent: fine. `http`: accepted and ignored (every existing config). Any other value: refused at load (R7) |

## Environment variables

| Variable | Maps to |
|---|---|
| `WATCHER_ANTHROPIC_KEY` | `analysis.api_keys.anthropic` (existing `analysis.api_key` also reads it) |
| `WATCHER_OPENAI_KEY` | `analysis.api_keys.openai` |
| `WATCHER_GEMINI_KEY` | `analysis.api_keys.gemini` |
| `WATCHER_PROVIDER_TRIAGE`, `WATCHER_PROVIDER_ANALYZE`, `WATCHER_PROVIDER_ESCALATE` | `analysis.providers.<step>` |

## Validation at load (before any external call)

1. `analysis.provider` and every value in `analysis.providers` is one of the three names.
2. Each provider used by a step has a key, else the message names the key path (`analysis.api_keys.openai`), never a value.
3. Each step's model id appears in `analysis.prices`.
4. `analysis.client`: absent or `http`; anything else fails with the replacement message from R7.
5. `base_url` for every provider is `https` (the existing rule for remote URLs).

## Example

```php
'analysis' => [
    'provider' => 'anthropic',
    'providers' => [
        'triage' => 'gemini',      // cheapest step on Gemini
        'analyze' => 'anthropic',
        'escalate' => 'anthropic',
    ],
    'api_keys' => [
        'anthropic' => getenv('WATCHER_ANTHROPIC_KEY') ?: null,
        'gemini' => getenv('WATCHER_GEMINI_KEY') ?: null,
    ],
    'models' => [
        'triage' => 'MODEL_ID_FROM_YOUR_CONSOLE',
        'analyze' => 'MODEL_ID_FROM_YOUR_CONSOLE',
        'escalate' => 'MODEL_ID_FROM_YOUR_CONSOLE',
    ],
    // prices keyed by model id, as today
],
```

Model ids and prices are placeholders: they come from the operator's provider console and pricing page, never from this document (Principle VI).
