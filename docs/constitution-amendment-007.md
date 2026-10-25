# Constitution amendment for spec 007 (code-aware analysis)

**Status**: Approved by the owner with conditions. Code stays behind `code_aware` (default **off**) until T114 and constitution file update.

See `specs/007-code-aware-analysis/spec.md` for full Principle I v1.1.0 wording.

## MVP (feat/007-code-aware)

Read-only repo fetch and redacted line-window fragments for top frames at `deploy.commit`, added to analysis payload only when `code_aware.enabled` is true. Fragments never go to notifiers or state.
