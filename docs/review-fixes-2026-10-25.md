# Pipeline + core review — fix batches (2026-10-25)

Tracks remediation from the pipeline and core code reviews. Status: `open` / `queued` / `deferred` / `in progress` / `closed`.

Related: [review-2026-10-23.md](review-2026-10-23.md) (log-source and reducer findings).

## Batch 1 — hygiene (closed)

| ID | Item | Status |
|----|------|--------|
| B1-1 | Ignore local Redis dumps (`*.rdb` in `.gitignore`) | closed |
| B1-2 | `Factory.php` M4: merge consecutive docblocks; fix lock-renewal comment now that `RenewableLock` and `renewLock()` exist | closed |
| B1-3 | `review-2026-10-23` M1 `source.group_cap` — implemented; status synced to done | closed |

## Later batches — open

| ID | Item | Batch | Status |
|----|------|-------|--------|
| P-M4 | Slack webhook retry / transient failure handling | 2 | open |
| P-M5 | `BufferedStateStore` flush semantics (edge cases after failed delivery) | 2 | open |
| P-M1 | PDO `StateStore`: `rowCount` / affected-rows correctness | 2 | open |
| P-M2 | PDO state purge / retention | 2 | open |
| H2 | Laravel fleet integration gaps | 3 | open |
| M1 | CLI: catch `Throwable` at top level vs narrow types | 3 | open |
| M2 | `AnalyzerFailed` catch scope / error surfacing | 3 | open |
| W6 | `CodeLinkBuilder` path prefix boundary | 4 | open |
| H5 | Loki stack-trace continuation / server-side line filter | 4 | open |
| P-H1 | Fingerprint / template versioning | 5 | open |
| P-M3 | Notifier message parts / splitting | 5 | open |
| P-M6 | `LineParser` correctness (epoch ms, levels, multiline) | 5 | queued |
| H1 | `Watcher::locked()` refactor — `RunLedger` / `EventCollector` extraction | — | deferred |
| SEC | Security review follow-ups | — | in progress |
