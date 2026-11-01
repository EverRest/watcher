# watcher — brief (output of brainstorming, input to spec-driven development)

Status: brainstorm approved by the owner (the owner) section by section on 2026-10-23. Next step: run
`/speckit-specify` (then clarify, plan, tasks) from this brief. Nothing is implemented yet.

## Goal

> Superseded: Anthropic was removed in 0.7.0; the package uses OpenAI and Gemini (spec 016).

A general-purpose PHP package that, on a schedule, pulls a window of logs for one service from a log
backend, finds errors and suspicious behaviour, and reports to Slack. An Anthropic model does the
judging. First consumer is `orders-api`, but the package must not be orders-api-specific: every moving part is
behind an interface and chosen by config.

- Package: `everrest/watcher`, namespace `Watcher\`, repo `EverRest/watcher` (owner's org; not the
  company's internal GitLab, not yet). PHP ^8.1 (orders-api is PHP 8.2 / Lumen 10 / PHPUnit 10).
- Design priority from the owner: **as abstract and flexible as possible**; **token efficiency is a
  first-class concern**; **models are selectable per task**.

## Context gathered from orders-api (verified unless marked)

- Lumen 10 / PHP 8.2 microservice, runs on Kubernetes. Private composer packages come from GitLab
  `vcs` repositories.
- Logs: Monolog to stdout, **plain text line**, not JSON:
  `[datetime] channel.LEVEL [APP]: message {context} {extra}` (`App\Formatters\Logs\StdFormatter`).
  Other services differ (one internal service uses a JSON logger) → source adapters must normalise.
- `logging.php` already has a `slack` channel (webhook, level `critical` only).
- Cluster notes mention splunk-otel and datadog; reporting services export OpenTelemetry (UNVERIFIED
  which backend is authoritative). **Backend differs per environment** (Grafana/Loki in some,
  Datadog in others) → source is a per-environment config choice. Grafana/Datadog/Sentry were not
  reachable from the brainstorming session (401 / unauthenticated), so no API was exercised.
- A hand-made precedent exists: `docs/context/logs/backlog.md` is a manual 15-row backlog
  (id, severity, workload, symptom, evidence, suspected cause, next step, status). The package output
  should resemble one such row.
- orders-api data contains participant personal data → GDPR applies to anything leaving the perimeter.
- The owner's `healer` project (TS) is adjacent (issue → diagnosis → fix). watcher stays small and
  read-only; no overlap intended.

## Decisions

1. **Embedded per service** (each service runs its own scheduled job), not a central runner.
   Consequence: Anthropic key and read-only log token live in each service's env.
2. **Host provides the scheduler** (k8s CronJob or framework scheduler); package exposes a runnable
   entry point plus an Lumen/Laravel adapter command (`watcher:run`). **Run hour and lookback window
   are configurable** (default lookback 24h).
3. **StateStore is pluggable**: Redis, file, DB. One contract test-suite runs against all.
4. **Log source is pluggable**: v1 ships the minimum adapters needed (Loki first guess, Datadog
   second); Sentry/Splunk later as adapters. Source and credentials come from env-specific config.
5. **Tags / watch rules**: config lets the user declare tags (stream selectors and field/message
   matchers) that scope which logs are searched and may raise priority of matching groups.
   Exact semantics: to clarify in spec.
6. **Read-only**: no actions on services, no tickets in v1; output is a Slack message.
7. **No raw logs stored anywhere.** Only fingerprints, counters and findings, with TTL.

## Pipeline

```
LogSource → Reducer → Redactor → Selector → Triage → Analyze → (Escalate) → Dedupe → Notifier
(per env)   fingerprint  allowlist  anomaly    cheap     mid       strong      StateStore  Slack
            + counters   + normalise rules(code) model    model     model, once
            + vs yesterday
```

Interfaces: `LogSource`, `StateStore`, `Notifier`, `Analyzer` (+ `Redactor`, `ModelRouter`).
One implementation each in v1 where YAGNI allows; sources and state stores have several.

### Privacy (GDPR) — approach B, fail closed

Only allowlisted fields reach the model: level, **normalised message template** (numbers, ids,
emails, tokens replaced by `<N>` `<ID>` `<EMAIL>` ...), exception class, 3-5 top stack frames,
counts, timestamps. Raw `context`, request bodies and raw sample lines are never sent. A regex
redactor runs as a second layer on the template. If redaction fails or a field is not allowlisted,
the field is dropped. The model never performs redaction.

### Selector (deterministic, 0 tokens)

Candidates for the model: new fingerprint vs yesterday; count grew ≥ K× vs yesterday (K configurable);
level ≥ error; tag/watch-rule matches; plus top-N by volume. Everything else is collapsed to one line
("N more groups, M events"). If nothing qualifies → **no LLM call at all**.

### Model routing and token efficiency

| Step | Who | Notes |
|---|---|---|
| 0 reduce / redact / select / dedupe | code | 0 tokens; per-fingerprint verdict cached in StateStore, next day only the counter delta |
| 1 triage: noise / known / interesting | cheap model (Haiku-class) | one batched call, minimal output (`fingerprint → verdict` + ≤1 line) |
| 2 deep analysis | mid model (Sonnet-class), only `interesting`, top-M | structured output |
| 3 escalation | strong model (Opus-class) | severity ≥ error AND confidence < threshold; once per finding, capped per run |

- Model map is config: `models: {triage, analyze, escalate}`, tunable per service/env. No model ids in code.
- Compact input: each message template sent once, groups reference it by index; unindented JSON, short
  keys, ≤5 frames. Compact output: `maxLength` in the schema, per-step `max_tokens`.
- Prompt caching on the stable prefix (system prompt + schema) if it exceeds the model's minimum
  cacheable size (verify during implementation).
> Superseded by constitution 2.0.0, Principle V: no budgets, cost caps or token/cost reporting.

- Budget: estimate input size before calling; if over, drop lowest-priority groups and say
  "truncated: N groups" in Slack. Hard daily cost cap per service; when exceeded → triage only plus
  a "budget exhausted" notice, never silence.
> Superseded by constitution 2.0.0, Principle V: no budgets, cost caps or token/cost reporting.

- Observability: one footer line per digest: tokens in/out/cached, model per step, approx cost.
- Batch API: not in v1; `Analyzer` interface leaves room.

### Analyzer output

Structured JSON output via `output_config.format` (not a forced tool call — see
specs/001-scheduled-log-watch/research.md R3). `findings[]`: `fingerprints[]`, `kind` (bug|regression|anomaly|noise),
`severity` (info|warn|error|critical), `title`, `evidence`, `suspected_cause`, `suggested_action`,
`confidence` 0-1. Prompt forbids inventing data absent from the input and requires hypotheses to be
labelled as such. PHP validates against the schema; invalid → one retry → `AnalyzerFailed` →
short Slack message "analysis failed: reason" (never silent).

### Slack digest

One digest per service per run, only if something is above `min_severity`. Grouped by severity;
title, one-sentence evidence, count, trend vs yesterday. Low-confidence or `noise` collapsed into
"N minor". Shared channel, identical format across services. Dedupe key = fingerprint + day;
already-reported and not grown → omitted.

## Config surface (per service, env-driven)

> Superseded by constitution 2.0.0, Principle V: no budgets, cost caps or token/cost reporting.

`source` (driver, url, auth, stream selector), `tags`/watch rules, `lookback`, `run hour`/schedule
hint, `min_severity`, `selector` (K, N, M), `state` (driver), `slack` (webhook, channel), `models`
(per step), `anthropic` (key, max_tokens per step, daily budget), `redaction` (allowlist, extra
patterns).

## Testing (PHPUnit 10, no network)

1. Reducer/fingerprint golden tests (`user=123` and `user=456` → same fingerprint).
2. **Privacy test (primary):** logs seeded with known emails/names/phones/tokens; assert none appear
   in the payload passed to `Analyzer`; non-allowlisted fields absent; fail-closed behaviour.
3. Selector table tests (yesterday/today → expected groups); zero candidates → zero LLM calls.
4. Analyzer with Guzzle `MockHandler`: valid, invalid → retry, failure path.
5. Routing table (what goes to which model); escalation fires at most once.
6. Token-budget fixture: N groups → payload under cap; truncation notice.
7. StateStore contract tests across Redis/File/DB.
8. One end-to-end run with fake `LogSource` and `Notifier`.

## Non-goals (v1)

Auto-tickets, actions on services, storing raw logs, own UI, central multi-service runner, Batch API.

## Open questions for /speckit-clarify

- Exact meaning of "tags": stream labels only, or also message/field matchers, or both; do tags change
  priority or only scope?
- "Run hour": does the package own a schedule definition or only document a cron expression for the host?
- First two source adapters (Loki + Datadog assumed) and their auth model.
- Vendor/package name final check (`everrest/watcher`).
- Fingerprint algorithm and normalisation rules per log format (text line vs JSON).
- Retention/TTL for findings and cached verdicts (GDPR).
- Slack: webhook vs bot token; threading; per-severity mentions.
