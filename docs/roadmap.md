# watcher — roadmap

Each item is its own spec (specify -> clarify -> plan -> tasks -> implement). Specs 002-006 were written from the audit's
feature proposals (`docs/audit-2026-10-23.md`); 007-010 are earlier roadmap items, not yet specified.
Decisions recorded here were made by the owner on 2026-10-23.

| Spec | Scope | Status | Notes |
|---|---|---|---|
| **001 scheduled-log-watch** | Logs (Loki, Datadog) -> reduce -> redact -> select -> tiered AI -> Slack digest; dedupe, mute, budgets, notices; dependency context, deploy version, service context, log links, code links | Implemented (113 of 114 tasks); T114 needs a live run. Audit P0 fixes applied. | MVP = US1 + US2 |
| **002 operability-tools** | Heartbeat, `check` doctor, `--dry-run`, `--json` summary, file-store purge | Implemented | Makes first runs safe; replaces most of the manual smoke steps |
| **003 fleet-and-correlation** | One process for several services (shared state and budget), cross-service correlation | Implemented | Needed for the BFFs, which have no CLI or scheduler |
| **004 baselines-and-templates** | Rolling and same-weekday baselines, last-good-snapshot pointer, DST-safe windows, similarity-based grouping, optional backend patterns | Implemented (backend patterns deferred) | Fixes re-baselining after a missed day or DST change |
| **005 incident-workflow** | v1 without inbound endpoint or tracker writes: known-issue memory (config fingerprint -> ticket key), text ticket drafts, routing by severity, weekly report from 013 events | Spec pack written (spec, plan, research, data model, contracts, quickstart, tasks) | Buttons/threads deferred: need a Principle II amendment (spec D1). Post-release and on-demand runs removed from 005 (D6) |
| **006 log-hygiene** | Privacy-hygiene, log-shape, rule-proposal and cost reports from local measurement | Spec written | Raw values never leave the process |
| **007 code-aware analysis** | Model reads code fragments for the top frames at the deployed commit (read-only repo token, only referenced functions, secrets redacted, own token budget) | Spec written; amendment approved with conditions, constitution edited when planning starts (after T114) | Builds on `CodeLinkBuilder` + `DeployInfo` |
| **008 kubernetes events** | CrashLoopBackOff, OOMKilled, probe failures, restarts, HPA errors as a second source | Spec written | Closes the pod-level half of the manual backlog; needed for SC-001 |
| **009 metrics and Sentry** | Prometheus error rate / p95 deltas; Sentry issues as an already-grouped source | Spec written | |
| **010 API context** | OpenAPI/Swagger summaries for endpoints in findings | Implemented (Unreleased), off by default | Low value per token |
| **011 more-notifiers** | Telegram and Discord next to Slack; per-kind destinations, message splitting, 429 handling, fleet override, `check` per driver | Implemented | Slack unchanged; MINOR release |
| **012 feature-flags** | Master `enabled` switch (default off), `environment`, one boolean flag per feature, `WATCHER_*` variables | Implemented | Per-environment switches; redaction has no flag by design |
| **013 observability-sink** | Versioned vendor-neutral event stream (`watcher.event.v1`) to `log`, `file`, `http` and `prometheus` sinks; driver `none` for dashboard-only mode; Grafana dashboards, alert rules and Kibana/Datadog mapping | Implemented | Chat can be switched off; all sinks off by default; OTLP later |
| **014 schedule-periods** | Several runs per day (`schedule.every` PT1H..PT12H or a `run_at` list), contiguous windows, high-water mark against double reporting, catch-up after outages, per-day run cap and per-run budget share, same-slot baselines, `ingestion_delay` | Spec written | Flags per spec 012 pattern: new keys default to today's behaviour; end-of-day rollup deferred |

## Out of scope until a constitution amendment

Automatic remediation, ticket creation, any write to a monitored system (Principle II).
