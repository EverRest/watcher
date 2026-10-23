# Quickstart: validating Operability Tools

All scenarios run with fakes (no network). Gates: `composer test`, `composer stan`, `composer style`, each exit 0
(read `$?`).

| # | Scenario | Expected | Test file |
|---|---|---|---|
| 1 | Quiet, reported, baselined, failed run, then read the heartbeat | record holds time, outcome, window, service, cost; `lastOkAt` set except after first failure | `tests/EndToEnd/HeartbeatTest.php` |
| 2 | `skipped_done`, `skipped_locked`, `skipped_not_due` after a real run | `hb:` unchanged; `trig:` updated (US1 AC2) | `tests/EndToEnd/HeartbeatTest.php` |
| 3 | Advance clock past the limit (default 1.5 x window) | `status` -> `stale`, exit 1, prints both instants (SC-001) | `tests/Unit/Heartbeat/HeartbeatTest.php`, `tests/Unit/CliTest.php` |
| 4 | Last run failed, `lastOkAt` recent | `status` -> `failed`, exit 1 | `tests/Unit/Heartbeat/HeartbeatTest.php` |
| 5 | Store unreadable / no record | `status` -> `unknown`, exit 3, never exit 0 | `tests/Unit/Heartbeat/HeartbeatTest.php` |
| 6 | Heartbeat `put` throws (`FailingStateStore`) | outcome and exit code unchanged; host log has the failure (FR-005) | `tests/EndToEnd/HeartbeatTest.php` |
| 7 | Weekly summary on; a week with runs, then first run of next week | one message with counts and cost; second run of that week sends nothing; week with no runs sends nothing; off by default | `tests/EndToEnd/HeartbeatTest.php`, `tests/Unit/Notify/NoticeRendererTest.php` |
| 8 | Two services in one store | heartbeats independent | `tests/Unit/Heartbeat/HeartbeatTest.php` |
| 9 | `check`, all fakes healthy | all PASS, exit 0, no Slack post, no model call | `tests/Unit/Check/DoctorTest.php` |
| 10 | `check` with one failing dependency at a time (source 401, bad model id, missing price, store throws, bad webhook) | that check FAIL with its reason, others still run, exit 1 (FR-008) | `tests/Unit/Check/DoctorTest.php` |
| 11 | Bearer 401, `x-api-key` 200 | `provider_auth` FAIL with the explicit sentence (US2 AC6); both 200 -> PASS; both 401 -> FAIL | `tests/Unit/Analyze/AnthropicModelCatalogTest.php` |
| 12 | `check --post-test` | exactly one labelled message per destination; without the flag zero | `tests/Unit/Check/DoctorTest.php` |
| 13 | Report text and JSON scanned for the seeded secrets/URLs/log lines | 0 occurrences (SC-006) | `tests/Unit/Check/DoctorTest.php`, `tests/EndToEnd/PrivacyTest.php` |
| 14 | `check` on an empty store; two checks concurrently | no real keys touched; scratch keys unique | `tests/Unit/Check/DoctorTest.php` |
| 15 | Same window, real run vs `--dry-run` on identical fakes | digest text identical; dry run leaves state dir byte-for-byte unchanged and `FakeNotifier` empty (SC-003) | `tests/EndToEnd/DryRunTest.php` |
| 16 | Dry run, window already reported | executes, prints the "already reported" label | `tests/EndToEnd/DryRunTest.php` |
| 17 | Dry run with model calls | analyzer called, label says billed, `spend:` counters and `alert:` keys unchanged, no budget alert | `tests/EndToEnd/DryRunTest.php` |
| 18 | `--dry-run --estimate` | zero analyzer calls; per-step counts and token estimates printed | `tests/EndToEnd/DryRunTest.php` |
| 19 | `--estimate` without `--dry-run`; `--dry-run` outside the run_at hour | usage error exit 2; `skipped_not_due` with the `--now` hint | `tests/Unit/CliTest.php` |
| 20 | `--json` on reported, quiet, failed, invalid config | exactly one stdout line, same field names every time; human text only on stderr (SC-005) | `tests/Unit/CliTest.php`, `tests/Unit/Cli/JsonSummaryTest.php` |
| 21 | JSON line scanned against the privacy corpus | counts and ids only (FR-017, SC-006) | `tests/EndToEnd/PrivacyTest.php` |
| 22 | File store: expired entries, live entries, foreign files, stale lock sidecars | expired and old sidecars removed, live and foreign kept; at most once per day; bounded by 2 s / 5,000 files (SC-007) | `tests/Unit/State/FileStateStorePurgeTest.php` |
| 23 | Failed write (full disk) | previous record intact, error raised | `tests/Unit/State/FileStateStorePurgeTest.php` |
| 24 | Purge during a held lock | held sidecar not removed; waiter on a removed sidecar re-opens (no two holders) | `tests/Unit/State/FileStateStorePurgeTest.php` |
| 25 | Laravel commands | same options, outputs and exit codes as the CLI | `tests/Unit/Laravel/RunCommandTest.php`, `StatusCommandTest.php`, `CheckCommandTest.php` |

## Manual (Operator, after implementation)

1. `watcher check --config=...` against the real environment; every line PASS. Replaces the six `curl` steps in
   `docs/smoke-test.md` (update that doc in the final task).
2. `watcher run --dry-run --now=<inside run_at hour> --config=...`: read the digest in the terminal; confirm no Slack
   message and the state directory unchanged.
3. `watcher status --config=...` from the host monitor's probe; stop the schedule and confirm exit 1 after 36 hours
   (or set `heartbeat.stale_after` to `PT1H` and wait).
