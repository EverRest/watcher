# SEC review follow-ups (in progress)

Companion to `SECURITY.md`. The `watcher-sec-hardening` worktree was an integration branch; `develop` already contains the merged hardening (SecureUrl, LineParser caps, privacy corpus tests, and related review batches).

## Checklist

| Item | Status | Notes |
|------|--------|-------|
| SSRF / URL validation for observe and metrics sinks | open | Extend `SecureUrl::internalHost()` coverage docs; confirm operator guidance for `allow_insecure`. |
| Code repository token least privilege | open | Spec 007; document GitLab/GitHub read-only scopes when `code_aware` ships. |
| Composer audit in CI | open | `composer audit` gate on default branch. |
| Hostile log → model boundary | partial | Privacy corpus + `OutputValidator` hardening; add targeted fixtures for prompt-injection strings in templates. |
| State store path permissions | done | File state documented in `docs/operations.md`; PDO/Redis credentials via env only. |

No code changes are required on this branch until the next SEC work session; tracking lives here and in `SECURITY.md`.
