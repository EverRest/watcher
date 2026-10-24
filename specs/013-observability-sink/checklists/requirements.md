# Requirements Checklist: Observability Sink

Run against spec.md before planning is accepted. `[x]` = holds now; `[ ]` = open (reason given).

## Content quality

- [x] No `[NEEDS CLARIFICATION]` markers remain.
- [x] Every user story has a priority, an independent test and acceptance scenarios in Given/When/Then form.
- [x] Requirements say what, not which class; class names appear only in plan.md and data-model.md.
- [x] Actors named (Operator, Reader, Host monitoring, Reviewer).
- [x] Out of scope stated: OTLP in v1, writing to Loki/Elasticsearch/Datadog, vendor dashboards other than Grafana,
      fleet-level events, event-type filtering.

## Testability

- [x] Every FR maps to a task and a test in tasks.md / quickstart.md.
- [x] Success criteria are measurable (event counts, zero occurrences of seeded values, series bound 48 per service, exit
      code unchanged, 15 s worst case, 50 ms for the log sink).
- [x] Edge cases listed: disabled, not-due, dry run, `--json` on stdout, fleet, no window, concurrent writers, many groups.
- [ ] SC-011 (50 ms) depends on the CI machine; the test asserts an order of magnitude (< 500 ms) and records the figure.

## Constitution

- [x] I Privacy: allowlist by event type, `include_text` per sink, corpus test, expiry on files and counters.
- [x] II Read-only: sink = configured notification channel (research R1); no write to a monitored system; no amendment.
- [x] III Test-first: schema validation, privacy corpus and docs-drift tests are mandatory tasks.
- [x] VII Failures loud: sink failure visible in summary, host log, next heartbeat, status, metric.
- [x] VIII Simplicity: new dev dependency justified (R11); rejected alternatives recorded.
- [ ] R1 interpretation awaits owner confirmation (open decision O1).

## Consistency with earlier specs

- [x] Flags pattern of spec 012: every sink off by default, `enabled` + `WATCHER_*` variable, strict booleans, key path in
      messages; master `enabled` rules unchanged.
- [x] Destination validation style of spec 011 (`invalidKey`), `check` extended not replaced.
- [x] `RunOutcome`, `Severity`, `FindingKind`, `Level`, `GroupStatus` values reused verbatim.
- [x] JSON summary additions are additive (new keys only); `schema` stays 1.

## Open decisions for the owner

See the report of this pack and research.md: O1 constitution reading, O2 `include_text` default of the `log` sink,
O3 group templates, O4 earlier surfacing of sink failures, O5 DNS pinning, O6 JSON Schema validator dependency,
O7 `none` driver name, O8 Prometheus text format instead of OpenMetrics.
