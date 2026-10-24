# Implementation Plan: API Context for Findings

**Branch**: `010-api-context` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/010-api-context/spec.md` (with its "Owner decisions" block)

## Summary

One small, off-by-default step between selection and the model call: read an OpenAPI **JSON** document at most once per
TTL, reduce it at the boundary to `{method, path template, summary|operationId, first tag, deprecated}`, cache the
reduced result in the existing `StateStore`, match the HTTP routes found in the **masked** templates of the selected
groups, and attach one capped, masked line per distinct endpoint (digest line; one short `ep` field in the analysis input).

New concrete classes in `src/ApiContext/` (no interface: one implementation, Principle VIII):

| Class | Job |
|---|---|
| `OpenApiSource` | read bytes from a local file or an `https` URL (GET, timeouts, redirect limit, decoded-size cap); refuse YAML |
| `OpenApiReducer` | JSON array -> `ReducedSpec` (operations + base path); allowlist, caps, masking, 100 KB bound |
| `ApiSpecCache` | `StateStore` get/put of the reduced spec, key `api:{service}:{sourceId}`, TTL = `api_context.ttl` |
| `ApiContextReader` | cache hit -> reduced spec; miss -> source -> reducer -> cache; failure -> fixed-token notice, never throws |
| `RouteExtractor` | masked template text -> `Route` (method?, segments) or null |
| `RouteMatcher` | `Route` x `ReducedSpec` -> one operation or null (specificity, ties attach nothing) |
| `EndpointResolver` | selected groups -> `fingerprint => EndpointContext`, cap, skipped count, masking of the final line |

Decisions: [research.md](research.md). Cache entry and value objects: [data-model.md](data-model.md). Config, matching
algorithm and case table, payload and JSON additions: [contracts/](contracts/). Scenarios: [quickstart.md](quickstart.md).

## Technical Context

**Language/Version**: PHP ^8.1 (unchanged). **New dependencies**: **none**. YAML is deferred (owner decision: JSON first;
`symfony/yaml` would be a new dependency for an off-by-default, low-value-per-token feature, and its alias-expansion
limits would need their own tests). A YAML source is refused with the notice token `unsupported_format` (research R1).

**Storage**: existing `StateStore`; one new key kind `api` (`Keys::apiContext`). The raw document is never persisted.

**Testing**: PHPUnit `Unit`, `Contract`(none new), `EndToEnd`; no network: Guzzle `MockHandler` + temp files;
fixtures `tests/Fixtures/api_context/*.json`; the SC-002 case table is a data file read by the test.

**Constraints**
- Off by default: section absent = the source is never opened, no `put`, byte-identical output (FR-014, SC-003).
- No route in any selected template = **no read at all** (not even the cache).
- Failure of the source never changes log findings or exit status; one visible notice (SC-005).
- <= 25 input tokens per endpoint in the model field, <= `max_endpoints` (default 5, hard 20); zero output tokens.

## Constitution Check

| Principle | Gate | Result |
|---|---|---|
| I. Privacy fails closed | New field class (endpoint summary, tag, operationId) is an allowlisted addition (owner decision): cut to `summary_cap` (80), masked by `Redactor::redactText` at reduction (cache is clean) **and** again when the line is built; any `null` from the redactor drops the value. The reader keeps six named keys per operation; examples, schemas, parameters, bodies, descriptions, server variables, extensions are never copied out of the decoded array. Cache holds no raw document. Privacy corpus extended **before** the reducer (T002). | Pass |
| II. Read-only | `GET` only (`ArchitectureTest` pins it, T011). `https` only (O1), redirects <= 3 and https-only, no request body. Own-state `put` of the cache is allowed. | Pass |
| III. Test-first | Every implementation task has a red test task first (T006/T007 before T008/T009, T010-T013 before T014-T018, T019-T021 before T022-T024). Matching table >= 30 cases (SC-002). | Pass |
| IV. Code decides | Route extraction, matching, cap and order are code; the model never picks an endpoint; the prompt says the line is documentation, not evidence (FR-016). | Pass |
| V. Token discipline | Off by default; <= 25 tokens per endpoint (model field trimmed to fit), <= 5 by default; `ep` only in analysis/escalation, not triage; first context dropped when over budget (`Budget::fitEndpoints` runs before the other fitters). Token-budget fixture T028. | Pass |
| VI. Config over code | Source, TTL, caps, base path, prefixes from `api_context`; validated at start naming the key (T013). Nothing consumer-specific. | Pass |
| VII. Loud failures | `API context unavailable (<token>)` in the digest; `watcher check` gains a check; skipped endpoints counted in the footer; truncation of an oversize reduction stated. | Pass |
| VIII. Simplicity | No interface, no YAML, no dependency, no multi-document merge. One new key kind. | Pass |

**Four-contract principle**: unchanged. `ApiContextReader` is built by `Factory::apiContextReader(WatchConfig, Client,
StateStore, Redactor-factory)` and handed to `Watcher` as one nullable argument.

**Spec drift recorded (resolved by the owner decision block)**
1. FR-001, Edge Cases and SC-006 mention YAML and YAML alias bombs. Owner decision 2026-10-24: **JSON first**. This plan
   ships JSON only; SC-006 is met with a JSON nesting/size bomb fixture plus "a YAML document is refused in < 1 s". The spec
   text should be amended when YAML is added (it is not edited in this change: docs-only lane).
2. FR-003 says cache expiry equals the TTL; FR-004 says "use the last unexpired cached result when the source cannot be
   read". With expiry = TTL and "read only on a miss", the fallback can never apply. Plan follows FR-003 literally and states
   that FR-004's fallback is vacuous until owner decision O2 is answered (research R4).
3. Spec FR-007 lists six placeholders; `<USER>` also exists in the `Normalizer` and is treated like them (R5).

## Project Structure

### Documentation (this feature)

```text
specs/010-api-context/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/{config.md,matching.md}
├── checklists/
└── tasks.md
```

### Source Code (changes only)

```text
src/
├── ApiContext/ (new)
│   ├── OpenApiSource.php, OpenApiReducer.php, ReducedSpec.php, OperationSummary.php
│   ├── ApiSpecCache.php, ApiContextReader.php
│   ├── Route.php, RouteExtractor.php, RouteMatcher.php
│   └── EndpointContext.php, EndpointResolver.php
├── State/Keys.php                       # + apiContext(), kind `api` in ttl()
├── Analyze/PayloadExtras.php            # shared with 009 (create or extend: `endpoints`)
├── Analyze/CompactPayload.php, Budget.php, prompts/    # [B] `ep` field, fitEndpoints, one sentence
├── Notify/DigestExtras.php              # shared with 009 (create or extend)
├── Notify/DigestData.php, DigestRenderer.php           # [D] endpoint line, footer counts
├── Operate/Doctor.php                   # [K] api_context check
├── Operate/RunStats.php, JsonSummary.php
├── Config/WatchConfig.php [C], Factory.php [F]
└── Watcher.php [W]                      # one block after fitDependencies
config/watcher.example.php, docs/{configuration,privacy,operations}.md, CHANGELOG.md, docs/roadmap.md

tests/
├── Unit/ApiContext/{OpenApiSourceTest,OpenApiReducerTest,ApiSpecCacheTest,ApiContextReaderTest,RouteExtractorTest,RouteMatcherTest,EndpointResolverTest}.php
├── Fixtures/api_context/{petstore_v2,orders_v30,orders_v31,pii_seeded,json_bomb,yaml_doc,match-cases}.*
└── EndToEnd/ApiContextTest.php, PrivacyTest.php + RunSummaryTest.php (extended)
```

**Structure Decision**: same single library. `Watcher.php`, `Factory.php`, `WatchConfig.php`, `DigestData/Renderer`,
`CompactPayload/Budget`, `Doctor`, `RunStats/JsonSummary` are hot files shared with spec 009; tasks carry the
`[W] [F] [C] [D] [B] [K]` markers and the cross-spec order is in tasks.md.

## Wiring in `Watcher::attempt()` (one block)

After `Budget::fitDependencies(...)` (`Watcher.php:632`), when `$c->apiContext !== null`:

1. `RouteExtractor` over `$redacted` templates (already masked, already truncated to the budget). No route -> stop (no read).
2. `ApiContextReader::spec()` (cache or source); failure -> `DigestExtras::unavailable['api_context'] = token`.
3. `EndpointResolver::resolve($redacted, $spec, $cap)` -> `fingerprint => EndpointContext`, `skipped`.
4. `Budget::fitEndpoints(...)` (before 009's `fitMetrics` and the dependency fit), note when dropped.
5. `PayloadExtras::endpoints` for the analysis/escalation requests; `DigestExtras::endpointLines/endpointsSkipped`; `RunStats`.

The baseline run, the quiet run and the digest-disabled run return earlier and never reach this block.

## Complexity Tracking

| Ceiling | Why | Upgrade path |
|---|---|---|
| JSON only | Owner decision; no new dependency | Add YAML with `symfony/yaml` (`Yaml::PARSE_*` flags, alias cap) after a written reason in a new plan |
| Cache cut at 100,000 bytes, documents beyond it are truncated in document order | SC-004 bound; keeps the state entry small | Raise the bound or filter by tag once real documents show the need |
| One `servers[]` entry (the first) supplies the base path | Spec: base path from `servers[].url` or config | `api_context.base_path` already overrides; multiple bases need a use case |
| Mask-at-reduction means a changed `redaction.patterns` applies after the TTL, not immediately | Cache stays clean (SC-001) | The use-time pass already re-masks every line; clear the key to force |
| Literal route segment never matches a `{param}` (`/users/me` vs `/users/{id}`) | Spec US2 AC1; no guessing | None; documented limitation |
