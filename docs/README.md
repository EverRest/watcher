# watcher documentation

Back to the [project README](../README.md).

| Document | For | Read it when |
|---|---|---|
| [smoke-test.md](smoke-test.md) | whoever runs the first live test | before connecting watcher to real systems |
| [operations.md](operations.md) | the engineer who installs and runs it | deploying, scheduling, alerting, dry runs, heartbeat, fleet, muting noise, troubleshooting |
| [configuration.md](configuration.md) | anyone editing the config | looking up a key, a default, an environment variable or a feature flag |
| [kubernetes.md](kubernetes.md) | whoever adds pod events to a service | RBAC, hourly trigger, native source vs event exporter, limits |
| [privacy.md](privacy.md) | security / data-protection reviewers | approving what leaves the service |
| [development.md](development.md) | contributors | running tests in parallel, coverage, badges, hooks, supported versions, CI |
| [publishing.md](publishing.md) | maintainers | cutting a release and publishing to Packagist |
| [repository-settings.md](repository-settings.md) | maintainers | checking the GitHub repository settings |
| [roadmap.md](roadmap.md) | maintainers | planning the next spec |
| [brief.md](brief.md) | maintainers | the original brief and decisions |
| [audit-2026-10-23.md](audit-2026-10-23.md) | maintainers, reviewers | the pre-live audit: P0 fixes, improvement plan, feature list, first services |
| [review-2026-10-23.md](review-2026-10-23.md) | maintainers, reviewers | the code review findings and batch plan |

`audit-*`, `review-*`, `brief.md`, `roadmap.md`, `publishing.md`, `development.md`, `repository-settings.md` and `badges/` are excluded from the Composer archive (`.gitattributes`).

Examples ([`examples/`](examples/)): [`smoke.config.php`](examples/smoke.config.php) (minimal),
[`orders-api.loki.config.php`](examples/orders-api.loki.config.php) (one service on Loki),
[`watcher-fleet.php`](examples/watcher-fleet.php) (several services). Reference configs:
[`../config/watcher.example.php`](../config/watcher.example.php) (every key), [`../config/watcher.php`](../config/watcher.php) (Laravel/Lumen).

Elsewhere: [`../SECURITY.md`](../SECURITY.md), [`../CHANGELOG.md`](../CHANGELOG.md),
[`../.specify/memory/constitution.md`](../.specify/memory/constitution.md) (principles), the specs
([`../specs/001-scheduled-log-watch/`](../specs/001-scheduled-log-watch/): spec, plan, research, contracts) and the
[spec index in the README](../README.md#specs).
