# watcher documentation

| Document | For | Read it when |
|---|---|---|
| [smoke-test.md](smoke-test.md) | whoever runs the first live test | before connecting watcher to real systems (T114) |
| [operations.md](operations.md) | the engineer who installs and runs it | deploying, scheduling, muting noise, troubleshooting |
| [configuration.md](configuration.md) | anyone editing the config | looking up a key, a default or an environment variable |
| [privacy.md](privacy.md) | security / data-protection reviewers | approving what leaves the service |
| [audit-2026-10-23.md](audit-2026-10-23.md) | maintainers, reviewers | before the first live run: P0 fixes, improvement plan, feature list, rollout to the first services |
| [development.md](development.md) | contributors | running tests in parallel, coverage, badges, hooks, CI |
| [roadmap.md](roadmap.md) | maintainers | planning the next spec |
| [brief.md](brief.md) | maintainers | the original brief and decisions |

Examples: [`examples/smoke.config.php`](examples/smoke.config.php) (minimal), [`../config/watcher.example.php`](../config/watcher.example.php)
(every key), [`../config/watcher.php`](../config/watcher.php) (Laravel/Lumen). Specification, plan and tasks live under
[`../specs/001-scheduled-log-watch/`](../specs/001-scheduled-log-watch/).
