# watcher

PHP package: scheduled log analysis with Anthropic, reports to Slack. Spec 001 is implemented (113 of 114 tasks); T114, the first live smoke run, needs real credentials (`docs/smoke-test.md`).

@docs/brief.md

- Roadmap (specs 001–006): `docs/roadmap.md`. Active feature: `specs/001-scheduled-log-watch/`.

- Workflow: spec-driven (speckit: specify → clarify → plan → tasks → implement), TDD.
- Commits: after a finished stage whose three gates (`composer test`, `composer stan`, `composer style`) all exit 0, commit without asking (owner's standing permission, 2026-10-23); read the exit codes, never the tail of the output; no `Co-Authored-By` trailer. Never push, amend or force-push unless asked.
- User documentation: `docs/README.md` (smoke test, operations, configuration, privacy).
