# Contract: command line and configuration

## watcher mcp

```text
watcher mcp --config=<file>
```

Adapter: the Laravel/Lumen adapter exposes the same server as `watcher:mcp`.

| Aspect | Rule |
|---|---|
| Options | `--config` only. `--json`, `--now`, `--dry-run`, `--post-test` and any other option are usage errors (exit 2). |
| stdin | UTF-8 JSON-RPC 2.0, one message per line, lines up to 64 KB. |
| stdout | Protocol messages only, one per line, flushed after each. Nothing else, ever (no banner, no warning). |
| stderr | Diagnostics only when something is wrong: `watcher [error] <class>: <masked message>` (no startup banner). Never a secret, URL, log text or field value. |
| Config | One service. A fleet file (`fleet` key) is a usage error naming the key. |
| Start | Config is validated and the store opened read-only before the first read of stdin. A failure prints one stderr line naming the setting and exits 2 with empty stdout. |
| Exit | `0` stdin closed; `1` internal fatal error (class name on stderr); `2` usage error or invalid config. |
| Signals | SIGTERM/SIGINT end the loop after the current message; a dry run in progress is not interrupted (the process is killed by the client, which is safe: nothing is written). |

## Configuration

Added to `WatchConfig` (T018); documented in `docs/configuration.md` (T030).

| Key | Default | Env | Meaning |
|---|---|---|---|
| `mcp.allow_dry_run` | `false` | `WATCHER_MCP_ALLOW_DRY_RUN` | When true, `watcher_dry_run` is listed and callable. Model calls it makes are real and billed. |

Strict boolean (`"true"`/`"false"`/`1`/`0` from the environment as elsewhere). `mcp.enabled` does not exist: running the
command is the opt-in. Any other key under `mcp` is rejected naming `mcp.<key>`. The `mcp` section holds no secret.

## Example assistant configuration (quickstart has the full walkthrough)

```json
{ "mcpServers": { "watcher-orders-api": { "command": "php", "args": ["vendor/bin/watcher", "mcp", "--config=/srv/orders/watcher.php"] } } }
```
