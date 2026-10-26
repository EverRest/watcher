# Requirements Checklist: Read-only MCP Server

Run against spec.md before planning is accepted. `[x]` = holds now; `[ ]` = open (reason given).

## Content quality

- [x] No `[NEEDS CLARIFICATION]` markers remain.
- [x] Every user story has a priority, an independent test and acceptance scenarios in Given/When/Then form.
- [x] Requirements say what, not which class; class names appear only in plan.md and data-model.md.
- [x] Actors named (Operator, Assistant user, Assistant, Reviewer).
- [x] Out of scope stated: remote transport, sampling, resource subscriptions, any write tool, real runs, muting, fleet files,
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

## Amendment 2026-10-25 (onboarding, setup copilot, Q&A), re-validated

- [x] Existing IDs keep their meaning; changed sentences carry an "(amended 2026-10-25)" marker (US4 AC1/AC3, FR-003, FR-005, FR-021, FR-040).
- [x] New user stories US7-US12 have a priority, an independent test and Given/When/Then scenarios: plan without a secret (US7 AC7), pasted
      token refused (US8), unknown config key explained or refused (US11 AC2), every outcome code explained (US11 AC3 + SC-013), resource path
      traversal refused (US11 AC7 + SC-014).
- [x] Requirements FR-060..FR-095 say what, not which class (classes in plan.md and data-model.md only); SC-010..SC-018 are measurable.
- [x] Edge cases added: stripped install, huge config file, symlinked config path, plain-text secrets in a config, no configuration yet.
- [x] Constitution: I (secret rule structural, corpus extended T045/T043), II (no write/model/network except `connect`), III (red-first tasks, gates),
      IV (no model in the server), VI (one new setting, validated; no model id or price in the package), VII (unknown = `found:false`), VIII (no
      dependency, no embeddings, lexical index, `KeyCatalog`/`OutcomeRegistry` replace scattered strings).
- [x] Owner decisions O6-O12 recorded; each default is also listed as an assumption (A1-A15).
- [x] Tool counts consistent: 5 inspect + `watcher_dry_run` (optional) + 5 onboarding + 3 docs = 13 by default, 14 at most, 8 in `--setup` mode.
- [x] Clarification markers: 0 open (limit 3). Every open point had a default.
- [x] PHP floor corrected to ^8.2 in plan.md.
- [ ] Verify at implementation (T002): resources/prompts result shapes in the published revisions (R11).
- [ ] Verify at implementation (T051/T052): the `KeyCatalog` and registry extractions change no behaviour (plan risk 7, existing tests unchanged).

## Open (not owner decisions)

- [ ] Spec 006 field table for `watcher_hygiene_report` - contract placeholder until 006 is planned.
