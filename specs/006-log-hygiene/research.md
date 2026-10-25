# Research: Log Hygiene Reports

Decisions taken while planning. Step 0 (design questions) was approved by the owner; where the spec left a choice, the cheapest option that keeps Principles I and VIII is recorded here with its ceiling.

## R1. A separate pass, not a tap in the daily run

**Decision**: `watcher hygiene` reads the window itself (`LogSource::lines`) and reduces it with `Reducer`; `Watcher::locked()` is not touched.
**Why**: the spec (FR-004) says "during the existing read". That is true of the data (same source, same masking, same grouping) but a tap inside `locked()` would collide with the decomposition of that method under way in another lane, and it would put hygiene cost on the run's critical path (SC-005). A separate pass costs one extra read per scheduled hygiene run and nothing on the daily run.
**Ceiling**: two reads of the same window on days both run. Acceptable; hygiene is weekly or on demand.
**Rejected**: hook in `observe()` (couples to the lane being rewritten); persisting raw events (Principle I).

## R2. Categories are placeholder families of the existing Normalizer

**Decision**: a line's categories are read off its **normalised** text: `<EMAIL>` e-mail; `<TOKEN>` token; `<IP>` address (IP address; a postal address is not detectable by pattern and is not claimed); `<PAN>` card-like; `<HOST>` and `<URL>` host name; `<USER>`, `<PATH>` and each `redaction.extra_patterns` placeholder `other`; phone-like is the one category the Normalizer has no placeholder for: `+` followed by nine or more digits, spaces, dashes or brackets, tested on the text in memory and reduced to a yes/no (a bare `NNN-NN-NNNN` is not counted, it is indistinguishable from a date). `<PATH>` and `<ID>` are no category: file paths and opaque ids are everywhere in logs and are not personal data by themselves. `<ID>`, `<N>`, `<STR>` are not personal-data categories and are not counted.
**Why**: "no new detection of personal data is introduced beyond variable free text" (Assumptions). Counting placeholders reuses the table, so the report cannot disagree with what is masked, and the scanner never holds the matched value (only `normalize()` output is inspected, and only counts survive).
**Ceiling**: phone-like is a heuristic; a phone written without a plus sign is not counted.

## R3. Fields are scanned, keys are not

**Decision**: every string field value of an event is normalised and counted; field **names** are never interpreted (`email`, `name` as keys are not evidence). A call site that writes personal data only inside context (the audit JSON) is therefore found.
**Why**: the survey's worst cases sit in context (`{"Email":…,"IP":…}`). A key-name heuristic would be new detection.
**Bound**: at most 40 fields per event, each cut by the Normalizer's 4096-character cap.

## R4. Call-site identity

**Decision**, in order: context keys `file` + `line`; else the first stack frame; else the group's fingerprint with its (possibly withheld) template. File/line and frame go through `Redactor` (frame rewriting by `repo.path_map`, known-directory cut, digits masked); an identifier it drops falls through to the next rule. Template ids are `tpl:<fingerprint>` plus the template text only when `Redactor` returned one.
**Why**: matches FR-001 and the edge cases ("matched by template and file so a moved line is not new" is satisfied by keying the registry on `file` + template-hash when both exist; see data-model).

## R5. Variable free text: distinct masked messages per template

**Decision**: `TemplateMiner` counts, per cluster, how many distinct masked messages were folded into it (`msgs`) and exposes the clusters that have a varying position and at least `min_group_events_for_text` events as `variability()` (also on `Reducer`). Listed when the count is at least `hygiene.variable_distinct` (default 20); positions = number of `<*>`; the template goes through `Redactor::redactText` before it is reported.
**Why not count fingerprints under a template**: the fingerprint is `level|type|frame|token count|first two tokens`, so all variants of one sentence share a fingerprint; a count of fingerprints is always 1. The miner is the only place that knows.
**Cost**: one integer per cluster and a 12-line hunk in `TemplateMiner` (plus a getter on `Reducer`); no change to clustering or to any template.
**Ceiling**: when several positions vary, the count is an upper bound on the distinct values at any one position, so the field is named `distinct_messages`.

## R6. Shape measures

- **Volume share**: events per site / events read. A site is "noisy" above `hygiene.noisy_share` (default 0.20) at level info or below; above it at any level is reported with its level.
- **Long line**: `strlen(message)` > `hygiene.max_line_length` (default 2000) — the parsed message, not the raw line (the parser keeps the first physical line, `docs/privacy.md`).
- **Trace in message**: message contains a newline, or matches `Stack trace:`, `#\d+ …(…)` or `at x(file:line)`. The parser cuts messages at the first line, so the signal is mainly sources that deliver multi-line message strings.
- **Format mix**: each message is classed `access` (HTTP request line), `json` (starts with `{` or `[`), or `text`; non-dominant classes at or above 1 % are listed with counts.
- **Level/content mismatch**: class `access` at level warning or above.
**Ceiling**: four content classes. New ones are a constant and a test.

## R7. Rule proposals need hygiene's own history

**Decision**: proposals are computed from the per-day aggregate records the hygiene pass stores (family: fingerprint, masked template if allowed, level, count, first/last, attributed tokens), not from the run's snapshots.
**Why**: a snapshot holds counts per fingerprint but no level, so "never severe" (FR-011) cannot be answered from it. FR-016 already asks for these aggregates. Consequence: 14 days of history means 14 days of hygiene passes; the report says how many more are needed.
- **Mute**: seen on at least 80 % of history days, mean daily count at least `hygiene.mute_min_daily` (default 100), max daily ≤ 3 × min daily (stable), **no day at or above `selection.min_level`**, and its template (when allowed) never seen at or above that level under another fingerprint.
- **Watch**: a non-severe family whose first occurrence of the day lies 0..`hygiene.watch_lead` seconds (default 1800) before the first occurrence of a severe family, on at least 3 distinct days and at least half of the days that had a severe family. Needs a template; the rule is `template` regex built from the masked template (`<…>` → `.*`).
- **Fragments**: `['fingerprint' => …, 'until' => null]` for mute; `'name' => ['template' => '/…/']` for watch. Same shapes as `config/watcher.example.php`.
- **Applied**: a fingerprint already in `mute`, or a template rule already equal in `watch_rules`, is not proposed again (status `applied`, not listed).
- **Declined**: stored per proposal id for `hygiene.decline_days` (default 90) by `watcher hygiene --decline=<id>`.

## R8. Replay uses the real MuteList

**Decision**: a mute proposal's effect is computed by building `ProblemGroup`s from the stored family records and filtering them through a real `MuteList` holding the fragment, per day. `candidates_removed` counts days×families removed that had attributed tokens (they were analysed, hence candidates); `tokens_saved` sums their tokens. The proposal's stated saving is the average per day of the same quantity, so SC-003 is a check that the fragment really matches what the evidence says.
**Why**: a fragment that does not match (typo, wrong form) shows `0` before the owner pastes it.

## R9. Cost: run records are the truth, the ledger adds detail

**Decision**: per-day tokens in/out/cached, cost and run count are summed from **run records** (`run:<key>:<windowEnd>`, `usage`, retention 30 days); enumerating the keys uses `RunGate::latest()` and `endBack()`. This is what makes SC-006 ("equals the sum of recorded runs") a theorem. Calls per step and top spenders are not in the run record, so a `LedgerAnalyzer` decorator (only when `hygiene.enabled`) records them per day: counters per step and a bounded family table with tokens attributed evenly across the groups of each request. Without a ledger those fields are `null` and the text says "not recorded".
**Why not extend the run record**: that is a change in `Watcher.php` (other lane). The decorator is wired in `Factory::analyzer` only.
**Spend counters are not used**: they expire after two days.
**Day of a run**: the local date (service timezone) of its window end.
**Spike**: day cost or tokens > `hygiene.spike_factor` (default 2.0) × median of the previous days in the report that have a record; at least one previous day is required. Causes, from stored data: `new_families` and `grown_families` (snapshot of the day vs the previous day, growth by `selection.growth_factor`), `escalations` (ledger calls).

## R10. Delivery

**Decision**: stdout by default; `--notify` posts every non-empty report to the **notice** destination (`config->destination('notice')`; nothing when `notify.notice.enabled=false`), after FR-007 suppression for the privacy report and at most once per `repeat_days` for shape, proposals and cost; a chat notice is built as escaped sections (like the digest's notices), at most 25 sites and 10 templates, and only the sites it shows are marked as sent; `--json` prints one JSON line on stdout and the text on stderr (same convention as `run --json`). Scheduling is the host's: call `watcher hygiene` **daily** (day records are what proposals are computed from; a sampled or missing day is missing history) and add `--notify` as often as you want to hear (once a week is typical). The package has no scheduler of its own and the spec's "if enabled, on a schedule" is met by the flag plus that call.
**Why**: Principle VIII; the host already schedules `watcher run`.

## R11. Flag semantics

`hygiene.enabled` defaults to **false** (spec Assumptions) whereas every entry of `WatchConfig::FLAGS` defaults to true (and tests assert it), so the switch lives on `HygieneSettings` (`$config->hygiene->enabled`), not in `FLAGS`. With it off: privacy, shape and proposal reports are refused with a stderr hint (exit 0); the cost report is always available (from run records); the ledger is not written.

## R12. Sampling

The scan reads at most `hygiene.event_cap` events (default 200 000, the existing `source.event_cap` order of magnitude) with `ReadFilter`; when the source reports truncation the report carries `sampled: true` and the number read. Sites are capped at 500 per day record; overflow is counted as `other_events`.

## Open items

None blocking. Postal addresses and personal names in fixed text are out of reach by design (`docs/privacy.md` limits).
