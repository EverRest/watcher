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

## Owner decisions (2026-10-24), all closed

- [x] O1 `lastrep:{key}` allowlisted record, independent of spec 013, Watcher.php hunk SEQ after Lanes A/C/B/G.
- [x] O2 `watcher_dry_run` off unless `mcp.allow_dry_run: true`; `confirm_cost` per call; daily-cap check.
- [x] O3 `estimate_only` needs no confirmation.
- [x] O4 masked template never returned.
- [x] O5 protocol revision list fixed in T002.

## Open (not owner decisions)

- [ ] Spec 006 field table for `watcher_hygiene_report` - contract placeholder until 006 is planned.
