# Feature Specification: Analysis Without the Model

**Feature Branch**: `feat/analysis-without-model`

**Created**: 2026-11-15

**Status**: Draft

**Target release**: next MINOR after 0.18.2 (set at release time; VERSION not changed here)

**Input**: Owner request (2026-11-15): when the model provider cannot be used because of the key or the account, watcher must still deliver the digest, without the AI part, instead of failing the run.

## Clarifications

### Session 2026-11-15 (owner decisions, fixed)

- Q: Should the last transient failure of a window also deliver without AI? → A: Yes (FR-013).
- Q: How is growth judged for a group shown without AI? → A: The simplest way: against the count it was shown with (FR-014).
- Q: `analysis_skipped` in the public event? → A: No; internal run record only (option c).

- Q: Which failures switch a run to "without AI"? → A: key not set for a provider a step uses; HTTP 401/403; a key the provider reports as invalid or expired; quota or credit exhausted (billing, not rate limit). Timeout, connect error, 5xx, a plain rate-limit 429, invalid model output and refusal stay transient, exactly as today.
- Q: Is a missing key a config error? → A: No. Config loads; `watcher check` reports the analysis check as `warn`. An unknown provider name, a bad model id format or any other analysis key error stays `InvalidConfig`.
- Q: What does the operator see? → A: One line in every digest of an affected run, and one notice per service, per provider, per reason, per UTC day.
- Q: Which reason words? → A: A closed set: `no key`, `authentication failed`, `key expired`, `quota exhausted`. Never the key, a URL, a header or any part of a provider's error text.
- Q: Do skipped groups cache a verdict? → A: No. They are marked reported (dedupe works), but no verdict is stored, so when the key returns a group gets AI only if it is new or grows.
- Q: Exit code? → A: 0 for a run that delivered its digest without AI. No new RunOutcome value; an additive `analysis_skipped` field on the run record and summary.
- Q: Partial runs? → A: Finished steps keep their results. Triage unavailable: no AI at all. Analyze unavailable: no AI findings (groups shown without AI). Only escalate unavailable: keep analyze findings and say `escalation skipped: <reason>`. After one such failure no further request goes to that provider in the same run.
- Q: Non-recoverable 429? → A: Not retried. Only the error-code fields of a non-200 body are read, checked against a closed allowlist; the body is never stored, logged or forwarded. The response-size limit stays.

## Current behaviour (verified on origin/develop b00481f)

- `AnalysisSection` throws `InvalidConfig 'analysis.api_keys.<name> is required for the provider in use'` when a used provider has no key: the package stops at load (exit 2).
- `GuzzlePost::json` turns 401/403 into `AnalyzerFailed('authentication failed')`, and every other non-200 into `AnalyzerFailed('provider unavailable (HTTP <n>)')`.
- `Retry::send` retries any 429 up to 3 attempts, including OpenAI `insufficient_quota`, which does not recover by waiting.
- `Watcher::analyze()` keeps the window snapshot and rethrows; `run()` turns the failure into `failWithNotice('analysis', …)`, exit 1, no digest. After `MAX_ANALYSIS_FAILURES` (2) the window is recorded as failed and its digest is lost.
- `Watcher::run()` already has a display path without a model call: `repeatGroups` in `analyze()` (shown with today's counts and links). It is reused here.

## User Scenarios & Testing

### User Story 1 - Digest still arrives when the key is missing or unusable (Priority: P1)

An operator runs a service whose provider key is unset, revoked, out of credit or out of quota. The scheduled run still posts the digest with every flagged group, with a warning line that says the AI summary is off.

**Acceptance Scenarios**

1. **Given** no key for the provider a step uses, **When** a run has candidates, **Then** the config loads, the digest is delivered, its first line of AI status names `no key`, the run exits 0, and the provider is not called.
2. **Given** the provider answers 401 or 403, **When** a run has candidates, **Then** the digest is delivered without AI, the reason is `authentication failed`, and no further request goes to that provider in the run.
3. **Given** the provider reports quota or credit exhausted (OpenAI billing codes, Gemini 402), **When** a run has candidates, **Then** the reason is `quota exhausted` and the digest is delivered without AI.
4. **Given** a group was shown without AI, **When** the key returns, **Then** that group gets AI only if it is new or grows.

### User Story 2 - One notice per day per reason (Priority: P2)

Operators are told once a day why AI is off, not on every run.

**Acceptance Scenarios**

1. **Given** two runs on the same UTC day with the same provider and reason, **Then** one notice is sent.
2. **Given** the next UTC day, **Then** one more notice is sent for the same reason.

### User Story 3 - Transient failures stay as they are (Priority: P1)

**Acceptance Scenarios**

1. **Given** timeout, connect error, 500, 503, a plain 429, invalid model output or a refusal, **Then** behaviour is unchanged and the existing tests stay green without edits.
2. **Given** an OpenAI `insufficient_quota` 429, **Then** the request is sent exactly once.

### User Story 4 - Config and check (Priority: P2)

1. **Given** a provider in use with no key, **Then** config loads and `watcher check` reports the analysis check as `warn` (`no key for <provider>: AI analysis off`).
2. **Given** an unknown provider name, a bad model id format or another analysis key error, **Then** `InvalidConfig` as today.

### User Story 5 - Partial runs (Priority: P2)

1. **Given** escalation fails with quota after analyze succeeded, **Then** analyze findings are kept and the digest says `escalation skipped: quota exhausted`.

## Requirements

- **FR-001** A key missing for a provider in use MUST NOT stop config loading. (US4.1)
- **FR-002** `watcher check` MUST report that case as `warn`. (US4.1)
- **FR-003** Unknown provider names, bad model ids and other key errors MUST stay `InvalidConfig`. (US4.2)
- **FR-004** The reasons MUST be a closed set of five words (`no key`, `authentication failed`, `key expired`, `quota exhausted`, `provider unavailable`); nothing else from a provider reply may reach output, notice or record. `key expired` is reserved: no verified code produces it (research.md). (privacy)
- **FR-005** An affected run MUST deliver the digest without AI, with exactly one AI-status line in it. (US1)
- **FR-006** An affected run MUST exit 0 and set an additive `analysis_skipped` field on the internal run record. The public event and JSON summary stay unchanged: `run` has `unevaluatedProperties: false` (owner decision 2026-11-15, option c). (behaviour 6)
- **FR-007** Skipped groups MUST be marked reported and MUST NOT get a cached verdict. (US1.4)
- **FR-008** One notice per service, provider, reason and UTC day, through the existing notice-once mechanism. (US2)
- **FR-009** The recoverable list stays as in today's code; only the listed failures switch a run to "without AI". (behaviour 1)
- **FR-010** A non-recoverable 429 is sent once. Body is read only for error-code fields, against a closed allowlist. (behaviour 8)
- **FR-011** A step failing with a non-recoverable reason stops further requests to that provider in the run. (behaviour 7)
- **FR-012** A run with a working key MUST stay byte-identical to today (`SameFindingsTest` and golden fixtures). (behaviour, tests)
- **FR-013** The second transient failure of the same window (`MAX_ANALYSIS_FAILURES`) MUST deliver the digest without AI with the reason `provider unavailable`; the first still fails loudly; refusals are unchanged. (owner decision 2026-11-15)
- **FR-014** A group shown without AI gets AI again only when its count reaches `selection.growth_factor` times the count it was shown with. Only the groups the digest lists (`selection.min_level` and up) are marked. (owner decision 2026-11-15)
- **FR-015** The notice is sent after the digest; a failing notice channel is logged, never a failed run; `notify.notice.enabled: false` sends none. Known-issue references written in the run are kept (only verdicts are dropped).

## Success Criteria

- **SC-001** Each of the four reasons produces a delivered digest, a warning line, exit 0 and zero further provider requests in the run (test per reason).
- **SC-002** No seeded secret, key, URL, header or body text appears in the digest warning, the notice, the run record or the JSON summary (privacy corpus extended).
- **SC-003** Existing tests stay green; the e2e golden stays byte-identical. Tests that encoded behaviour the owner replaced were changed: `WatchConfigTest` and `WatchConfigProvidersTest` (missing key loads), `ProviderRunTest` (401 no longer fails), `ModelDistrustTest` (second failure is delivered).

## Out of scope (v1)

- Detecting an expired key: no documented code verified (see research.md). Kept transient.

## Edge cases

- Key present but provider rejects with 400 for an expired Gemini key: stays transient (not verified as expired).
- Body not JSON, or oversized: no code read; treated as transient.
- Quota code on a non-429 status: matched on the code, still non-recoverable.
