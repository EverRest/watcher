# Data Model: Read-only MCP Server

Tool inputs and outputs are in [contracts/tools.md](contracts/tools.md) and
[contracts/tools.schema.json](contracts/tools.schema.json); the command line is in [contracts/cli.md](contracts/cli.md).
This page lists the objects behind them.

## Objects (new, namespace `Watcher\Mcp`)

| Object | Kind | Role |
|---|---|---|
| `McpCommand` | CLI entry | Builds config, read-only store and tools; runs `Server`; wired in `src/Cli/Application.php` as `mcp`. |
| `Server` | service | Line reader (64 KB cap), JSON-RPC decode, routing of the five methods, error codes, `initialize` state. Writes one line per response to the output stream; never throws out of `serve()` for client input. |
| `JsonRpc` | pure | Encode/decode, error constructors, id validation (string, int or null; never echoed unless valid). |
| `Tool` | contract (6 implementations) | `name()`, `description()` (static), `inputSchema()`, `call(array $args): array` returning the `data` array. Exists because the spec names six tools with one registry. |
| `ToolRegistry` | service | Fixed list; omits `watcher_dry_run` unless `mcp.allow_dry_run`; validates arguments against the tool's schema (unknown property, type, range). |
| `Envelope` | pure | Builds `schema`, `untrusted_data`, `notice`, `service`, `tool`, `now`, `data` or `error`; enforces the 16 KB cap (drops findings from the end, sets `truncated`). |
| `OutputGuard` | pure | The text pipeline of FR-022: format-char strip -> `Redactor` second scan -> command filter -> URL/host/e-mail filter -> cap. And the typed validators of FR-023. Reuses the digest's command filter (extracted to a shared function, T017) - not a second copy. |
| `ReadOnlyStateStore` | decorator of `StateStore` | `get` passes through; `put`, `increment`, `acquire`, `release` throw `ReadOnlyViolation`; not `Purgeable`. |
| `LastReport` | value + reader | Reads and validates the last-report record. |
| `HygieneSource` | seam | `latest(): ?array`; shipped implementation returns null until spec 006 exists. |
| `DryRunGuard` | service | Confirmation, `Spend::exhausted`, estimate upper bound plus the escalation allowance (`escalation_cap` x worst-case price of the escalation model via `CostCalculator`), in-process counter, `busy` flag. |

Reused unchanged: `HeartbeatStatus`, `Spend`, `Doctor` (read-only flag added), `Redactor`, `Keys`, `StateStore`
implementations, `DryRunStateStore` (already swallows every write in a dry run), `EstimatingAnalyzer`, `RunOptions::dryRun()`, the `Watcher` output callable.

## State (existing store; one new key, written by a real run, never by the server)

| Key (`Keys::` kind) | Value | TTL | Used for |
|---|---|---|---|
| `lastrep:{key}` (`LASTREPORT`) | `{v:1, windowStart, windowEnd, outcome, at, counts{events,groups,candidates,findings}, findings:[{fp, severity, title}]}` (at most `top_m` findings, `title` <= 80 after the output scrub) | `state.retention` (as run records) | `watcher_latest_report` |

Dry run: the pipeline's `DryRunStateStore` swallows writes, so the last-report record is built in memory by `Watcher` and
returned on the run result (T021); the server never reads it back from a store.

Read by the server: `lastrep:*`, `verdict:{key}:{fp}`, `reported:{key}:{fp}`, `hb:{key}`, `trig:{key}`, spend counters
(`spend*`), and (via `Doctor`) one heartbeat read. Written by the server: nothing.

The record holds only fields that already appear in the digest (fingerprint, severity, scrubbed title,
counts). No raw line, no template, no field value. On older records without the key the tool answers
`available: false` / `no_report`.

## Finding fields returned (from `verdict:` and `reported:`)

| Field | Class | Cap / form |
|---|---|---|
| `fp` | typed | 12 lowercase hex |
| `severity` | typed | `info`/`warn`/`error`/`critical` |
| `kind` | typed | `bug`, `regression`, `anomaly`, `noise` (`FindingKind`) |
| `confidence` | typed | float 0..1 |
| `escalated` | typed | bool |
| `title` | text | 80 |
| `evidence` | text | 240 |
| `suspected_cause` | text | 240 |
| `suggested_action` | text | 200 |
| `how_to_check` | text | 200 |
| `related_checks[]` | typed | each exactly a 12-hex fingerprint or a name in the configured `dependencies` (checked against the config, not a pattern); at most 3 |
| `last_reported_at` | typed | ISO-8601 UTC `Z`, from `reported:` (`lastReportedAt`) |
| `count` | typed | integer, `atCount` of the verdict / report |
| `mute_suggestion` | text (code-built) | fixed sentence + fingerprint |

`reported:` holds only `lastReportedAt` and `atCount`; there is no first-seen time and no previous count, so none is returned.

Not read, not returned: `tpl`, frames, `watch` names, group `first`/`last` timestamps, anything under `fields`.

## In-process only

`DryRunGuard::$spentUpperBound` (float USD) and `::$tokens` (int), `::$running` (bool). Not persisted.
