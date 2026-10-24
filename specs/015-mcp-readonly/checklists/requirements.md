# Requirements Checklist: Read-only MCP Server

Run against spec.md before planning is accepted. `[x]` = holds now; `[ ]` = open (reason given).

## Content quality

- [x] No `[NEEDS CLARIFICATION]` markers remain.
- [x] Every user story has a priority, an independent test and acceptance scenarios in Given/When/Then form.
- [x] Requirements say what, not which class; class names appear only in plan.md and data-model.md.
- [x] Actors named (Operator, Assistant user, Assistant, Reviewer).
- [x] Out of scope stated: remote transport, resources/prompts/sampling, any write tool, real runs, muting, fleet files,
      structured output schemas, injection detection by classifier.

## Constitution

- [x] I: allowlist per tool, scan-or-drop, no template/line/value/frame/path; corpus extension (SC-003).
- [x] II: stricter than required (no writes at all); one write added to the *run*, not the server.
- [x] III: tasks test-first; mandatory suites named in plan.
- [x] V: default tools cost nothing; dry run gated (confirm, limit, estimate, session counter).
- [x] VI: one setting, validated at start, names the key.
- [x] VIII: no dependency; one seam justified.

## Open (owner)

- [ ] O1 last-report record (A) vs 013 file sink (B) - research R3, proposal A.
- [ ] O2 `watcher_dry_run` off by default - proposal yes.
- [ ] O3 `estimate_only` exempt from confirmation - proposal yes.
- [ ] O4 masked template never returned - proposal never in v1.
- [ ] O5 MCP revision list - fixed at T002.
- [ ] Spec 006 field table for `watcher_hygiene_report` - contract placeholder until 006 is planned.
