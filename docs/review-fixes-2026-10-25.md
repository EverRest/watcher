# Pipeline + core review — fix batches (2026-10-25)

Tracks remediation from the pipeline and core code reviews. Status: `open` / `queued` / `deferred` / `in progress` / `closed`.

Related: [review-2026-10-23.md](review-2026-10-23.md) (log-source and reducer findings).

## Batch 1 — hygiene (closed)

| ID | Item | Status |
|----|------|--------|
| B1-1 | Ignore local Redis dumps (`*.rdb` in `.gitignore`) | closed |
| B1-2 | `Factory.php` M4: merge consecutive docblocks; fix lock-renewal comment now that `RenewableLock` and `renewLock()` exist | closed |
| B1-3 | `review-2026-10-23` M1 `source.group_cap` — implemented; status synced to done | closed |

## Batch 2 — state + Slack (closed)

| ID | Item | Status |
|----|------|--------|
| P-M4 | Slack webhook retry / transient failure handling | closed |
| P-M5 | `BufferedStateStore` flush: unset each key only after successful `put` | closed |

## Batch 4 — Laravel + CLI (closed)

| ID | Item | Status |
|----|------|--------|
| H2 | Laravel `watcher` config with sibling `fleet` breaks `Watcher::fromConfig` / `Factory::doctor` | closed |
| M1 | CLI and `watcher:run`: catch `Throwable` at process boundary (class + message, exit 1) | closed |
| M2 | `AnalyzerFailed` handler: state snapshot/write must not mask the analysis error | closed |

## Later batches — open

| ID | Item | Batch | Status |
|----|------|-------|--------|
| P-M1 | PDO `StateStore`: MySQL `rowCount` / affected-rows | 3 | open |
| P-M2 | PDO state purge / `Purgeable` | 3 | open |
| W6 | `CodeLinkBuilder` path prefix boundary | 5 | open |
| H5 | Loki stack-trace continuation / server-side line filter | 5 | open |
| P-H1 | Fingerprint / template versioning | 6 | open |
| P-M3 | Notifier message parts / splitting | 7 | open |
| P-M6 | `LineParser` decode memoization / injected clock | 7 | queued |
| H1 | `Watcher::locked()` refactor — `RunLedger` / `EventCollector` extraction | — | deferred |
| SEC | Security review follow-ups | — | in progress |
