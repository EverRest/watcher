# Tasks: Analysis Without the Model

Each stage: write the failing test first, see it fail, implement, see it pass, run the gates, commit.

## Stage 1 — Config and check

- [ ] T001 Test: config with a missing key for the provider in use loads (red).
- [ ] T002 Test: `watcher check` reports the analysis check as `warn` with `no key for <provider>: AI analysis off` (red).
- [ ] T003 Test: unknown provider name, bad model id and other key errors stay `InvalidConfig` (red).
- [ ] T004 Implement: `AnalysisSection` drops the throw for a missing key; `AnalyzerFactory` wires a `NoKeyProvider` (reason `no key`).
- [ ] T005 Implement: `check` warn.

## Stage 2 — Classification

- [ ] T006 Test: 401 and 403 → `AnalyzerFailed` with skip `authentication failed` (red).
- [ ] T007 Test: OpenAI `insufficient_quota` 429 → skip `quota exhausted`, sent once (MockHandler counts requests) (red).
- [ ] T008 Test: Gemini 402 `payment_required` → skip `quota exhausted`; Gemini 429 → transient (red).
- [ ] T009 Test: plain 429, 500, 503, timeout, invalid output, refusal → unchanged (existing tests green).
- [ ] T010 Test: body text, key and URL never appear in the exception message or any output (privacy, red).
- [ ] T011 Implement: `AnalyzerFailed` skip property; `GuzzlePost` mapping; `Retry` keep predicate; allowlists.

## Stage 3 — Run degrade

- [ ] T012 Test: each reason → digest delivered, warning line present, exit 0, zero further provider requests, no verdict cached, groups marked reported (red).
- [ ] T013 Test: notice once per service, provider, reason and UTC day (two runs same day → one; next day → one more) (red).
- [ ] T014 Test: run record and JSON summary carry `analysis_skipped`; privacy corpus extended (red).
- [ ] T015 Test: a working-key run stays byte-identical (`SameFindingsTest`, golden fixtures unmodified) (green).
- [ ] T016 Implement: `Watcher::analyze` degrade path, digest line, `noticeOnce`, additive field, schema doc property.

## Stage 4 — Partial runs

- [ ] T017 Test: escalate fails with quota → analyze findings kept, `escalation skipped: quota exhausted` (red).
- [ ] T018 Test: after one skip, no further request to that provider in the run (red).
- [ ] T019 Implement: `ModelRouter` escalate catch and per-run provider latch.

## Stage 5 — Docs

- [ ] T020 `docs/configuration.md`, `docs/operations.md`, README "At a glance" and "Failures and exit codes", CHANGELOG "Unreleased". No VERSION change.
