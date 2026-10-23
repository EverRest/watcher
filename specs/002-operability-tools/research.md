# Research: Operability Tools

Only decisions where more than one design was reasonable. Facts are from the code as of 2026-10-24.

## R1. Where the heartbeat lives, and how it expires

- **Decision**: in the existing `StateStore`, key `hb:{service}`, TTL = 3 x `state.retention` (90 days by default),
  rewritten on every due run, so it expires only when no run happened for three retention periods. Host monitoring
  reads it with `watcher status`, not by touching the store.
- **Alternatives**: a file in a configured directory (a second storage, and a Kubernetes job has no writable shared
  disk); a push to a dead-man-switch URL (new outbound call, new secret, outside the spec).
- **Consequence**: an unreadable store makes both the pipeline and the heartbeat unreadable; `status` reports
  `unknown` (exit 3), never "ok".

## R2. What counts as a trigger, and which record a skip may touch

- **Decision**: two keys. `hb:{service}` is written only by a run that finished (reported, quiet, baselined, failed)
  and carries `lastOkAt` forward. `trig:{service}` holds only the time of the last trigger and is written for every
  trigger outcome, including `skipped_not_due`. A skip never touches `hb:`.
- **Open for the owner**: 001 FR-002 says a not-due trigger "costs nothing", and the store is connected lazily so
  that such a trigger never reaches Redis. Writing `trig:` on a not-due trigger connects on every hourly trigger.
  Spec 002 US1 AC2 asks for it, so the plan follows 002; the write is wrapped in try/catch (a Redis outage on a
  not-due trigger must not fail it). The alternative is to record `trig:` only for `skipped_done`/`skipped_locked`.

## R3. Scratch key for the state check

- **Decision**: `check:{random}` via `put` (TTL 60 s), `get`, two `increment`s read back through `get(...)['total']`,
  and `acquire`/`release` on `check:lock:{random}`. Nothing is deleted because the contract has no delete; the keys
  expire in 60 s. Unique names make two concurrent checks independent.
- **Alternative**: add `StateStore::delete()`. Rejected: breaks the four-contract rule and four stores for a
  60-second leftover.

## R4. Anthropic authentication probe

- **Decision**: `GET https://api.anthropic.com/v1/models` (free, no tokens), timeout 5 s, first with the scheme the
  package uses, then the other. The SDK the analyzer wraps sends `Authorization: Bearer` (docs/smoke-test.md); the
  other scheme is `x-api-key` + `anthropic-version: 2023-06-01`. Both are tried even when the first passes, so the
  report can say "bearer: 401, x-api-key: 200". If the package scheme fails and the other works the check fails with
  the explicit sentence required by US2 AC6. The model ids come from the response of the scheme that worked
  (paged with `limit=1000`); each configured id is looked up in that list.
- **Where**: `AnthropicModelCatalog` (adapter) behind the optional `ModelCatalog` interface; `Factory::modelCatalog()`
  returns it only for `analysis.provider = anthropic`. A provider without a catalog gives `skip`, not `fail`.
- **Never**: the key is not placed in any message; a 4xx body is not printed (only the HTTP status).

## R5. Purge safety for lock sidecars

- **Decision**: purge removes (a) `<sha256>.json` whose stored `expires_at` is past, (b) leftover `*.tmp` older than
  the lock lifetime, (c) `<sha256>.json.lock` older than the lock lifetime. Only names matching
  `^[0-9a-f]{64}\.json(\.lock)?$` or `^[0-9a-f]{64}\.json\.[0-9a-f]{8}\.tmp$` are touched.
- **The hazard** (review 2026-10-23, "lock sidecars accumulate"): unlinking a `.lock` that another process has open
  lets a third process create a new inode and lock it, giving two holders. **Decision**: purge takes the sidecar with
  `flock(LOCK_EX|LOCK_NB)` before unlinking, and `FileStateStore::locked()` re-checks after `flock` that the open
  handle's inode still equals the path's inode, retrying the open otherwise.
- **Cadence**: marker file `.purge` in the directory, mtime = last purge; skip if younger than 24 h. Budget: stop at
  2 s or 5,000 directory entries, whichever first; the remainder is done by the next day's run.

## R6. Dry-run store wrapper semantics

- **Decision**: `DryRunStateStore` wraps the real store: `get` reads through except keys of kind `run:` (returns
  null, so "already done" is ignored, FR-013); `put`, `release`, `increment` do nothing (`increment` returns `$by`);
  `acquire` returns true without writing (FR-012: no lock left). It does not implement `Purgeable`, so no purge in a
  dry run. A dry run does not wait for or respect a concurrent real run's lock; it only reads.
- **Not-due trigger**: a dry run still goes through `RunGate`. Outside the `run_at` hour it ends as
  `skipped_not_due`, printed with the hint to pass `--now=<ISO-8601 inside the run_at hour>`. Not adding a gate
  bypass keeps `RunGate` unchanged.
- **Budget alerts and "exhausted" notice**: `Spend::add` is swallowed, so `BudgetAlerts` sees `before == after` and
  fires nothing (FR-014). `noticeOnce` acquires succeed, so a real exhausted day prints its notice to stdout.

## R7. Estimate-only analyzer

- **Decision**: `EstimatingAnalyzer implements Analyzer`; `triage()` records `count(groups)` and the
  `Budget::estimateTokens` of the encoded payload, then returns `interesting` for every group with zero `Usage`, so
  the router reaches `analyze()` for the top-M and the analysis step is estimated too; `analyze()` records and returns
  an empty, zero-usage result. Escalation is shown as "up to `escalation_cap` groups, not estimated". Cost = tokens at
  the configured prices (input side only for triage/analyze plus `max_output_tokens` as the output bound).
- **Consequence**: the estimate is an upper bound for analysis, labelled so in the output.

## R8. The JSON line and the human text

- **Decision**: `--json` prints exactly one line to the `$out` stream (stdout) and nothing else there; the human
  summary line, usage text, check lines and dry-run labels go to stderr. In dry-run, the captured digest text goes to
  stdout *before* the JSON line only when `--json` is absent; with `--json` the digest is written to stderr so stdout
  stays one parsable line.
- **Failure and invalid config**: the line is still printed. For invalid config the service is read from the raw
  array only if it is a string, else `null`; all counters are `null`.
- **Package version**: `Composer\InstalledVersions::getPrettyVersion('everrest/watcher')`, falling back to
  `"unknown"` when the package is not installed through Composer (for example a source checkout without a version).

## R9. Config surface

- **Decision**: one new optional top-level section `heartbeat`: `stale_after` (ISO-8601 duration, default 1.5 x
  `schedule.window`, computed in seconds) and `weekly_summary` (bool, default false). Unknown keys inside are
  rejected like every other section.

## R10. Weekly counters

- **Decision**: counters are `StateStore::increment` keys per ISO week (service timezone) and outcome
  (`hbweek:{service}:{YYYY-Www}:{outcome}` and `...:cost`), TTL 14 days. The first due run of a new week looks at the
  previous week's counters; if reported + quiet + baselined + failed is zero it sends nothing. A sent marker
  (`acquire`, no release on success, released on a failed send, same pattern as `Watcher::noticeOnce`) keeps it to one
  message. Skipped runs counted are `skipped_done` and `skipped_locked`; `skipped_not_due` is not counted (24 a day).
