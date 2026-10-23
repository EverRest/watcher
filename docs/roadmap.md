# watcher — roadmap

Each item is its own spec (specify -> clarify -> plan -> tasks -> implement). Specs 002-006 were written from the audit's
feature proposals (`docs/audit-2026-10-23.md`); 007-010 are earlier roadmap items, not yet specified.
Decisions recorded here were made by the owner on 2026-10-23.

| Spec | Scope | Status | Notes |
|---|---|---|---|
| **001 scheduled-log-watch** | Logs (Loki, Datadog) -> reduce -> redact -> select -> tiered AI -> Slack digest; dedupe, mute, budgets, notices; dependency context, deploy version, service context, log links, code links | Implemented (113 of 114 tasks); T114 needs a live run. Audit P0 fixes applied. | MVP = US1 + US2 |
| **002 operability-tools** | Heartbeat, `check` doctor, `--dry-run`, `--json` summary, file-store purge | Implemented | Makes first runs safe; replaces most of the manual smoke steps |
| **003 fleet-and-correlation** | One process for several services (shared state and budget), cross-service correlation | Spec written | Needed for the BFFs, which have no CLI or scheduler |
| **004 baselines-and-templates** | Rolling and same-weekday baselines, last-good-snapshot pointer, DST-safe windows, similarity-based grouping, optional backend patterns | Spec written | Fixes re-baselining after a missed day or DST change |
| **005 incident-workflow** | Known-issue links, ticket drafts, Slack threads and buttons, severity routing, more channels, post-release and on-demand runs, weekly report | Spec written | No writes to trackers (Principle II) |
| **006 log-hygiene** | Privacy-hygiene, log-shape, rule-proposal and cost reports from local measurement | Spec written | Raw values never leave the process |
| **007 code-aware analysis** | Model reads code fragments for the top frames at the deployed commit (read-only repo token, only referenced functions, secrets redacted, own token budget) | Approved by owner; not specified | Builds on `CodeLinkBuilder` + `DeployInfo` |
| **008 kubernetes events** | CrashLoopBackOff, OOMKilled, probe failures, restarts, HPA errors as a second source | Not started | Closes the pod-level half of the manual backlog; needed for SC-001 |
| **009 metrics and Sentry** | Prometheus error rate / p95 deltas; Sentry issues as an already-grouped source | Not started | |
| **010 API context** | OpenAPI/Swagger summaries for endpoints in findings | Not started | Low value per token |

## Out of scope until a constitution amendment

Automatic remediation, ticket creation, any write to a monitored system (Principle II).
