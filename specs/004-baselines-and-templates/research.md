# Research: Baselines and Templates

Only decisions where more than one design was reasonable. Facts are from the code as of 2026-10-24.

## R1. Finding the earlier snapshots without listing keys

- **Decision**: compute candidate window ends by arithmetic from the current window end and `get` each snapshot key.
  Rolling: the window length back, 1..N times. Weekday: 7k days back at the same local `run_at`, k = 1..N. A missing
  snapshot is skipped.
- **Why**: `StateStore` has `get/put/acquire/release/increment` only; adding `list`/`delete` breaks the four-store
  contract suite (the same reason spec 002 R3 rejected `delete`). Snapshot keys are `snap:<svc>:<window end UTC>` and
  runs happen once per local day at `run_at`, so the ends are predictable. `RunGate::runAtOn()` already resolves DST
  gaps and repeats; exposing it as `endBack(end, days)` makes weekday candidates exact across clock changes.
- **Alternatives**: (a) a `hist:<svc>` index document of recent ends: a second source of truth that can disagree with
  the snapshots and needs its own write ordering; (b) a back-link `prev` stored in each snapshot (chain walk): up to
  28 sequential reads for weekday mode and a broken chain on any expired link.
- **Consequence**: rolling/weekday require whole-day windows with one run per day (validated, R2). Previous mode is
  unchanged and keeps using the in-flight pointer.

## R2. Windows that are not whole days

- **Decision**: `baseline.mode` other than `previous` is rejected at start unless `schedule.window` is a whole number
  of days. Previous mode keeps working for any window.
- **Why**: for `PT6H` windows the existing previous lookup (`end == start of this window`) already finds nothing because
  runs are daily; extending the arithmetic to that case would be new behaviour nobody asked for.

## R3. Do fingerprints change? (FR-012)

- **Decision**: no. `Fingerprinter` is untouched.
- **Why**: identity is `sha256(level|errorType|frame0|token count|first two words)`, independent of the template and of
  volume. The audit's complaint (fully masked templates) comes from the *cluster* that supplies the template, not from
  the fingerprint. Changing identity would reset every `reported:` / `verdict:` / snapshot comparison once for no gain
  in template quality, and the spec's own edge case ("a group splits into two after an upgrade") cannot occur.
- **Cost of this choice**: two fingerprints that really are one problem (the first two words differ) stay separate
  groups. Merging them is a different feature (identity by similarity) with a migration; not proposed here.
- **Migration/one-time cost**: none for fingerprints and comparisons. Templates sent to the model change on the first run
  after upgrade; `VerdictCache` is keyed by fingerprint, so cached verdicts stay valid and no extra model calls result.
  T019 pins fingerprints with golden values so a later change cannot happen silently.

## R4. What "grown" means per mode, zeros, and the `Expected` status

- **Decision**: per-hour counts. Rolling value = mean over the used snapshots, weekday value = median; a snapshot that
  exists without the fingerprint contributes 0. `New` = in none of the used snapshots. `Grown` = `count >= growth_factor
  x value`. If the value is 0 yet the fingerprint appears in some used snapshot (a problem that happens on a rare busy
  day), the value is the maximum of the set; if the group is not `Grown`, status is `Expected`.
- **Why**: spec AC US2-4 requires such a group to "not be reported as new unless it grew" and to say it is expected for
  this weekday. A median of 0 would make `count >= factor x 0` always true. Mean and median are the spec's words.
- **Alternatives**: reuse `Unchanged` and say "expected" only in the digest: rejected because the digest line is
  derived from the status and the model sees `st`. A new enum value is fixed, short and safe to send.
- **Consequence**: `GroupStatus::Expected`, `GroupStatusLabel::Expected`, `RedactedGroup` mapping, prompt fixtures. In
  previous mode `Expected` never occurs, so default behaviour is byte-identical.

## R5. Per-hour normalisation

- **Decision**: compare `count / window hours` with `snapshot count / snapshot hours`, using stored `start`/`end`; when a
  snapshot has no `start`, assume the current length. The scaled previous count is shown rounded in "↑ from N".
- **Why**: FR-002 and the edge case (operator changes the window). A DST day (23 h or 25 h) changes counts by 4%,
  harmless against factor 3, but scaling keeps the rule uniform.

## R6. Exceptions and reset: config, not CLI and not state (FR-007)

- **Decision**: `baseline.exclude` = list of local dates (`YYYY-MM-DD`, the date of the window end in
  `schedule.timezone`); `baseline.since` = local date, windows ending before it are ignored.
- **Why**: nothing to store, nothing to race with the run, reviewable in git, no new command or write path
  (Principle VIII, II). The digest prints the window ends it used, so the operator can read the date to exclude.
- **Cost**: a config deploy per exclusion. **Owner decision O3** if a `watcher history` command is wanted instead.

## R7. Grouping algorithm

- **Decision**: two-phase, deterministic leader clustering over *distinct masked messages*, not streaming Drain.
  Phase 1 (stream): per bucket `(level, errorType, token count, frame0)` keep `message text => {tokens, count, fp}`.
  Phase 2 (after the stream, lazily on first `template()`): sort by `(count desc, text asc)`, assign each message to the
  most similar existing cluster at or above the threshold, else found a cluster. Template = leader tokens with `<*>`
  where any member differs. Group template = template of the fingerprint's largest cluster.
- **Why**: online Drain's result depends on arrival order (the first-seen message becomes the template and early merges
  are irreversible). Events arrive in source order, which differs between Loki and Datadog and between retries.
  Distinct messages with counts are a multiset, so sorting them removes order dependence (FR-010, US3-5). Memory is per
  distinct message, which is bounded by `grouping.message_cap`, instead of per event.
- **Alternatives**: online Drain with a parse tree: faster at 100k+ distinct messages but order-dependent; rejected for
  the stated requirement. MinHash/LSH: more code and a probabilistic result.

## R8. Similarity measure and threshold

- **Decision**: `sim(leader, message) = positions where both tokens are equal and literal / literal tokens in the
  leader`. A literal token is one that is not a placeholder (`<NUM>`, `<ID>`, `<EMAIL>`, `<URL...>`, ...).
  Two messages with no literal tokens are identical in shape (sim 1). Default threshold `0.5`.
- **Why**: counting placeholder equality would merge "`<ID> <NUM> failed`" with "`<ID> <NUM> succeeded`" (2 of 3).
  Same bucket means same length, so no length penalty is needed. 0.5 sits above Drain's usual 0.4 because the bucket is
  already narrow; T015/T018 tune it on the fixtures and the default is a decision for the implementer to confirm there.
- **Languages without spaces**: a message is one token; messages group only when equal (today's behaviour). Character
  level similarity is not added (YAGNI); this satisfies "never fails".

## R9. Backend patterns (US4) are deferred

- **Facts**: Loki's `/loki/api/v1/patterns` exists only with the pattern ingester enabled (Loki 3.x); no Datadog
  equivalent was found in the public logs API; the spec itself says it was not verified against a live system.
- **Decision**: do not build in this pass. If built, the hints may only influence *which cluster a message joins*, never
  supply template text: output text stays derived from members of the cluster, so backend text cannot reach the model or
  a notifier at all, which makes FR-014's "masked and scanned" trivially true. It would be an optional interface
  implemented by `LokiSource` only. **Owner decision O5.**
- **Honest value**: with R7 the pattern hints do not reduce reading cost either (events must still be read to be
  counted). The spec's stated benefit ("lower the cost of reading very large windows") would need pattern *counts*
  instead of lines, which is a different pipeline.

## R10. Bounds and truncation (FR-010, SC-005)

- **Decision**: `grouping.message_cap` (default 50,000 distinct messages) and a deterministic comparison budget of
  `400 x message_cap`. Past the cap, new distinct messages are counted (`droppedMessages`) and not stored; when the
  budget runs out the remaining unclustered messages are withheld. No wall-clock check, so tests are reproducible.
  Digest line says grouping was truncated.
- **Fail closed**: withheld means template null, as for a small cluster.
- **SC-005**: 100,000 distinct is above the default cap, so the full-size test expects truncation or raises the cap to
  100,000 and expects completion in 60 s and 256 MB; a scaled-down version runs in the default suite.

## R11. Model-provider retry (FR-004, second half)

- **Decision**: not implemented; owner decision O1. The SDK retry is off on purpose (`maxRetries => 0`): two attempts
  per call, a 180 s timeout and a 900 s lock mean a retry can outlive the lock (review W3). With the pointer work, a
  failed day no longer costs the comparison; the next scheduled trigger is the retry.

## Open decisions for the owner

| # | Question | Default taken |
|---|---|---|
| O1 | Drop the model-provider part of FR-004 from this spec (retry stays off; the next trigger is the retry), or add a retry bounded by a run time budget below `lock_ttl` (needs lock renewal first, review W3)? | Dropped for now; the spec should be amended. |
| O2 | Keep `Fingerprinter` unchanged (R3)? Changing identity to similarity would merge more groups but resets every comparison once. | Unchanged. |
| O3 | Exceptions and reset by config keys (R6), or a `watcher history` command that writes state? | Config. |
| O4 | New outgoing status value `expected` (R4). It is a fixed enum label sent to the model. | Accept. |
| O5 | Build US4 (Loki patterns) at all, given R9? | Deferred; one placeholder task. |
| O6 | Weekday mode with fewer than 3 same-weekday snapshots: fall back to the previous window (taken), or to rolling over what exists? | Previous window. |
