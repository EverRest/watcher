# Research: Fleet Mode and Cross-Service Correlation

Only decisions with more than one reasonable design. Facts are from the code as of 2026-10-24.

## R1. What a "fleet" is, and what stays out of it

- **Decision**: a thin runner over `Watcher`. Per entry: its own `WatchConfig`, `LogSource`, `Notifier`, gate, lock, run
  record, snapshot, mute list, rules. Shared: one `StateStore` and one `Analyzer` (so one credential). The unit of work
  is still one service per `Watcher::run()`.
- **Alternative**: make `Watcher` itself multi-service. Rejected: 1,000 lines, a decomposition is planned, and every
  existing test assumes one service.
- **Consequence**: shared state means the entries' `state` sections must be identical after merge; different
  credentials per service are out of scope (spec assumption), so `analysis` credentials must match. Both are checked at load.

## R2. Shared budget key

- **Decision**: the fleet counters are an ordinary `Spend` and `BudgetAlerts` whose service component is
  `fleet/{name}` (`Keys` URL-encodes it to `fleet%2F{name}`). No new key kind, no new `Keys` method, TTLs unchanged.
- **Collision check**: a service literally named `fleet/x` would encode to the same `fleet%2Fx`. Resolved:
  `FleetConfig` rejects service names containing `/` at fleet load (single-service configs are unchanged). Cheaper
  than a new key kind.
- **Alternative**: `Keys::fleetSpend(...)`. Rejected: duplicates `Spend` for one prefix.

## R3. Order of services

- **Decision**: sort by `priority` (higher first, default 0), then name (FR-005); then a stable pass that moves each
  service after the services it depends on **when those are in the fleet** (a dependency is "in the fleet" when its
  name equals a service name or appears in that entry's `provides`). A cycle is broken by keeping the sorted order
  for the members of the cycle.
- **Why**: a consumer's group can only be explained by an origin whose groups the registry already holds. Spec FR-005
  alone would make correlation depend on alphabetical luck. The same order decides who consumes the fleet cap first.
- **Spec drift**: FR-005 says "priority, then name". Dependencies-first is a refinement; **owner to confirm**. Without
  it, `priority` is the only way to get origins first (operator burden).

## R4. Correlation: where, with what, with a model or not

- **Decision**: `Correlator::correlate(list<ServiceGroups>)` is pure. A consumer group `C` of service A is linked to
  an origin group `O` of service B when all hold: (a) A declares dependency name `d` and B provides `d`
  (`provides`, default `[service]`); (b) `C->template` contains `<URL:d>` or `<HOST:d>` (the masked form from
  `Normalizer`); (c) `O` is `New` or `Grown`, `O->level` is at least `selection.min_level` of B, and
  `O->firstSeen..lastSeen` overlaps `C->firstSeen..lastSeen` (widened by `correlation.tolerance`, default `PT0S`).
  Cycles: when A and B link both ways, the group with the earlier `firstSeen` is the origin; equal -> the service
  that sorts first.
- **Inputs**: `RedactedGroup` only. It is the type already produced for the notifier/AI path, so nothing raw can
  enter by construction (FR-013).
- **Model**: none in v1. Wording is a fixed template; the link is labelled a hypothesis (Principle IV). SC-006 is
  met trivially (0 calls).
- **Alternative**: ask a model to word it. Rejected for now: one more failure mode and prompt to test, for a sentence a
  template writes; `correlation.word_with_model` is the named upgrade, not built.

## R5. Consumer findings stay; the correlated message is additive (owner decision, 2026-10-24)

- **Decision**: nothing is withheld. A consumer's own finding stays in its digest and, when the seam linked its group to
  an origin group, carries one annotation line: `likely caused by the <origin service> finding <short fingerprint> (hypothesis)`.
  After the last service the fleet also posts one `CorrelatedFinding` (to the fleet default destination) naming the
  origin finding and listing the consumers with counts. So the Reader gets N+1 messages in per-service mode, never
  fewer findings than without correlation.
- **Why**: no finding may be lost if the correlated post fails. That failure is loud (host log, flag in `FleetResult`,
  exit 1) but removes nothing from any digest. No `reported:` markers are written by the fleet.
- **Origin back-reference**: the origin's digest is already delivered when consumers run (deferring it would record a
  window as done that was never delivered). The correlated message is the back-reference (FR-012).
- **Rejected**: withholding consumer groups (one message, but a failed post loses findings); posting inside the hook per
  consumer (no aggregation of consumers).

## R6. The `Watcher.php` seam

- **Decision**: constructor gets one optional last argument `?FleetSeam $fleet = null`; `fromConfig` is unchanged.
  Edits inside `locked()`, all no-ops when `$this->fleet === null`:
  1. Right after the existing redaction loop (`$redactedBy[...] = $redactor->redact(...)`, not moved), call
     `$annotations = $this->fleet?->reduced($service, array_values($redactedBy)) ?? []`, a map
     `fingerprint => annotation text`. The groups and candidates are not changed.
  2. `$annotations` is passed to `DigestData` (new optional last argument) and `DigestRenderer` appends the line to
     the matching finding. `DigestData`/`DigestRenderer` change, `Watcher` only passes the map.
  3. `$triageOnly = ... || $this->fleet?->exhaustedCap() !== null`, `metered()` books to `$this->fleet?->book()`, and
     the budget-exhausted notice passes the cap name (`service` or `fleet`).
- **Nothing else** moves. No new behaviour class lives in `Watcher.php`; a decomposition can lift these places unchanged.
- **Why a class, not an interface**: one implementation, one caller (Principle VIII).

## R7. Failure, time and exit code

- **Decision**: each service runs in `try { } catch (Throwable)`; a throw becomes a `failed` entry with
  `internal error (Class)` (class name only, Principle I). `InvalidConfig` at load rejects the whole fleet, exit 2.
  Exit code: `2` invalid definition/usage; else `1` if any service `failed` **or was skipped for time**; else `0`.
  "Worst" exit code of the entries and the fleet-level failures.
- **Time budget**: `time_budget` (ISO duration, default none). Checked before starting each service; remaining services
  are `skipped_time` (explicit, not silent). A service already running is bounded by its own timeouts (lock_ttl/HTTP).
- **Not due** is not skipped-for-time: `skipped_not_due` stays exit 0.

## R8. Per-service vs combined digest

- **Decision**: per-service is the default and needs nothing: each entry has its own `notify`, with the fleet
  `defaults.notify` merged under it at load. Combined mode (`digest = combined`) gives each `Watcher` a
  `CollectingNotifier` that forwards **notices** immediately (budget, baseline, failure: they name the service
  already) and holds only messages sent to the service's digest destination. After the loop `CombinedDigest` builds
  one `Message` and sends it to `notify.digest.webhook` of the fleet defaults.
- **Size**: message is capped like `DigestRenderer` (`SlackWebhookNotifier::MAX_TEXT` 4000 chars, 50 blocks). Services
  with nothing to report collapse to one line; if still over the cap, services with the fewest findings collapse to
  "N more services: see their digests". Never split into two messages (SC-005). Needs the per-service message
  `sections` (header/severity/finding) to be reused unchanged.
- **Ceiling**: same as R5 (record written before the fleet post).

## R9. Hosting, heartbeat and status

- **Decision**: the fleet records a heartbeat through the existing `Heartbeat` with service component `fleet/{name}`
  (so `hb:` TTL, stale rule and weekly counters are reused). `fleet:status` prints the fleet record and every
  service's record (existing `Heartbeat::status`), exit code = worst. Docs say a fleet hosted inside a watched
  service dies with it and recommend a dedicated job.
- **Alternative**: only per-service heartbeats. Rejected: when the fleet never starts, no service record ages
  fast enough to distinguish "fleet dead" from "services not due".

## R10. Config shape and loading

- **Decision**: a PHP file returning `['fleet' => name, 'services' => [...], ...]` (contracts/config.md) or a
  directory of such per-service files (each returns one service config; the file name is not the service name).
  Entry keys `priority` and `provides` are stripped by `FleetConfig`; everything else goes to
  `WatchConfig::fromArray` after `array_replace_recursive($defaults, $entry)`. Unknown fleet keys are rejected.
- **Detection**: CLI `fleet:*` takes a fleet file or directory; `run`/`check`/`status` keep taking a single config.
  A `services` key in a `run` config is an "unknown configuration key" as today (no ambiguity).
- **Alternative**: infer fleet from `run --config`. Rejected: it would change `WatchConfig` and every error message.
