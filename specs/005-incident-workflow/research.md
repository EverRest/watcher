# Research: Incident Workflow (v1)

Only decisions where more than one design was reasonable. Facts are from the code as of 2026-10-24 (`src/Select/MuteList.php`,
`src/Analyze/VerdictCache.php`, `src/Notify/*`, `src/Watcher.php`, `src/State/Keys.php`). Decisions marked **(assumed)** are
open for the owner (spec.md O1-O5); planning proceeds on them.

## R1. Why v1 fits Principle II without an amendment

- **Decision**: v1 outputs are (a) messages to configured notifier destinations (known line, drafts, route messages, weekly
  report) and (b) the package's own state (reference counts, delivered markers) and (c) a read of the package's own
  spec-013 output file. Principle II allows "posting a message to its configured notifier and writing its own state".
  Nothing is received from the network, nothing is written to a tracker.
- **Why a route or drafts destination is still "its configured notifier"**: it is a `Destination` of an existing driver,
  validated by the same code as `notify.digest` (spec 011). No new egress host is introduced.
- **Rejected**: reading a tracker "read-only". Principle II's read-only clause names *log source* credentials; a tracker
  token is a new class of credential (D2).

## R2. Where the "count at link time" comes from

- **Decision (assumed, O5)**: the package records the count the first time it sees an open entry's fingerprint in the
  retention period (`known:<service>:<fp>` = `{ref, seen}`), renews the expiry on each sighting, and compares with
  `selection.growth_factor * ref`. After a `known_grown` finding is delivered, `ref` becomes the current count.
- **Why**: configuration is static text; the Operator cannot know the count at the time they link. Reusing
  `selection.growth_factor` adds no key and matches how `reported` markers work (`Keys::reported`, `atCount`).
- **Alternative**: a `count` field in each entry. Rejected: asks the Operator for a number they do not have, and goes stale.
- **Alternative**: compare with the previous snapshot only. Rejected: a slow climb never crosses `growth_factor` between two
  consecutive windows, so the problem would stay "known" forever while growing tenfold over a week.

## R3. Known group versus the selector and the verdict cache

- **Decision**: partition after the mute filter and before candidate selection in `Watcher::attempt` (the place where mute
  already removes groups). Open, unexpired, below-threshold groups leave the candidate list and are rendered as a compact
  line; they stay in the snapshot (as muted groups do). `known_grown` is a new `CandidateReason` so the selector keeps the
  group even when its snapshot trend is `unchanged`.
- **Why not reuse `VerdictCache` `known` verdicts**: those are model verdicts, deliberately never applied to error-or-higher
  groups because log text can steer the model (`mustNotBeSilenced`). A known-issue entry is an operator decision from
  configuration, the same trust as `mute`, so it MAY silence any level, but it is shown (never silent) with level and count.
- **Multi-fingerprint finding**: suppression is per group before analysis; a finding spans groups only after analysis, so
  the rule "known only if every fingerprint is known" applies to the *label* on a finding, not to suppression.

## R4. Resolved entries and "returns to active"

- **Decision**: `status: resolved` in configuration never suppresses. The package does not flip it (it cannot edit
  configuration). The finding says `regression of ABC-123 (marked resolved)` on every run in which the group is present,
  which is correct until the Operator reopens or removes the entry.
- **Alternative**: store an "active" override in state. Rejected: two sources of truth, and the first draft's reason for it
  (a button) is deferred.

## R5. Drafts: where they go and how they look

- **Decision (assumed, O2)**: one message per finding, sent after the digest to `drafts.destination` (default: notice
  destination). Separate messages can each be copied whole; a block inside the digest cannot be cut off by the digest's
  size cap and splitting (spec 011 splits at section boundaries and numbers parts).
- **Decision (assumed, O4)**: a new section kind `draft`, rendered as a code block by `SlackText`, `TelegramText`
  (`<pre>` with HTML escaping) and `DiscordText` (fenced, with the defang of spec 011 FR-007 and the fence character
  neutralised). Reusing `notice` would render labels and values with channel markup escapes that corrupt a paste.
  Cost: three formatter changes (hot files), covered by the spec 011 injection corpus.
- **Why no model call**: all content exists in the finding; the draft is a template (Principle IV, V).
- **Why no Operator template (D5)**: no stated need; a template with placeholders is a second rendering path to secure.
- **Why skip findings with a ticket**: a `known_grown` finding gets an "Update on ABC-123" draft, so the Reader pastes a
  comment, not a duplicate ticket; below-threshold known groups produce no finding and so no draft.

## R6. Routing semantics

- **Decision (assumed, O3)**: first matching route wins, one destination per finding, default digest takes the rest. A
  finding appears once (SC-003), the default Reader sees `N finding(s) routed to <name>`.
- **Alternative**: copy to every match. Rejected: duplicates, and the rule "which is the source of truth" becomes unclear.
- **Alternative**: route by service. Rejected: fleet service entries already override `notify` per service (spec 011 US3).
- **Mentions (D4)**: the first draft had configured mentions. Spec 011 FR-006 sets Discord `allowed_mentions.parse: []`
  and defangs `@` on every channel, so a mention would never fire. Not carried into v1.
- **Delivery and retries**: `Keys::delivered` marks the default digest for a window. Each route gets
  `sent:<service>:<windowEnd>:r<i>` (same TTL, `DELIVERED_TTL`). Order: routes first (urgent first), default digest last,
  so the existing "digest delivered" marker and failure notice keep their meaning.
- **Route message**: `DigestRenderer` with a `DigestData` copy that keeps only the routed findings and zero extras. No new
  renderer.

## R7. Weekly report: command or part of `run`

- **Decision (assumed, O1)**: a command `watcher report`, called weekly by the host's scheduler, idempotent per ISO week via
  a marker. `run` is untouched, so the largest hot file (`Watcher.php`) gets no report logic and a malformed event file can
  never fail a run.
- **Alternative**: send from `Watcher::conclude` on the first run of a new ISO week, like `heartbeat.weekly_summary`.
  Rejected: couples a file read to every run and to the lock window; the first run after a weekend would be slower.
- **Source of truth**: spec 013 `file` sink. Not new counters: events already carry run outcome, release, cost, tokens,
  muted count and the top-N groups. Not the state store: it keeps no per-day history beyond `hbweek` counters.
- **What the file cannot say**: `group` events are the top `observe.top_groups` per run (default 20) and carry no source
  name or text by default. So "noisiest sources" becomes "noisiest groups" (fingerprint, level, summed count), and
  "stopped after a release" is "no longer among the top groups after release X" — worded that way in the report.
- **Retention**: the report needs 7 full days plus slack, so `observe.file.retention_days >= 8` is validated when the
  report is enabled.

## R8. Fleet

- `known_issues`, `drafts`, `notify.routes` and `report.weekly` are per-service blocks that a service entry replaces as a
  whole (the `observe` rule of 013). Reason: `FleetConfig` merges defaults with `array_replace_recursive`, which merges
  lists index by index, so a shared route list and a service route list would be mixed. `digest: combined` + routes is rejected. `fleet:report` is a loop over entries, one message per service; no combined report in v1.

## R9. Inventory for the deferred amendment (D1), so it can be argued later

Interactive controls need: a listening surface; a credential with write scope on the chat platform; request
authentication; an allowed-actors list; idempotent state changes; thread ids as new persisted data (Principle I
expiry); and a statement that inbound text is untrusted (it can steer nothing but the package's own state). The suggested
amendment text: in Principle II, add "and receiving authenticated interaction requests that change only the package's own
state". MINOR bump of the constitution, owner approval, then a separate spec.

## R10. Dependencies and order of landing

Known issues, drafts, routes need spec 011 (landed) and spec 012 flag pattern (landed). They touch hot files also edited
by 013 and 014 (`Watcher.php`, `WatchConfig.php`, `Factory.php`, `Notify/*`, `Keys.php`). The weekly report needs 013's file
sink and event schema. Order: known issues -> routes -> drafts -> report, each rebased on the then-current files.
