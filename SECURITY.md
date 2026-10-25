# Security policy

## Supported versions

| Version | Supported |
|---------|-----------|
| latest `0.x` release | yes |
| older `0.x` releases | no: upgrade to the latest |

The project is pre-1.0; fixes land in the next release of the latest minor line.

## Reporting a vulnerability

Do not open a public issue. Use GitHub private vulnerability reporting:
<https://github.com/EverRest/watcher/security/advisories/new>

Include the affected version, what an attacker can do, and a minimal reproduction. Do not include real credentials or production logs.

Response targets (best effort, one maintainer): acknowledgement within 5 working days, an assessment within 14 days, a fix or mitigation for confirmed high-severity issues within 30 days. Reporters are credited in the advisory unless they ask otherwise.

## Scope notes

- The configuration file is PHP code and is executed by design: never point `--config` at an untrusted or world-writable path.
- Log content is untrusted input to the model; redaction runs before anything leaves the process (see `docs/privacy.md`).
- Outbound URLs (Slack webhooks, Loki, observe sinks, repository raw URLs) are validated for scheme and SSRF risk where the package initiates the fetch; see `src/Config/SecureUrl.php` and architecture tests under `tests/Unit/ArchitectureTest.php`.
- Secrets belong in the environment or a host secret store, not in committed config; `WatchConfig` redacts credentials in debug output.

## Security review (2026-10)

A dedicated hardening pass (`watcher-sec-hardening` worktree) was merged into `develop` ahead of this policy update. Remaining tracked items live in `docs/review-fixes-2026-10-25.md` under **SEC**; open checklist:

- [ ] Final pass on SSRF rules for optional observe/metrics URLs (internal-host classifier vs operator `allow_insecure`).
- [ ] Repository read-only token scope documentation in `docs/configuration.md` (code-aware analysis, spec 007).
- [ ] Third-party dependency audit cadence in CI (Composer audit gate).
- [ ] Live penetration-style smoke of `check` / `run` with malicious log fixtures (extends privacy corpus).

Report issues via the advisory link above; do not file public issues for unfixed SEC checklist rows.
