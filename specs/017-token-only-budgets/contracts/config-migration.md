# Contract: configuration migration (0.4.x to 0.5.0)

Load-time contract. Each removed key fails `WatchConfig::fromArray` before any external call, with the message below. Nothing is silently ignored.

| Removed key or variable | Message (exact text) |
|---|---|
| `analysis.daily_budget_usd` | `analysis.daily_budget_usd was removed in 0.5.0: set analysis.daily_token_limit (tokens per day)` |
| `analysis.run_budget_usd` | `analysis.run_budget_usd was removed in 0.5.0: set analysis.run_token_limit (tokens per run)` |
| `analysis.prices` | `analysis.prices was removed in 0.5.0: costs are not computed; delete this key` |
| `budget.daily_budget_usd` (fleet) | `budget.daily_budget_usd was removed in 0.5.0: set budget.daily_token_limit` |
| `WATCHER_PRICE_*` (env, MCP setup) | `WATCHER_PRICE_* is no longer read in 0.5.0; delete it from the environment` |

Rules:

1. A removed key present together with its replacement still fails (the operator must delete the old one).
2. Limits: `analysis.daily_token_limit`, `analysis.run_token_limit`, `budget.daily_token_limit` accept null or a positive integer.
3. The message names the key path, never a value (Principle VI and VII).
