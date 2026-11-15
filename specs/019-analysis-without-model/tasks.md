# Tasks: Analysis Without the Model

Each stage: write the failing test first, see it fail, implement, see it pass, run the gates, commit.

## Stage 1 — Config and check

- [x] T001 Test: config with a missing key for the provider in use loads (red).
- [x] T002 Test: `watcher check` reports the analysis check as `warn` with `no key for <provider>: AI analysis off` (red).
- [x] T003 Test: unknown provider name, bad model id and other key errors stay `InvalidConfig` (red).
- [x] T004 Implement: `AnalysisSection` drops the throw for a missing key; `AnalyzerFactory` wires a `NoKeyProvider` (reason `no key`).
- [x] T005 Implement: `check` warn.

## Stage 2 — Classification

- [x] T006 Test: 401 and 403 → `AnalyzerFailed` with skip `authentication failed` (red).
- [x] T007 Test: OpenAI `insufficient_quota` 429 → skip `quota exhausted`, sent once (MockHandler counts requests) (red).
- [x] T008 Test: Gemini 402 `payment_required` → skip `quota exhausted`; Gemini 429 → transient (red).
- [x] T009 Test: plain 429, 500, 503, timeout, invalid output, refusal → unchanged (existing tests green).
- [x] T010 Test: body text, key and URL never appear in the exception message or any output (privacy, red).
- [x] T011 Implement: `AnalyzerFailed` skip property; `GuzzlePost` mapping; `Retry` keep predicate; allowlists.

## Stage 3 — Run degrade

- [x] T012 Test: each reason → digest delivered, warning line present, exit 0, zero further provider requests, no verdict cached, groups marked reported (red).
- [x] T013 Test: notice once per service, provider, reason and UTC day (two runs same day → one; next day → one more) (red).
- [x] T014 Test: run record carries `analysis_skipped` (the JSON summary stays unchanged, owner decision c); privacy checked in the e2e tests.
- [x] T015 Test: a working-key run stays byte-identical (`SameFindingsTest`, golden fixtures unmodified) (green).
- [x] T016 Implement: `Watcher::analyze` degrade path, digest line, `noticeOnce`, additive field on the internal run record (no schema change, owner decision c).

## Stage 4 — Partial runs

- [x] T017 Test: escalate fails with quota → analyze findings kept, `escalation skipped: quota exhausted` (red).
- [x] T018 Test: after one skip, no further request to that provider in the run (red).
- [x] T019 Implement: `ModelRouter` escalate catch and per-run provider latch.

## Stage 5 — Docs

- [x] T020 `docs/configuration.md`, `docs/operations.md`, README "At a glance" and "Failures and exit codes", CHANGELOG "Unreleased". No VERSION change.

## Review follow-ups (2026-11-15)

- [x] T021 Notice after the digest, guarded by `notify.notice.enabled`, a send failure logged (`AnalysisWithoutModelEdgesTest`).
- [x] T022 Verdicts in their own buffer: a skip drops verdicts only; known-issue references are kept.
- [x] T023 Only groups the digest lists are marked `withoutAi`.
- [x] T024 Run-level latch: catch-up windows send no further request after a skip; a refused escalation stops escalation for the run.
- [x] T025 Provider name on every failure (invalid output, refusal); fallback wording `the model`.
- [x] T026 Doctor: a missing key no longer hides the keyed providers' checks.
- [x] T027 Escalation skip names the provider and sends the daily notice.
