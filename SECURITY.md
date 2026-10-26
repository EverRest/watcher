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
- Outbound URLs (metrics, Sentry, OpenAPI spec, observe HTTP target) come from the operator's config. The internal-address check reads
  the host text and does not resolve names, so a configured name that resolves to an internal address is not refused. Accepted
  because only the operator sets these URLs. Revisit (resolve once and connect to the checked address) if any URL can come from
  untrusted input. See `docs/operations.md`, Proxies and the internal-address check.
