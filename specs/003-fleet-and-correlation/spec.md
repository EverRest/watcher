# Feature Specification: Fleet Mode and Cross-Service Correlation

**Feature Branch**: `003-fleet-and-correlation`

**Created**: 2026-10-23

**Status**: Draft

**Input**: User description: "Fleet mode and cross-service correlation for everrest/watcher: one process watches several services (each with its own config) with shared state and a shared budget, so services that have no scheduler of their own can be watched from one place; and when errors in a service coincide with errors in a service it depends on, one correlated finding names the likely upstream cause instead of several separate findings. Source: docs/audit-2026-10-23.md, features 1 and 2, and the rollout survey of the three SEQ services."

## Overview

The first release watches one service per process. The system it will first watch is three services: an API with a
scheduler and two front-facing gateways that have **no** command line and **no** scheduler, and that call the API for
almost every request. Three separate jobs would mean three digests, three budgets and three places to run, and a single
upstream incident would appear as three unrelated problems, each described without the others. This feature lets one job
watch several services and lets a finding say "this started upstream".

**Actors**

- **Operator** — owns the watcher job for a group of services.
- **Reader** — reads the Slack output.
- **Service owner** — owns one of the watched services and may want only their own digest.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Watch several services from one place (Priority: P1)

The Operator lists several services in one fleet definition, each with its own log selection, dependencies, mute list,
rules and service context. One scheduled run processes them all and a failure in one never stops the others.

**Why this priority**: it is the only way to watch services that cannot host the package themselves, and it halves the
operational surface for the ones that can.

**Independent Test**: a fleet of three fake services where the second fails; the first and third report normally, the
failure is reported for the second, and the overall exit code reflects it.

**Acceptance Scenarios**:

1. **Given** a fleet of three services, **When** the run executes, **Then** each service is processed with its own
   settings and produces its own result (reported, quiet, baselined, skipped, failed).
2. **Given** one service's log source is unreachable, **When** the fleet runs, **Then** that service ends as failed with
   its own notice, the others complete normally, and the overall exit code is non-zero.
3. **Given** services with different run times, **When** triggers arrive hourly, **Then** each service runs only in its
   own due hour, exactly as when run alone.
4. **Given** the same fleet is triggered twice in the same hour, **When** the second trigger runs, **Then** every service
   is skipped as already done and nothing is posted twice.
5. **Given** a service name that appears twice, or two services that would share state keys, **When** the fleet is
   loaded, **Then** it is rejected before any call, naming both entries.
6. **Given** a fleet with one invalid service entry, **When** the fleet is loaded, **Then** the whole fleet is rejected
   before any external call, naming the entry and the key (a half-valid fleet is not run silently).

---

### User Story 2 - One budget and one destination policy for the fleet (Priority: P1)

The Operator sets a daily spending cap and alert thresholds for the whole fleet as well as optional caps per service, and
chooses whether each service posts its own digest or the fleet posts one combined digest.

**Why this priority**: model spend multiplies with the number of services; one runaway service must not exhaust the
budget of the others, and the whole fleet must have a hard ceiling.

**Independent Test**: fleet of three with a tiny fleet cap; the third service runs out of budget and does triage only
while the first two are unaffected; thresholds fire once per day for the fleet total.

**Acceptance Scenarios**:

1. **Given** a fleet cap and per-service caps, **When** spend is recorded, **Then** a service stops deep analysis when
   either its own cap or the fleet cap is reached, with a notice that names which cap.
2. **Given** the fleet total crosses 25, 50, 75 or 90 percent of the fleet cap, **When** a run ends, **Then** one notice
   per threshold per day is sent for the fleet total, in addition to per-service thresholds.
3. **Given** per-service digests, **When** the fleet runs, **Then** each service posts to its own configured
   destination, or to the fleet's default destination if it has none.
4. **Given** combined mode, **When** the fleet runs, **Then** one message lists each service with its findings under a
   heading, and services with nothing to report are collapsed into one line; a fleet where nothing is reported posts
   nothing.
5. **Given** the services run in a fixed order, **When** the fleet cap is nearly spent, **Then** the order in which
   services consume it is deterministic and documented (by configured priority, then name).

---

### User Story 3 - A finding that names its upstream cause (Priority: P2)

When a service shows errors that mention a service it depends on, and that dependency shows new or grown errors in the
same window, the Reader receives one finding that says so and links the two, instead of two findings that look
unrelated.

**Why this priority**: in the target system the gateways forward most failures from the API; reading three digests to
discover one cause is the exact manual work the package exists to remove.

**Independent Test**: gateway errors referencing the API plus API errors with a new group in the same window produce one
correlated finding naming the API group; remove the API errors and the gateway finding stays an ordinary one.

**Acceptance Scenarios**:

1. **Given** service A declares B as a dependency, A has candidate groups referencing B, and B has a new or grown error
   group in the same window, **When** the run completes, **Then** A's finding names B and B's group as the probable
   origin, marked as a hypothesis, and B's own finding refers back to the consumers it affects.
2. **Given** several consumers of the same dependency are affected, **When** the fleet digest is built, **Then** the
   dependency's finding appears once and lists the affected consumers with counts.
3. **Given** a dependency shows errors but no consumer references it, **When** the run completes, **Then** no
   correlation is claimed.
4. **Given** the dependency is not part of the fleet (not watched), **When** a consumer references it, **Then** the
   existing dependency context applies and no correlation to a finding is claimed.
5. **Given** correlation is evaluated, **When** its inputs are examined, **Then** only masked, already-redacted data
   crosses between services; no raw log text, field value or secret is shared.
6. **Given** correlation changes the number of model calls, **When** a run completes, **Then** it makes no extra calls
   when correlation is decided by counts alone; a model is asked only to word the combined finding.

---

### User Story 4 - Watch services that cannot host the package (Priority: P2)

The Operator watches services whose own images have no command line or scheduler by defining them in the fleet and
running the fleet from another place (another service's scheduler, or one standalone job).

**Why this priority**: two of the first three services are in this situation; without this they stay unwatched.

**Independent Test**: a fleet definition for services that exist only as log streams; a run produces digests for them
without any package code running inside them.

**Acceptance Scenarios**:

1. **Given** a service defined only by its log selection and settings, **When** the fleet runs elsewhere, **Then** it is
   watched exactly like a service with the package installed, with no change to the watched service.
2. **Given** the fleet runs from inside one of the watched services, **When** that service is down, **Then** the fleet
   is also down and the heartbeat of the fleet (not of that service) is the signal; documentation says so and recommends
   a separate job for production.
3. **Given** the watched services run different releases, **When** the fleet runs, **Then** each service's release is
   resolved from its own settings.

### Edge Cases

- A service in the fleet has no logs in the window: quiet, not failed.
- A dependency cycle among fleet services (A depends on B, B on A): correlation picks the group that started earlier
  and never produces a loop.
- Two services' errors reference each other's hosts: one correlated finding per origin, not per pair.
- The fleet takes longer than the lock lifetime: each service holds its own lock; the fleet itself needs no global lock
  but a second concurrent fleet run skips services whose lock is held.
- A service is removed from the fleet: its old state expires by retention; nothing is deleted.
- The fleet definition is large (dozens of services): total wall time is bounded and services that do not fit are
  reported as skipped for time, not silently dropped.

## Requirements *(mandatory)*

### Functional Requirements

**Fleet**

- **FR-001**: The package MUST accept a fleet definition: a list of service entries, each a complete service
  configuration as defined for a single service, plus fleet-level settings (default destination, shared state,
  fleet budget, mode of digest).
- **FR-002**: A fleet run MUST process each due service independently, with that service's own schedule gate, lock, run
  record, snapshot, mute list, rules, dependencies and context, exactly as a single-service run.
- **FR-003**: A failure of one service MUST NOT stop, delay beyond its own timeout, or alter the result of another; the
  fleet's exit code MUST be non-zero when any service failed or the definition was invalid.
- **FR-004**: The fleet definition MUST be validated as a whole before any external call; duplicate service names,
  colliding state prefixes and invalid entries MUST be reported with the entry and key, and nothing MUST run.
- **FR-005**: Services MUST be processed in a deterministic order (dependencies that are in the fleet first, then
  configured priority, then name; a dependency cycle keeps the priority-then-name order) and the run MUST stop
  starting new services when a configurable total time budget is exhausted, reporting the remaining ones as skipped for
  time.
- **FR-006**: The single-service entry points MUST keep working unchanged; a fleet of one is equivalent to a single
  service.

**Budget and destinations**

- **FR-007**: A fleet-level daily cost cap and optional token cap MUST be enforced across all services in addition to
  per-service caps; reaching either cap MUST put the affected service in triage-only mode with a notice naming the cap.
- **FR-008**: Threshold notices (25/50/75/90 percent) MUST also be sent for the fleet total, at most once per threshold
  per day.
- **FR-009**: Each service MUST post to its own destination, or to the fleet default; a combined mode MUST post one
  message grouping services, collapsing services with nothing to report, and posting nothing if nothing is reported.
- **FR-010**: Failure notices, budget notices and baseline notices MUST name the service they concern.

**Correlation**

- **FR-011**: The package MUST be able to mark a service group as a probable origin of a consumer group when (a) the
  consumer declares the origin service as a dependency, (b) the consumer group's masked template references that
  dependency's host, and (c) the origin service has a new or grown error group in the same window.
- **FR-012**: A correlated finding MUST present the link as a hypothesis, name both services, and list affected
  consumers with their counts. The correlated message is additive: it names the origin finding and lists the consumers,
  and does not edit the origin's already-delivered digest; each consumer finding stays in its own digest with an
  annotation naming the likely origin finding (consumer findings are annotated, never withheld).
- **FR-013**: Correlation MUST use only data already masked and redacted for the single-service pipeline; nothing
  else MUST pass between services.
- **FR-014**: Correlation MUST be decided from counts, hosts and statuses in code; a model MAY be used only to word the
  combined finding, within the existing token budgets and caps.
- **FR-015**: Cycles among dependencies MUST NOT cause loops or duplicate findings; the earlier-starting group is the
  origin.
- **FR-016**: When the origin service is not part of the fleet, the existing dependency context MUST be used and no
  correlation to a finding MUST be claimed.

**Hosting**

- **FR-017**: A service MUST be watchable without any package code running inside it, using only its log-source selection
  and the fleet entry.
- **FR-018**: Documentation MUST state that a fleet hosted inside a watched service shares that service's failures and
  MUST recommend a dedicated job, and MUST describe the heartbeat of the fleet (see the operability tools).

### Key Entities

- **Fleet Definition** — the list of service entries and fleet-level settings.
- **Service Entry** — one complete service configuration plus an optional priority.
- **Fleet Budget** — daily cost and token counters and thresholds spanning all services.
- **Correlation Link** — a probable origin group in one service for a consumer group in another, with counts and the
  window.
- **Correlated Finding** — a finding that names an origin and lists affected consumers.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A fleet of three services runs from one scheduled job and produces the same per-service results as three
  single-service jobs would, in 100% of the tested scenarios.
- **SC-002**: One failing service never changes the result of another in a fleet (0 cross-contamination in the failure
  test matrix).
- **SC-003**: The fleet's total daily model spend never exceeds the fleet cap by more than one deep-analysis step.
- **SC-004**: In the correlation test matrix, 100% of upstream incidents with affected consumers produce exactly one
  origin finding that lists the consumers, and 0 correlations are claimed when no consumer references the origin.
- **SC-005**: A combined fleet digest has at most one message per run and stays within the chat platform's size limits
  for 20 services with findings (overflow is collapsed, never split).
- **SC-006**: Correlation adds zero model calls when it can be decided from counts, and no more than one extra call per
  correlated finding otherwise.
- **SC-007**: No personal value, secret or log text crosses between services in any privacy test (0 occurrences).

## Assumptions

- Services in one fleet share one state store and one Anthropic credential; separate credentials per service are out of
  scope for the first version of this feature.
- The fleet runs on the existing hourly trigger; no new scheduler is introduced.
- "Dependency" and "host referenced in a template" keep the meaning they have in the first release (a declared name
  matched against masked hosts).
- Correlation covers one hop (consumer to its declared dependency); multi-hop chains are reported as a chain of single
  hops and may be revisited later.
- Per-service digests are the default; the combined digest is opt-in because teams usually want their own channel.
- Fleet hosting inside a watched service is allowed but discouraged.
