# Data Model: Read-only MCP Server

Tool inputs and outputs are in [contracts/tools.md](contracts/tools.md) and
[contracts/tools.schema.json](contracts/tools.schema.json); the command line is in [contracts/cli.md](contracts/cli.md).
This page lists the objects behind them.

## Objects (new, namespace `Watcher\Mcp`)

| Object | Kind | Role |
|---|---|---|
| `McpCommand` | CLI entry | Builds config, read-only store and tools; runs `Server`; wired in `src/Cli/Application.php` as `mcp`. |
| `Server` | service | Line reader (64 KB cap), JSON-RPC decode, routing of the five core methods and, with the `docs` group, the five resources/prompts methods, error codes, `initialize` state. Writes one line per response to the output stream; never throws out of `serve()` for client input. |
| `JsonRpc` | pure | Encode/decode, error constructors, id validation (string, int or null; never echoed unless valid). |
| `Tool` | contract (14 implementations) | `name()`, `group()`, `description()` (static), `inputSchema()`, `call(array $args): array` returning the `data` array. Exists because the spec names fourteen tools with one registry. |
| `ToolRegistry` | service | Fixed list per group (`mcp.groups`; `--setup` = onboarding + docs); omits `watcher_dry_run` unless `mcp.allow_dry_run`; validates arguments against the tool's schema (unknown property, type, range) and runs the `SecretGuard` first on the onboarding and docs tools. |
| `Envelope` | pure | Builds `schema`, `untrusted_data`, `notice`, `service`, `tool`, `now`, `data` or `error`; enforces the 16 KB cap (drops findings from the end, sets `truncated`). |
| `OutputGuard` | pure | The text pipeline of FR-022: format-char strip -> `Redactor` second scan -> command filter -> URL/host/e-mail filter -> cap. And the typed validators of FR-023. Reuses the digest's command filter (extracted to a shared function, T017) - not a second copy. |
| `ReadOnlyStateStore` | decorator of `StateStore` | `get` passes through; `put`, `increment`, `acquire`, `release` throw `ReadOnlyViolation`; not `Purgeable`. |
| `LastReport` | value + reader | Reads and validates the last-report record. |
| `HygieneSource` | seam | `latest(): ?array`; shipped implementation returns null until spec 006 exists. |
| `DryRunGuard` | service | Confirmation, `Spend::exhausted`, estimate upper bound plus the escalation allowance (`escalation_cap` x worst-case price of the escalation model via `CostCalculator`), in-process counter, `busy` flag. |

Reused unchanged: `HeartbeatStatus`, `Spend`, `Doctor` (read-only flag added), `Redactor`, `Keys`, `StateStore`
implementations, `DryRunStateStore` (already swallows every write in a dry run), `EstimatingAnalyzer`, `RunOptions::dryRun()`, the `Watcher` output callable.

## Objects added by the 2026-10-25 amendment (namespaces `Watcher\Mcp`, `Watcher\Config`, `Watcher`)

| Object | Kind | Role |
|---|---|---|
| `Mcp\SecretGuard` | pure | `looksSecret(string): bool` over the shapes of `Reduce\Normalizer` (reused: the token, known-prefix, webhook, JWT, PEM, URL-with-credentials, DSN-password rules) plus the Slack/Discord/Telegram shapes; used before schema validation for every string of the onboarding and docs tools and prompt arguments. Refusal = `SecretRefused` (message fixed, value never held). |
| `Mcp\PathPolicy` | pure | FR-067: NUL/length, `realpath`, regular `.php`, owner and mode (shared classifier with `observe.file.path`), under an allowed root (roots compared after `realpath`). Returns the resolved path or a closed reason. Also used by `Mcp\ConfigFileReader` (bounded read, 256 KB + 1). |
| `Mcp\SetupPlanner` | service | `plan(host, source, notifier, state, features): array` and `firstDay(host): array` from a code catalog (`SetupCatalog`: step templates keyed by driver); commands built from the host enumeration; every expected code is a `OutcomeRegistry` constant. |
| `Mcp\ConfigGenerator` | service | Builds the PHP snippet and the env-variable list from validated answers; one escaping function (`var_export`-style single quotes); emits `Env::bool/string/float('WATCHER_...')` or `env()` for the Laravel form; `enabled` reads `WATCHER_ENABLED` default false. |
| `Mcp\ConfigFileValidator` | service | Reads via `ConfigFileReader`, includes the file, runs `WatchConfig::fromArray`, maps `InvalidConfig` messages (key paths) to `KeyCatalog` hints; static tokenizer scan for literals at `secret` keys (`SecretLiteralScan`, guarded by `function_exists('token_get_all')`). Holds no value from the file in its result. |
| `Mcp\RolloutInspector` | service | PHP version vs the package floor, extensions per state driver, installed version (`VERSION`), project constraint (`composer.json` read-only), config presence, master-switch state, and with `connect` one `get` via `ReadOnlyStateStore`. |
| `Config\KeyCatalog` | table | One entry per config key path (free-form maps by wildcard): `type`, `required`, `default`, `env`, `secret`, `summary`, `constraints[]`, `related[]`, `pitfalls[]`, `docs{file,anchor}`. `WatchConfig` accepts exactly the catalog's keys (unknown-key rejection reads it); `Mcp\ConfigExplainer` reads it. |
| `Mcp\ConfigExplainer` | service | `explain(key)`: exact path, then wildcard entry, else `found:false` + `did_you_mean` (Levenshtein over catalog paths, <= 3). |
| `OutcomeRegistry` | table | Constants for every code plus entries `{code, kind, severity, exit_code, meaning, likely_cause, check[], fix[], related[], docs, reserved_by}`; family entries for `name:*`. Emit sites use the constants. |
| `Mcp\DocsIndex` | service | Allowlist (`DocsAllowlist`: exact relative paths -> MIME), lazy in-process index: sections split at `^#{1,4} ` headings, GitHub-style anchors, lower-cased term counts, heading boost; `search(query, limit)`; `read(uri)` by exact allowlist match. |
| `Mcp\PromptCatalog` | table | Four prompts: name, description, arguments (enumerations + defaults), ordered tool names, static text with `{enum}` slots, golden files. Listing filters on enabled tools. |

Setup-tool outputs are not stored anywhere; the docs index lives in process memory only.

### KeyCatalog entry

| Field | Type | Notes |
|---|---|---|
| `path` | string | `selection.growth_factor`; wildcard segments `<name>` for free-form maps (`source.stream_tags.<label>`, `analysis.prices.<model>`, `watch_rules.<name>`). |
| `type` | enum | `string`, `int`, `float`, `bool`, `list`, `map`, `duration`, `enum`, `regex`. |
| `required` | bool | |
| `default` | scalar or null | The value the validator applies; compared with the loaded `WatchConfig` in a test. |
| `env` | string or null | The `WATCHER_*` variable the shipped configs read. |
| `secret` | bool | Drives `plain_text_secret` and the `secret: true` flag in generated env lists. |
| `summary`, `constraints[]`, `pitfalls[]` | static text | Written once, in the catalog; a test ties each key to a row of `docs/configuration.md`. |
| `related[]` | paths | Must exist in the catalog (test). |
| `docs` | `{file, anchor}` | Anchor must exist in the docs file (test). |

### OutcomeRegistry entry

| Field | Type | Notes |
|---|---|---|
| `code` | string | `lock_lost`; families `truncated:<reason>`, `k8s_ingest_failed:<reason>` registered once with `family: true`. |
| `kind` | enum | `outcome` (RunOutcome cases), `reason` (run/heartbeat reason codes), `notice`, `check_reason` (doctor vocabulary), `event_code` (event stream `config_warning` / delivery codes), `counter` (JSON summary health counters), `mcp_error`. |
| `severity` | enum | `info`, `warn`, `error`. |
| `exit_code` | int or null | For outcomes (0, 1, 2). |
| `meaning`, `likely_cause`, `check[]`, `fix[]` | static text | |
| `related[]` | codes | Must be registered (test). |
| `reserved_by` | spec id or null | Set for codes of specs not yet implemented (e.g. `k8s_ingest_failed` -> `spec-008`); a test lists them. |

### Resource index entry (in memory)

`{uri, path, mime, bytes, sections[]}`; section `{anchor, heading, level, line, terms{term: count}}`. Built lazily on the first
`resources/*` or `watcher_search_docs` call, from the allowlist only; files over 512 KB are skipped; a missing file is skipped.

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
