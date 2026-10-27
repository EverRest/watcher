# Contract: configuration migration (0.4.x to 0.5.0)

Load-time contract. Each removed key fails `WatchConfig::fromArray` before any external call. The message names the key path, says it was removed in 0.5.0, and says there is no replacement. Nothing is converted.

| Removed key or variable | Exact message |
|---|---|
| `analysis.daily_budget_usd` | `analysis.daily_budget_usd was removed in 0.5.0; there is no replacement. Delete this key.` |
| `analysis.run_budget_usd` | `analysis.run_budget_usd was removed in 0.5.0; there is no replacement. Delete this key.` |
| `analysis.prices` | `analysis.prices was removed in 0.5.0; there is no replacement. Delete this key.` |
| `analysis.daily_token_limit` | `analysis.daily_token_limit was removed in 0.5.0; there is no replacement. Delete this key.` |
| `analysis.budget_alert_percents` | `analysis.budget_alert_percents was removed in 0.5.0; there is no replacement. Delete this key.` |
| `analysis.max_runs_per_day` | `analysis.max_runs_per_day was removed in 0.5.0; there is no replacement. Delete this key.` |
| `budget` (whole section) | `budget was removed in 0.5.0; there is no replacement. Delete this section.` |

`WATCHER_PRICE_*` environment variables are not read by the package any more; they are documented as removed and produce no message.

Rules:

1. A removed key present together with anything else still fails; the operator deletes it.
2. The first removed key found is named; `watcher check` lists all of them.
3. The message names the key path, never a value (constitution 2.0.0, Principles VI and VII).
