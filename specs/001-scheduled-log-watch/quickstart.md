# Quickstart: validating Scheduled Log Watch

## Prerequisites

- PHP ≥ 8.1 with `ext-json`, `ext-mbstring`; `ext-redis` and `pdo_sqlite` for the store contract
  tests (Redis tests skip if no server at `WATCHER_TEST_REDIS`).
- Composer.

## 1. Gates (no network)

```bash
composer install
composer test        # phpunit: Unit + Contract + EndToEnd suites
composer stan        # phpstan
composer style       # pint --test
```

Each command's exit code must be 0 (read `$?`, not the last output line).

## 2. Scenario checks (all with fakes, from `tests/EndToEnd`)

| # | Scenario | Expected |
|---|---|---|
| 0 | (Level anomaly) info total ×6 vs previous window | second pass reads info lines ≤ `anomaly_cap`; digest line "info ×6" (FR-038) |
| 1 | First run, no snapshot | outcome `baselined`; one baseline notice; 0 AI calls (FR-035) |
| 2 | Second run, one new error group | outcome `reported`; digest lists it with fingerprint; usage footer present (US1) |
| 3 | Same input again next window | outcome `quiet`; 0 AI calls; no message (FR-008, FR-018, FR-025) |
| 4 | Group grows ×4 | reported as `↑ from N` (FR-006) |
| 5 | Privacy corpus run | no seeded email/name/phone/token in any captured AI or Slack payload (US2, SC-002) |
| 6 | Watch rule matches a warning group | group is a candidate (FR-012b) |
| 7 | Muted fingerprint, then expired | excluded, then included (FR-034) |
| 8 | Triggered at 05:00 with `run_at` 06:00 | outcome `skipped_not_due`; no external call (FR-013) |
| 9 | Two runners concurrently | one `reported`, one `skipped_locked` (FR-028) |
| 10 | Analyzer returns invalid JSON twice | outcome `failed`; "analysis failed" notice (US5) |
| 11 | Source returns 401 | outcome `failed`; "source failed" notice; exit code 1 |
| 12 | Daily budget already spent | triage only; "budget exhausted" notice (FR-022) |
| 13 | Input over `max_input_tokens` | lowest-priority groups dropped; "truncated" line (FR-021) |
| 14 | Low-confidence error finding | escalated once; never more than `escalation_cap` per run (FR-016) |
| 15 | A logs errors to `http://core-api/…`, core-api has a new error group | A's finding names core-api with its counts (FR-039) |
| 16 | Template references undeclared host `reporting-api` | digest shows a count only: "1 call target(s) not declared as dependencies" (the host is masked as `<URL>`, never named) |
| 17 | Release changed since previous window, new group | finding marked "first seen after <release>" (FR-041) |
| 18 | Any finding | carries a log link without personal data (FR-042) |
| 19 | Finding with app frames and vendor frames | app frames link to the repo at the commit, vendor frames have no link (FR-043) |

```bash
vendor/bin/phpunit --testsuite EndToEnd
```

## 3. Manual smoke against a real environment (optional, by the Operator)

1. Copy `config/watcher.example.php`, set `service`, `source` (Loki or Datadog read-only token),
   `analysis` key and models, `state.driver=file`, a test Slack webhook, `run_at` = current hour.
2. `vendor/bin/watcher run --config=watcher.php` → expect the **baseline** notice in Slack.
3. Run again with `--now` one window later → expect a digest or no message (quiet), and a footer
   with tokens and cost when a model was called.
4. Run again immediately → no second message (idempotent, FR-028).
