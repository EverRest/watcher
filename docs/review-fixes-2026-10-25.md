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

## Batch 8 — pipeline lows (closed)

| ID | Item | Status |
|----|------|--------|
| P-L1 | `Retry`: `Retry-After` HTTP-date not parsed | closed |
| P-L2 | `Normalizer` / `Redactor`: replacement rules rebuilt on every scan | closed |
| P-L3 | `DatadogSource`: unparseable log timestamp coerced to `now()` and skewed out of the window | closed |

Deferred from batch 8 (not worth the diff now): retry backoff jitter; Loki per-row `json_encode` dedup key; Telegram UTF-16 length accounting.

## Release process — security review F9 (2026-10-26)

The review's register, `SECURITY-REVIEW-2026-10-26.md`, is not in this repository, so F9 is tracked here. Cause: tag `v0.2.0` points to `36673a5`, an older commit, while `main` had moved one minute earlier; `release.yml` checked only that the tag was an ancestor of `origin/main`.

| ID | Item | Status |
|----|------|--------|
| F9-1 | `release.yml`: four guards before the release (`annotated`, `tip`, `version`, `changelog`) through `scripts/check-release.sh`; tested by `make release-guards-test` (branch `chore/release-guards`) | done, not merged |
| F9-2 | `docs/publishing.md`: release steps in order; after a wrong tag, never move a published tag, release the next patch | done, not merged |
| F9-3 | Owner, GitHub UI: tag ruleset for `v*` (restrict creation, block update and deletion) | open (owner) |
| F9-4 | Owner, GitHub UI: require the CI checks (every `test` leg and `coverage`) on `main` | open (owner) |
| F9-5 | Existing tag `v0.2.0` on `36673a5` | left as is; fixed forward by the next patch |

Sibling sweep (`is-ancestor`, `gh release`, `git tag`, `refs/tags`, `tags:` across `.github`, `scripts`, `docs`, `Makefile`): `release.yml` is the only release gate. `scripts/badges.php` and `docs/development.md` read the latest tag for the version badge; they create nothing, so they are unchanged, and the badge shows the wrong tag until a newer one exists.

## Later batches — open

| ID | Item | Batch | Status |
|----|------|-------|--------|
| H1 | `Watcher::locked()` refactor — `RunLedger` / `EventCollector` extraction | — | deferred |
| SEC | Security review follow-ups | — | in progress |
| F6 | Robust against bad remote and stored data (LOW) | — | closed on `fix/robust-inputs` |

## Batch 9 — F6: robust against bad remote and stored data (fix/robust-inputs)

Finding F6 of the 2026-10-26 security review (LOW), phase 0 of Lane F. The rule below is what the readers now do; the summary keys are in [operations.md](operations.md) under `--json`.

### Rule: unusable remote data

- A value a reader needs is used only when its type and range are usable (`src/Source/RemoteValue.php`): a log timestamp is a nanosecond digit string or an epoch number in 2000..2100; a count is a number or numeric string from 0 to 10^15.
- An unusable entry (a log line, a series, a bucket, an event) is skipped and counted in `malformed_entries` (`read_quality`). Its content is never kept.
- A reply where **more than half** of its entries are unusable is not a log read: it fails as `SourceUnavailable('malformed')`, the same as any other source failure. A reply whose `data` is not a list fails the same way.
- A missing Datadog timestamp still falls back to the clock (existing, tested behaviour). Stream labels that are not scalars are dropped, not counted: a label is metadata.
- Counters are per read: `malformed_entries` reports the last `lines()` call, as `pages_shrunk` does. Entries skipped by `totals()` and `versions()` add to it and are reported only if no `lines()` call follows them. Known limit, accepted for this phase.

### Rule: stored baseline records

- A snapshot whose group values are not counts (non-negative numbers up to 10^15, finite) is a corrupt record. It is treated as missing (first-run behaviour) and counted in `state_unreadable`. The check is `BaselineResolver::intact()`, applied to the candidate snapshots, to the previous snapshot, and in `Watcher` before any reader sees the previous snapshot.

### Tests (red first)

| Test | Red before | Green after |
|------|------------|-------------|
| `LokiSourceTest::testMalformedLinesAreSkippedAndCounted`, `testAVersionPointWithoutATimestampIsSkippedNotDatedTo1970`, `testAnUnusableTotalIsSkippedAndCounted`, `testAReplyMostlyMalformedFailsTheReadAsMalformed` | 1970 entry kept; no count | yes |
| `LokiSourceTest` / `DatadogSourceTest::testHostileValuesNeverRaiseAWarningOrATypeError` (data provider over `HostileValues`) | out-of-range cast warnings, `Array to string conversion` | yes |
| `DatadogSourceTest::testMalformedItemsAreSkippedAndCounted`, `testADataFieldThatIsNotAListFailsAsMalformed`, `testAnUnusableTotalBucketIsSkippedAndCounted` | silent drop; `foreach` warning on a non-list `data` | yes |
| `BaselineResolverTest::testCorruptSnapshotIsTreatedAsMissingAndCounted`, `testCorruptPreviousSnapshotIsTreatedAsMissingAndCounted`, `testHostileStoredValueNeverRaisesAWarningOrATypeError` | `TypeError: Unsupported operand types: float * array` (rolling) | yes |
| `RunSummaryTest::testCorruptBaselineSnapshotsAreTreatedAsMissingAndCountedNotThrown` (end to end) | run throws | yes |
| `WatchConfigTest::testHostileModelNameIsInvalidConfig`, `testDatadogSiteIsAHostNameOrInvalidConfig` | already green: the factory already refuses these | guard, kept |
| `ResponseLimitTest::testADeclaredLengthOverTheCapTripsTheCapWithoutACast` | cast warning on a huge header | yes |
| `RetryTest::testAHugeRemoteWaitGivesUpWithoutACastWarning` | cast warning on `1e400`, huge digits, `PHP_INT_MAX` hint | yes |
| `LineParserHardeningTest::testAHugeSeverityIsNotCastToAnIntAndReadsAsUnknown` | cast warning | yes |
| `EventLedgerTest::testAHugeStoredTimestampIsSkippedNotCast` | cast warning on a stored digit run | yes |
| `BaselineResolverTest` (`previousFor` read-back of a huge value) | cast warning in `Comparison::previousFor()` | yes |

### Siblings

Grep: `grep -rn '(int)\|(string)\|(float)' src/Source src/State src/Baseline` (104 hits; those that cast a decoded remote or stored value are below, the rest are config, internal values, or already guarded by `is_numeric` / `is_scalar` / a regex that bounds the digits).

| Site | Casts | Verdict |
|------|-------|---------|
| `LokiSource::totals`, `versions`, `page` | `(int)` of count, timestamp, line | fixed: `RemoteValue` |
| `DatadogSource::totals`, `versions`, `lines` | `(int)` of count | fixed: `RemoteValue::count` |
| `ResponseLimit::options`, `decode` | `(int)` of `Content-Length` header | fixed: `declared()` |
| `Retry::sendRateLimited`, `retryAfterSeconds` | `(int) ceil` of hint and header | fixed: capped at `MAX_ASK` before the cast |
| `LineParser::jsonLevel` | `(int)` of `severity` | fixed: range guard |
| `LineParser::plainLevel` | `(int) $m['n']` | already safe: `\d{1,3}` |
| `LineParser::time` | `(float)`, `(int) floor` | already safe: range check before the cast |
| `EventLedger::points` | `(int)` of stored digits | fixed: length guard |
| `EventLedger` `(int) $delta` | count from the mapper | already safe: the mapper keeps `count` an int >= 1 |
| `EventMapper` `(string) $k` | array keys | already safe: keys are scalar |
| `BaselineResolver` `(float)` of group values | arithmetic | fixed: `intact()` |
| `Comparison::previousFor` `(int) round` | read-back | fixed: `intact()` bounds values to 10^15 |
| `Watcher` metric anomalies reading `$prev['groups']` | direct read | fixed: corrupt previous snapshot dropped in `Watcher` first |
| `RedisStateStore::get` `(float) $raw` | stored | already safe: `is_numeric` guard |
| `RedisStateStore` `(float) $result`, `PhpRedisClient` `(int) pttl` | client reply | already safe: a float cast does not warn; `false` casts to 0 |
| `FileStateStore::...` `(int) file_get_contents($marker)` | local marker file | not remote; a corrupt marker reads as 0 (existing, local). Not changed. |
| `FileStateStore` `(float) $current` | stored | already safe: `is_numeric` guard |
| `PdoStateStore` `(float)`, `(string) getCode()` | stored / driver | already safe: `is_numeric` guard; driver code |
| `Hygiene/Cost/CostReader` reads stored `groups` | `is_int` guard per value | already safe |
| `KubeApi`, `KubeSettings`, `LabelSelector`, `Keys`, `LokiSource` label/time/page casts | config or internal values | not remote |

### Not done here (per the brief)

- No change to what a Datadog `site` may contain: the config and the source already accept only `[a-z0-9]+([.-][a-z0-9]+)*`.
- The Telegram/Discord hint closures accept only an int from the reply; no change.
