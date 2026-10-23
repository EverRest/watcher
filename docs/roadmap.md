# watcher — roadmap

Each item is its own spec (specify → clarify → plan → tasks → implement). Order is by value / cost.
Decisions recorded here were made by the owner on 2026-10-23.

| Spec | Scope | Status | Notes |
|---|---|---|---|
| **001 scheduled-log-watch** | Logs (Loki, Datadog) → reduce → redact → select → tiered AI → Slack digest; dedupe, mute, budgets, notices; dependency context, deploy version, service context, log links, code links | Spec, plan, tasks ready | MVP = US1 + US2 |
| **002 code-aware analysis** | Model reads code fragments for the top frames at the deployed commit: read-only repo token, only referenced functions (≈ ±40 lines), secrets redacted with the same code as PII, never `.env`/config files, own token budget | Approved by owner (sending code fragments to Anthropic is allowed) | Builds on 001 `CodeLinkBuilder` + `DeployInfo` |
| **003 kubernetes events** | CrashLoopBackOff, OOMKilled, readiness/liveness failures, restarts, HPA errors, image pull errors as a second source; read-only API access | Not started | Closes the pod-level half of `orders-api/docs/context/logs/dev-notes` (WT-01, 03–06, 08, 15); needed for SC-001 |
| **004 metrics and Sentry** | Prometheus error rate / p95 latency deltas; Sentry issues as an already-grouped source | Not started | Same `LogSource`-style contract per source type |
| **005 workflow** | Jira ticket draft text in the digest (no creation), linking findings to existing tickets, weekly trend digest, mute via Slack reactions (needs a bot token) | Not started | Read-only toward Jira |
| **006 API context** | OpenAPI/Swagger operation summaries for endpoints that appear in findings | Not started | Low value per token; only operations referenced by candidates |

## Out of scope until a constitution amendment

Automatic remediation, ticket creation, any write to a monitored system (Principle II).
