# Feature Specification: Multi-Provider Model Layer

**Feature Branch**: `016-model-providers` (not created yet)

**Created**: 2026-10-27

**Status**: Draft

**Input**: User description: "Spec 016: multi-provider model layer. Replace the single Anthropic client with a provider abstraction: Anthropic via the official anthropic-ai/sdk (pinned base URL, retries and timeouts set explicitly, no ANTHROPIC_BASE_URL fallback), OpenAI and Gemini via small adapters behind the same interface. Reduce our own HTTP/client code where an official SDK covers it. Keep the privacy pipeline, structured output validation, prompt caching where the provider supports it, token budget and cost accounting provider-neutral. Model ids and prices stay in config, never in code. Requirements from the owner: maximise official SDK use to shrink the codebase; allow choosing any of the three providers per step (triage, analyze, escalate)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Choose a provider per step from configuration (Priority: P1)

An operator of a service runs watcher with Anthropic for every step today. They want to run the cheap triage step on one provider and the analysis step on another, and change either without touching code. They edit the configuration, run `watcher check`, and the run uses the chosen providers.

**Why this priority**: This is the owner's main goal and the reason for the feature. Without it the other stories have no value.

**Independent Test**: With recorded responses for two providers, set triage to provider A and analyze to provider B in configuration only. A run produces a digest, and each step's requests went to the provider configured for that step.

**Acceptance Scenarios**:

1. **Given** a configuration that names Anthropic for all three steps, **When** a run happens, **Then** the outcome, findings and digest match the outcome of the same run before this feature.
2. **Given** a configuration that names different providers for triage and analysis, **When** a run happens, **Then** triage requests go only to the triage provider and analysis requests only to the analysis provider.
3. **Given** a step names a provider that is not supported, **When** the configuration loads, **Then** the run stops before any external call and the message names the setting.
4. **Given** a step names a provider whose credentials are missing, **When** the configuration loads, **Then** the run stops before any external call and the message names the missing setting, without printing any secret value.

---

### User Story 2 - Use OpenAI or Gemini with the same guarantees (Priority: P2)

An operator wants OpenAI or Gemini for one or more steps. The same structured-output validation, retry rule, token budget, daily spend cap, privacy rules and cost line apply, regardless of provider.

**Why this priority**: It delivers the owner's second goal, but it depends on the provider contract from Story 1.

**Independent Test**: For each of the three providers, run the same recorded log window through the pipeline. The digest structure, the privacy check and the budget behaviour are the same; only the model identifiers and the cost figures differ.

**Acceptance Scenarios**:

1. **Given** a step configured for OpenAI or Gemini, **When** the provider returns output that does not match the schema, **Then** the step retries once and then reports a failure, as with Anthropic.
2. **Given** a provider returns token usage, **When** the digest is built, **Then** the tokens and approximate cost are shown for that step, using the prices from configuration.
3. **Given** a provider does not support prompt caching, **When** a step runs, **Then** the step runs without caching and the cost line reports zero cached tokens.
4. **Given** a model identifier has no price in configuration, **When** a step runs, **Then** the existing unknown-price behaviour applies and the digest says so.

---

### User Story 3 - Maintain one transport path per provider (Priority: P3)

A maintainer wants the least code possible. Anthropic uses the official SDK. OpenAI and Gemini use thin adapters over the HTTP client the package already has. Adding a fourth provider needs one adapter and configuration, and no change to the core.

**Why this priority**: It shrinks the codebase, which the owner asked for, but it does not change what operators see.

**Independent Test**: Add a fake fourth provider in a test. It works through the same contract with no change to any file outside its own adapter and the configuration.

**Acceptance Scenarios**:

1. **Given** the Anthropic adapter, **When** a request is sent, **Then** the base URL is the one pinned in code or configuration, and the SDK does not read any base URL from the environment.
2. **Given** the Anthropic adapter, **When** a request times out or hits a retryable error, **Then** the number of attempts and the timeout are the values set in configuration, not SDK defaults.
3. **Given** a new provider adapter, **When** the test suite runs, **Then** the shared provider contract tests run against it without any change to core files.

### Edge Cases

- A step names a model that belongs to another provider: config load fails and names the step.
- All three steps name the same provider: works as a single-provider run.
- A provider returns a refusal, a truncated answer (max tokens reached) or a pause: each maps to the existing failure notice, not to a guess.
- A provider is unreachable after retries: the run fails loudly with the existing analysis-failed notice and records no run, so the next trigger retries.
- A provider's SDK or HTTP client raises an error that contains a request URL or a credential: the error text shown to the operator contains neither.
- Redaction and the allowlist run before any provider is called, whatever provider is chosen. A provider adapter never receives a field the allowlist removed.
- The configured daily budget is spent: the budget notice appears regardless of the provider used.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The analysis steps (triage, analyze, escalate) MUST each be assigned one provider from configuration. Providers are chosen independently per step.
- **FR-002**: The supported providers in this release MUST be Anthropic, OpenAI and Gemini. Adding another provider MUST NOT require a change to core files.
- **FR-003**: Model identifiers, prices and provider names MUST come from configuration only. No model identifier or price may appear in code.
- **FR-004**: Configuration MUST be validated before any external call. An unknown provider, a model that does not match its provider, or missing credentials MUST fail with a message that names the setting and never prints a secret value.
- **FR-005**: The Anthropic adapter MUST use the official Anthropic PHP SDK for requests, with the base URL pinned, and MUST NOT read a base URL from the environment.
- **FR-006**: Retries and timeouts for every provider MUST be set explicitly in configuration with documented defaults. SDK or client defaults MUST NOT apply silently.
- **FR-007**: OpenAI and Gemini MUST be reached through adapters over the package's existing HTTP client, unless an official PHP SDK for that provider is available when the plan is written.
- **FR-008**: Every provider MUST return the same internal result shape: the structured output, token usage (input, output, cached), and the model identifier used. Structured output MUST be validated in code against the same schema for all providers.
- **FR-009**: Invalid output MUST be retried once and then reported as a failure, for every provider.
- **FR-010**: The privacy pipeline (allowlist, redaction, template normalisation) MUST run before any provider is called. The adapter contract MUST accept only the reduced input.
- **FR-011**: Prompt caching MUST be used where the provider supports it and MUST be turned off where it does not. The stable prompt prefix MUST be kept the same for all providers.
- **FR-012**: The token budget per step, the daily spend cap and the cost line MUST be computed the same way for every provider from the provider's reported usage and the configured prices.
- **FR-013**: The contract tests MUST run against every provider adapter using recorded responses. No test may reach the network.
- **FR-014**: Existing configuration keys for the Anthropic client MUST keep working, or fail at config load with a message naming the replacement key. Existing deployments MUST keep their outcome without a configuration change where the key still exists.
- **FR-015**: Any provider failure MUST produce the existing visible failure notice; no provider failure may look like "nothing found".

### Key Entities *(include if feature involves data)*

- **Provider**: a named external model service (Anthropic, OpenAI, Gemini). Attributes: name, credentials reference, base URL, timeout, retry count.
- **Step assignment**: the mapping from a step (triage, analyze, escalate) to a provider and a model identifier, from configuration.
- **Model reply**: the common result of one call: structured output, token usage, model identifier, stop reason.
- **Price entry**: configured price per model identifier, input, output and cache rates, in USD per million tokens.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An operator changes the provider of any single step by editing configuration only, in under 5 minutes, with no code change.
- **SC-002**: For a run configured as before this feature (Anthropic for all steps), the outcome, findings and digest match the previous version on the existing recorded fixtures.
- **SC-003**: Across all three providers, zero seeded personal values appear in any outbound request body in the privacy test corpus.
- **SC-004**: For each of the three providers, the tokens and cost shown in the digest equal the provider's reported usage priced with the configured prices, in 100% of recorded fixtures.
- **SC-005**: A fourth provider can be added with one adapter and configuration, and the shared contract tests pass for it without editing any core file.
- **SC-006**: In 100% of recorded failure cases (unreachable, unauthorised, invalid output, refusal, truncation), the run reports a visible failure and exits non-zero or posts a notice.

## Assumptions

- The official Anthropic PHP SDK (`anthropic-ai/sdk`) is used for Anthropic. It was removed in 0.3.0; reinstating it is a reversal of that decision and the plan must record the reason, the version pin and its beta status.
- No first-party PHP SDK for OpenAI or Gemini was found when this spec was written. Those adapters use the package's HTTP client. The plan re-checks this and switches to an official SDK only if one exists.
- All three providers are in scope for this release; no provider is treated as a preview.
- Prompt caching is applied only where the provider exposes it; the plan records which providers do.
- The model used for each step is named explicitly in configuration; there is no automatic model selection by provider.
- Each provider has its own price table in configuration; no cross-provider price conversion is attempted.
- Existing Anthropic deployments keep working without changing configuration where the keys still apply.
- Single-provider behaviour and existing fixtures are the regression baseline.
