# Feature Specification: API Context for Findings

**Feature Branch**: `010-api-context`

**Created**: 2026-10-24

**Status**: Draft

**Input**: User description: "API context for everrest/watcher: OpenAPI/Swagger summaries for the endpoints named in a finding (route templates from logs or stack frames) so the model and the digest get one line saying what the endpoint is for. Source is a local OpenAPI file or a URL read-only, cached in state with a TTL; only operationId, summary, tag and the deprecation flag are used (never examples, schemas with data, or descriptions longer than a cap); route matching by method and path template with parameter placeholders after the Normalizer's path masking (`<ID>` against `{id}`); low value per token, so off by default and capped to N endpoints per run, counted in the token budget. Source: docs/roadmap.md item 010."

## Overview

A finding like `POST /orders/<ID>/refund failed: timeout` tells the Reader the route but not what it is for or who calls
it. The service's own OpenAPI document often answers in one line ("Refund an order", tag `payments`, deprecated or not).
Adding that line costs tokens on every run, and the model usually does not need it; so this feature is small, strictly
bounded and **off by default**. When enabled, it reads one API description, matches the routes that appear in selected
groups, and attaches a one-line summary per endpoint, capped per run and counted in the token budget.

**Actors**

- **Operator** — provides the OpenAPI source, enables the feature and sets the cap.
- **Reader** — sees the one-line "what is this endpoint" in the digest.
- **Service owner** — keeps the OpenAPI summaries accurate; they appear in the digest as written.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - One line of meaning for an endpoint in a finding (Priority: P1)

For a selected group whose template or top frames name an HTTP route, the digest shows, under the finding, a short line
such as `POST /orders/{id}/refund — Refund an order [payments] (deprecated)`. The same line is part of the compact model
input for that group.

**Why this priority**: it is the whole feature; without it nothing is delivered.

**Independent Test**: an OpenAPI fixture with three operations and a stream of groups naming those routes with masked
placeholders; each group gets the expected line, and an unmatched route gets none.

**Acceptance Scenarios**:

1. **Given** the feature is enabled and the OpenAPI source is readable, **When** a selected group contains a route with a
   method and a path (for example `POST /orders/<ID>/refund` after masking), **Then** the matching operation's summary,
   first tag, operationId and deprecation flag are attached to the group and shown as one line in the digest.
2. **Given** an operation has no `summary`, **When** the line is built, **Then** the operationId is shown instead; when
   neither exists the endpoint has no line.
3. **Given** a route matches no operation, **When** the group is processed, **Then** nothing is attached and nothing is
   reported as an error.
4. **Given** the feature is disabled (the default), **When** a run happens, **Then** the OpenAPI source is never read, no
   cache entry is written, and the model input is byte-identical to a run without this feature.
5. **Given** more than `api_context.max_endpoints` endpoints (default 5) match across the selected groups, **When** lines
   are attached, **Then** they are attached in candidate priority order up to the cap, the rest are skipped, and the digest
   footer says how many were skipped.

---

### User Story 2 - Routes match despite masking (Priority: P1)

A masked route (`/orders/<ID>/refund`, `/users/<N>`, `/files/<PATH>`) and an OpenAPI template (`/orders/{id}/refund`,
`/users/{userId}`) are recognised as the same endpoint, with method taken into account.

**Why this priority**: the Normalizer has already replaced the real values with placeholders; matching must work on that
form or it will never match.

**Independent Test**: a table of masked routes and OpenAPI templates with expected match, no match and ambiguity outcomes.

**Acceptance Scenarios**:

1. **Given** a masked path segment `<ID>`, `<N>`, `<TOKEN>`, `<EMAIL>` or `<NAME>`, **When** it is compared with an OpenAPI
   template segment `{param}`, **Then** it matches any parameter segment; a literal segment matches only an equal literal
   (case-sensitive).
2. **Given** the OpenAPI template has a base path from `servers[].url` or a configured `base_path`, **When** matching, **Then**
   the base path is stripped from the template side, and from the route side if present.
3. **Given** two operations match the same masked route, **When** one is more specific (more literal segments), **Then** the
   more specific wins; if equal specificity remains, nothing is attached and the ambiguity is not guessed.
4. **Given** a route with a trailing slash, query string or version prefix configured as ignorable, **When** matching,
   **Then** query string and trailing slash are ignored, and prefixes listed in `api_context.ignore_prefixes` are stripped.
5. **Given** the method is absent from the log text, **When** matching, **Then** a match is made only if exactly one
   method exists for that path; otherwise nothing is attached.
6. **Given** a path segment that the Normalizer replaced with `<PATH>` or `<TOKEN>` covering several segments, **When**
   matching, **Then** the route is treated as unmatchable and nothing is attached (no partial guess).

---

### User Story 3 - Read the description safely, once a day (Priority: P2)

The Operator points the feature at a local file or a URL. The document is read at most once per TTL (default 24 hours),
parsed in a way that keeps only four fields per operation, and the compact result is cached in state with an expiry.

**Why this priority**: reading the full document every run would waste time and bandwidth, and the document may hold
examples and data that must never be kept.

**Independent Test**: an OpenAPI fixture with examples containing seeded personal values and very long descriptions; the
cached result and every outbound payload contain none of them; the second run within the TTL makes no read.

**Acceptance Scenarios**:

1. **Given** a local file path or an `https` URL, **When** the document is read, **Then** the operation is `GET` only,
   timeouts and a size cap (default 2 MB) apply, redirects are limited, and a larger document is refused with a visible
   notice.
2. **Given** a parsed document, **When** it is reduced, **Then** only `method`, `path template`, `operationId`, `summary`,
   the first `tags` entry and the `deprecated` flag per operation are kept; examples, schemas, parameters, request and
   response bodies, descriptions, servers' variables and extensions are dropped at the boundary.
3. **Given** a summary or operationId longer than `api_context.summary_cap` (default 80 characters), **When** it is kept,
   **Then** it is cut to the cap, then passed through the Normalizer/Redactor like any other text before it can reach a
   notifier or the model.
4. **Given** the reduced result, **When** it is stored, **Then** it is stored in the state store with an expiry equal to the
   TTL; the raw document is never persisted.
5. **Given** the source is unreachable, invalid or oversize, **When** a run happens, **Then** the last good cached result is
   used if it has not expired; otherwise the feature is skipped with one visible notice, and the log findings are unaffected.
6. **Given** a URL needs credentials, **When** configured, **Then** the credential comes from the host environment and is
   never logged, echoed or sent anywhere except in that request.

### Edge Cases

- OpenAPI 2.0 (Swagger) and 3.0/3.1 documents: both are read through the same reduction; unknown versions are refused with
  a notice.
- YAML and JSON: both are accepted; a YAML document using aliases or anchors beyond a small expansion limit is refused
  (protection against expansion attacks).
- A summary contains a person name or an e-mail (copy-paste in documentation): the masking pass handles patterns it knows;
  the cap and the "owner is responsible for summaries" statement are the documented limit.
- A path has an `operationId` only: it is shown; nothing is invented.
- A route appears only in the stack frames (a controller path rather than a URL): not matched in this version; only HTTP
  route text in the masked template is used.
- Very large OpenAPI documents (thousands of operations): the size cap refuses the read; the Operator supplies a smaller
  file.
- The same route appears in many groups: the line is attached to each group but counts once against `max_endpoints`.
- Two services share one document: each service has its own configuration and cache key.
- The Operator enables the feature but supplies no source: configuration validation fails before any external call, naming
  the setting.

## Requirements *(mandatory)*

### Functional Requirements

**Source and cache**

- **FR-001**: The package MUST be able to read an OpenAPI 2.0, 3.0 or 3.1 document, JSON or YAML, from a local file path or a
  URL, read-only (`GET` for URLs), with timeouts, a size cap (default 2 MB) and a redirect limit.
- **FR-002**: The reader MUST reduce the document at the boundary to operations of `{method, path template, operationId,
  summary, first tag, deprecated}` and MUST drop every other part (examples, schemas, parameters, bodies, descriptions,
  server variables, extensions).
- **FR-003**: The reduced result MUST be cached in the state store keyed by service and source identity, with an expiry equal
  to `api_context.ttl` (default 24 hours); the raw document MUST NOT be persisted.
- **FR-004**: When the source cannot be read, the last unexpired cached result MUST be used; otherwise the feature MUST be
  skipped with one visible notice and MUST NOT fail the run.
- **FR-005**: Source credentials MUST come from the host environment or configuration and MUST NOT appear in notices, logs,
  digests or model input.

**Matching**

- **FR-006**: A route MUST be extracted only from the masked template text of a selected group (a method followed by a
  path that starts with `/`, or a bare path), never from raw events.
- **FR-007**: Matching MUST be by method and path template: literal segments compare equal (case-sensitive); a Normalizer
  placeholder in the route (`<ID>`, `<N>`, `<TOKEN>`, `<EMAIL>`, `<NAME>`, `<STR>`) matches exactly one `{param}` segment;
  `<PATH>` or any placeholder standing for several segments makes the route unmatchable.
- **FR-008**: The more specific template (more literal segments) MUST win; an unresolved tie MUST attach nothing.
- **FR-009**: Base path from `servers` or `api_context.base_path` and prefixes in `api_context.ignore_prefixes` MUST be
  stripped before comparison; query strings and trailing slashes MUST be ignored.
- **FR-010**: With no method in the text, a match MUST be made only if the path has exactly one operation.

**Use**

- **FR-011**: A matched endpoint MUST be shown as one digest line (method, template, summary or operationId, first tag,
  deprecated marker) and included in the compact analyzer input for that group as one short field.
- **FR-012**: At most `api_context.max_endpoints` distinct endpoints (default 5, hard maximum 20) MUST be attached per run,
  in candidate priority order, and the number skipped MUST be stated in the digest footer.
- **FR-013**: Summary and operationId MUST be cut to `api_context.summary_cap` (default 80 characters) and then pass through
  the Normalizer and Redactor before any use in a notifier or model input; any error drops the value (Principle I).
- **FR-014**: The feature MUST be off by default; when off, the source MUST NOT be read, no cache entry written, and output
  MUST be identical to a run without the feature.
- **FR-015**: Attached endpoint context MUST be counted in the token budget and the run summary (`--json`) MUST report the
  number of endpoints attached, skipped, cache hit or miss, and the tokens attributable to this context.
- **FR-016**: The model MUST NOT select which endpoints are looked up; matching is code only, and the model's prompt MUST
  state that the endpoint line is documentation text and is not evidence of the cause.
- **FR-017**: The `watcher check` doctor MUST get one check for this feature (source readable, size within the cap,
  operation count, how many operations were reduced) when enabled, without posting or spending tokens.
- **FR-018**: An invalid configuration (missing source, cap outside range, unknown key) MUST fail before any external call,
  naming the setting.

### Key Entities

- **API Source** — a file path or URL with optional credential reference, TTL and size cap.
- **Operation Summary** — `{method, path template, operationId, summary, tag, deprecated}` after reduction and cap.
- **Route Reference** — a method (optional) and a masked path found in a selected group's template.
- **Endpoint Context** — the matched Operation Summary attached to a group for digest and model input.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On a fixture OpenAPI document with seeded personal values in examples, descriptions, parameters and
  extensions, 0 seeded values appear in the cached result, any model input, any notifier payload or the `--json` output
  (privacy corpus extended in the same change).
- **SC-002**: The route-matching table (at least 30 cases covering literal, parameter, placeholder kinds, `<PATH>`, base
  path, trailing slash, query string, missing method, ties, case) passes with 0 wrong matches; every case that should not
  match attaches nothing.
- **SC-003**: With the feature enabled and 5 endpoints attached, the extra model input is at most 25 tokens per endpoint
  (125 per run at the default cap) and 0 output tokens; with the feature disabled the model input is byte-identical to the
  previous release on the existing end-to-end fixture.
- **SC-004**: Within the TTL, a second run makes 0 reads of the source (cache hit), and the reduced cache entry for a
  2 MB document is under 100 KB.
- **SC-005**: A document over the size cap, malformed, or an unreachable URL produces one visible notice and unchanged log
  findings (end-to-end test with fakes).
- **SC-006**: A YAML bomb fixture (deep aliases) is refused within 1 second and bounded memory (under 32 MB).
- **SC-007**: A Reader can tell what an endpoint in a finding is for from the digest alone for 100% of fixture groups whose
  route is documented.

## Token Budget Impact

- Value per token is low, which is why the feature is off by default.
- When enabled: at most 25 input tokens per attached endpoint, at most 5 endpoints per run by default (125 tokens), hard
  maximum 20 endpoints (500 tokens); no extra model calls; no output tokens.
- The tokens count against the same per-step budget as other context; if the budget would be exceeded, endpoint lines are
  the first items dropped (they are context, not evidence) and the digest says so.
- Disabled (default): zero change.

## Assumptions

- The OpenAPI document is the Service owner's own and is trusted for structure, not for content: structure is parsed,
  content is capped and masked.
- Only HTTP routes in masked templates are matched; routes in stack frames (controller names) are out of scope.
- The Operator accepts that summaries are free text written by humans; the masking pass reduces risk but does not make
  summaries safe to publish beyond the Operator's own channel.
- Local file or `https` URL only; other schemes (file URLs, FTP) are refused.
- A single document per service; multi-document merging is deferred.
- Spec 007 (code-aware analysis) may later give richer endpoint context; this feature does not depend on it.
- YAML parsing uses a parser already chosen by the plan, with alias expansion limited; a new dependency needs a written
  reason in the plan (Principle VIII).
- The state store entry follows the standard expiry rule (Principle I); the TTL is shorter than the default retention.


## Owner decisions (2026-10-24)

Endpoint summaries are an allowlisted addition (masked, capped to 80 characters). Start with JSON OpenAPI documents; YAML only if the plan justifies the new dependency under Principle VIII. Matching is limited to HTTP routes in masked templates; ties attach nothing.
