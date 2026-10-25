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

## Batch 3 — PDO state (closed)

| ID | Item | Status |
|----|------|--------|
| P-M1 | PDO `StateStore`: MySQL `rowCount` / affected-rows (`MYSQL_ATTR_FOUND_ROWS` in `Factory::pdo`) | closed |
| P-M2 | PDO state purge: `Purgeable` on `PdoStateStore`; lazy PDO wrapper forwards `purge()` | closed |

MySQL-specific `rowCount` behaviour is covered by contract tests on SQLite plus a Factory source check; a CI job with MySQL remains a follow-up.

## Batch 4 — Laravel + CLI (closed)

| ID | Item | Status |
|----|------|--------|
| H2 | Laravel `watcher` config with sibling `fleet` breaks `Watcher::fromConfig` / `Factory::doctor` | closed |
| M1 | CLI and `watcher:run`: catch `Throwable` at process boundary (class + message, exit 1) | closed |
| M2 | `AnalyzerFailed` handler: state snapshot/write must not mask the analysis error | closed |

## Batch 5 — Code links + Loki text filter (closed)

| ID | Item | Status |
|----|------|--------|
| W6 | `CodeLinkBuilder::mapPath` prefix match without path-segment boundary (`/app` vs `/apple`) | closed |
| H5 | Loki text-mode line filter drops stack-trace continuations server-side | closed (continuations); unworded labelled main lines still use `level_mode: label` |

## Batch 6 — fingerprint versioning (closed)

| ID | Item | Status |
|----|------|--------|
| P-H1 | Fingerprint version `fpv` in window snapshots; stale `fpv` → baseline reset notice; golden Normalizer corpus | closed |

### `fpv` behaviour

- `Fingerprinter::FPV` (currently `1`) is stored on every window snapshot as field `fpv`.
- Snapshots without `fpv` or with an older value are not used for growth comparison, rolling/weekday history, or H6 last-good fallback.
- After a Normalizer upgrade the next run records a fresh baseline and sends a **baseline reset** notice (`fpv N → M`). A true first install still gets **baseline recorded**.

## Batch 7 — Notify + LineParser (closed)

| ID | Item | Status |
|----|------|--------|
| P-M3 | Telegram/Discord partial delivery duplicates on digest retry | closed |
| P-M6 | `LineParser` decodes the same JSON line repeatedly while classifying continuations | closed |
| P-M6-low | `LineParser::inRange` used `time()` instead of the injected clock | closed |


## Later batches — open

| ID | Item | Batch | Status |
|----|------|-------|--------|
| H1 | `Watcher::locked()` refactor — `RunLedger` / `EventCollector` extraction | — | deferred |
| SEC | Security review follow-ups | — | in progress |
