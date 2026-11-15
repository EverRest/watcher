# Implementation Plan: Analysis Without the Model

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md)

## Constitution Check

- I Privacy: only the four closed reason words and the additive field leave the run; error body is read for codes only and dropped. Privacy corpus extended (T-privacy). PASS.
- II Read-only: no new writes beyond the package's own state and the existing notice. PASS.
- III Test-first: every stage starts with failing tests. PASS (enforced in tasks).
- IV Code decides: no change to what the model sees; skipped groups are still chosen by code. PASS.
- V Lean: no new model call; a skipped run makes zero requests to the failing provider. PASS.
- VI Config: no new hard-coded model or threshold; the allowlists are code constants (closed sets by spec). PASS.
- VII Failures loud: every affected run has a digest line and a notice. The catch-and-continue fallback is defined here and visible in the digest. PASS.
- VIII Simplicity: no new contract; reuse `repeatGroups`, `noticeOnce`, `Retry`. One new exception property. PASS.

## Stages (one commit each, after the three gates and `validate --strict` exit 0)

1. **Config and check** (`AnalysisSection`, `AnalyzerFactory`, `check` command): missing key loads; the step's provider becomes a `NoKeyProvider` that fails with reason `no key`; `check` warns.
2. **Classification** (`AnalyzerFailed` gets a `skip` reason; `GuzzlePost` maps 401/403 and the quota allowlist; `Retry` gets a "do not retry" predicate; the body is read for codes only).
3. **Run degrade** (`Watcher::analyze` on a skip reason: shows candidates through the `repeatGroups` display path, stores no verdict, adds the digest line, one notice per day per reason, additive `analysis_skipped` on the run record and summary; exit 0).
4. **Partial runs** (`ModelRouter`: escalate-only failure keeps analyze findings with `escalation skipped: <reason>`; a per-run latch stops further requests to the failing provider).
5. **Docs**: `docs/configuration.md`, `docs/operations.md`, README "At a glance" and "Failures and exit codes", CHANGELOG "Unreleased".

## Design notes

- `AnalyzerFailed` keeps `refused`; a new optional `?string $skip` carries the closed reason word. Null means today's behaviour.
- The quota check reads `error.code`, `error.type`, `error.status` from a non-200 body capped by the existing response sink; no body text is kept.
- A 429 whose body matches the quota allowlist is not retried: `Retry::send` gets an optional `keep` callable, default null, so every existing caller is unchanged.
- Digest line text: `⚠️ AI analysis skipped: <provider> <reason>; groups shown without AI summary`.
- Notice key is built with the existing `Keys` join helpers and sent through `noticeOnce`, keyed by service, provider, reason and UTC date.

## Open items carried into the final report

- Last-attempt delivery after MAX_ANALYSIS_FAILURES (owner question, not implemented).
- Gemini `generateContent` error body form (see research.md).
- Expired-key detection UNVERIFIED for both providers.
