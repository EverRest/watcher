# Implementation Plan: Multi-Provider Model Layer

**Branch**: `016-model-providers` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/016-model-providers/spec.md`

## Summary

Choose Anthropic, OpenAI or Gemini for each analysis step (triage, analyze, escalate) from configuration. Anthropic goes through the official `anthropic-ai/sdk` (reversing the 0.3.0 removal, with the reasons in R1). OpenAI and Gemini use adapters over the package's Guzzle client, because the third-party SDKs fail the owner and PHP-floor checks (R2). The pipeline before the provider (privacy, selection, budget) is unchanged. The contract is `ModelProvider` with a provider-neutral `ModelReply`.

## Technical Context

**Language/Version**: PHP ^8.2 (CI tests 8.2 to 8.5)

**Primary Dependencies**: `anthropic-ai/sdk` ~1.3.0 (new, required); `guzzlehttp/guzzle` (existing, PSR-18 client for the SDK and the two adapters). No `aisdk/*` package (R2).

**Storage**: unchanged (state stores). No new persisted data.

**Testing**: PHPUnit 10/11 through ParaTest; recorded HTTP fixtures per provider (Guzzle `MockHandler`); no network.

**Target Platform**: Linux (Kubernetes CronJob), macOS for development.

**Project Type**: library (PHP package), with the Laravel/Lumen adapter.

**Performance Goals**: unchanged; one call per step, same as today. The SDK's extra work per call is not measured here and is checked in tasks against the existing run-time fixture.

**Constraints**: Principle I (privacy fails closed), II (read-only), III (no network in tests), V (token budget per step, no token increase), VI (model ids and prices from config). The new dependency needs the written reason in Complexity Tracking.

**Scale/Scope**: three providers, three steps, one analyzer. Roughly 400 lines of new production code, a net reduction of about 30 lines once `AnthropicHttpClient` is removed (estimate; checked in tasks).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How the plan meets it |
|---|---|---|
| I. Privacy Fails Closed | Pass, with a change to the test | The payload is built before any provider. Each adapter receives the same reduced request. The privacy corpus test runs against each adapter's outgoing body (R9, tasks). |
| II. Read-Only by Design | Pass | Providers receive only generation requests. No write endpoints are used. The OpenAI and Gemini adapters have no list or upload calls. |
| III. Test-First | Pass | Contract tests per provider are written first, with recorded fixtures (tasks phase). The SDK adapter is tested through a Guzzle `MockHandler`. |
| IV. Code Decides, the Model Judges | Pass | Selection, validation and retries are unchanged and provider-neutral. |
| V. Token and Cost Discipline | Pass, with the stated expectation | Expected increase: **0 input and 0 output tokens per run** for the same configuration (R8). Cost can differ where caching thresholds apply; the cost line reports it. Token-budget fixtures run for all three providers. |
| VI. Configuration and Contracts over Hard-Coding | Pass | Provider names, model ids, prices and keys come from configuration. The contract `ModelProvider` has three implementations and a fake, so it is a contract by Principle VIII. |
| VII. Failures Are Loud | Pass | Each provider error maps to the existing `AnalyzerFailed` messages, with the class or HTTP status only (no URL, key or body). |
| VIII. Simplicity | Pass, with a recorded justification | One new runtime dependency (`anthropic-ai/sdk`) and its tree, justified in R1. Two third-party SDKs were rejected for the stated reasons (R2). No new contracts beyond `ModelProvider`. |

Gate result: **pass**, with one recorded justification (Complexity Tracking).

## Project Structure

### Documentation (this feature)

```text
specs/016-model-providers/
├── plan.md              # this file
├── research.md          # Phase 0 output: R1 to R9
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output: how to validate the feature
├── contracts/
│   ├── model-provider.md   # the ModelProvider contract and ModelReply
│   └── analysis-config.md  # the new and changed configuration keys
├── checklists/
│   └── requirements.md
└── tasks.md             # Phase 2 output (/speckit-tasks, not created here)
```

### Source Code (repository root)

```text
src/Analyze/
├── ModelProvider.php            # renamed from MessagesClient (R6)
├── ModelReply.php               # unchanged shape; stop reasons normalised
├── AnthropicSdkProvider.php     # new: anthropic-ai/sdk, replaces AnthropicHttpClient
├── OpenAiProvider.php           # new: Guzzle adapter (R2)
├── GeminiProvider.php           # new: Guzzle adapter (R2)
├── ProviderRegistry.php         # new: name -> ModelProvider, step -> provider (R4)
├── TriageRequest.php            # gains step-provider routing (R4)
├── AnalysisRequest.php          # gains step-provider routing (R4)
├── ModelRouter.php              # sets the step on each request
├── AnthropicAnalyzer.php        # renamed later if the class name is kept; see tasks
└── ProviderSchema.php           # unchanged (stripped schema is provider-neutral)

src/Config/
├── WatchConfig.php              # analysis.provider / providers / api_keys; client rules (R7)
├── KeyCatalog.php               # new keys with env names
└── Factory.php                  # builds the registry; model catalog only for anthropic

tests/Unit/Analyze/             # adapter mapping tests, recorded fixtures per provider
tests/Contract/ModelProviderContractTest.php   # shared contract run against all adapters + fake
tests/EndToEnd/                 # one run per provider with recorded replies
tests/Fixtures/privacy          # extended to each adapter's outgoing body

docs/configuration.md           # new keys; analysis.client rule
docs/smoke-test.md              # provider choice; cost check per provider
CHANGELOG.md                    # Unreleased: the SDK reversal, breaking config notes
composer.json                   # require anthropic-ai/sdk ~1.3.0; allow-plugins for php-http/discovery
```

**Structure Decision**: extend `src/Analyze/` in place. The analyzer's seam (`MessagesClient::create`) is already the right one; the change is in what implements it and in how a step picks one.

## Complexity Tracking

> Fill ONLY if Constitution Check has violations that must be justified

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| New runtime dependency `anthropic-ai/sdk` with a tree of six packages (R1) | The owner asked for maximum official SDK use. The SDK is maintained by Anthropic and follows API changes. | Keeping `AnthropicHttpClient` keeps our transport code and contradicts the owner's decision. The 0.3.0 reasons are partly addressed (explicit base URL) and partly accepted (dependency weight, plugin prompt). |
| Reversal of the 0.3.0 SDK removal | Same as above | Recorded in the 0.4.0 CHANGELOG entry as a breaking change for configuration that set `analysis.client = sdk` (already refused since 0.3.0). |

## Open items for tasks

- Gemini: confirm the REST field names and the thinking-off switch against the live reference (R3, unverified items).
- OpenAI: confirm cache-write behaviour with a recorded fixture before claiming caching (R3).
- SDK: pin the exact version after a smoke test against a recorded fixture; the 1.x line is four days old at this writing (1.0.0 to 1.3.0 were published between 2026-10-21 and 2026-10-23).
- `composer.json`: `allow-plugins` for `php-http/discovery`, so installs do not prompt.
