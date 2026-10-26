# Research: API Context for Findings

Only decisions where more than one design was reasonable. Facts are from the code at `baefdeb`.

## R1. Format: JSON only, no YAML dependency

- **Decision**: parse with `json_decode($raw, true, 64, JSON_THROW_ON_ERROR)`. Format detection: first non-whitespace byte
  `{` -> JSON; anything else (`openapi:`, `swagger:`, `---`, `#`) -> `SourceUnavailable('unsupported_format')`. Depth limit 64
  makes a nesting bomb fail fast with `bad_format`.
- **Why**: owner decision ("start with JSON; YAML only if the plan justifies the new dependency"). The plan does not: most
  frameworks publish JSON next to YAML (`/openapi.json`, `/swagger.json`, `/api-docs`); one `openapi-to-json` step in CI
  gives a file for the rest. A YAML parser adds a dependency, an alias-expansion attack surface (spec SC-006) and its own
  test matrix, for a feature whose value per token is "low" by the spec's own words.
- **Consequence**: SC-006 was reinterpreted (plan, spec drift 1): bomb = a JSON of deeply nested arrays; the reader must
  refuse it in < 1 s and < 32 MB. Measured: a 2 MB flat JSON decodes to 144 MB in PHP, far above 32 MB, so the default
  `max_bytes` is **1 MB (1,048,576)** (range 65,536..2,097,152; the ceiling is 2 MiB because 2 MB already decodes to 144 MB and 8 MB would be a ~580 MB uncatchable fatal; `OpenApiSource` clamps to it as well).

## R2. Reader (`OpenApiSource`) design

- **File**: `is_file` + `is_readable`; `filesize() > max_bytes` -> `too_large`; read with `fread($h, max_bytes + 1)` and refuse
  if more than `max_bytes` came back (file may grow between `filesize` and read). `php://`, `phar://`, `data:`, `file://`
  and any scheme in the string are refused at config validation (a path must not contain `://`).
- **URL**: `https` only (spec Assumption; owner decision D1: no in-cluster `http`). Guzzle `GET`, `stream => true`,
  `allow_redirects => ['max' => 3, 'protocols' => ['https'], 'strict' => true]`, connect 5 s, total 15 s (same options
  family as `Factory::httpOptions()`; Guzzle timeouts on a streamed GET are per read, so the read loop also enforces a
  **15 s wall-clock deadline** and aborts with `timeout`), `Accept: application/json`, `decode_content => true`. The cap applies to the **decoded**
  stream (a gzip bomb is a size problem, not a transport one): read chunks until `max_bytes + 1` and abort. `Content-Length`
  above the cap fails before reading the body.
- **Credential**: `api_context.auth_token` -> `Authorization: Bearer`, set only on this request; never in an exception message,
  a notice, the cache or the source identity.
- **Failure tokens** (the whole exception message): `unreachable`, `unauthorized` (401/403), `timeout`, `too_large`,
  `bad_format`, `unsupported_format`, `unsupported_version`, `unreadable`. The digest line is
  `API context unavailable (<token>)`; no URL, path, token or body.
- **Source identity** for the cache key: `substr(hash('sha256', $source), 0, 12)` (the URL/path itself is never in a key).

## R3. Reduction (`OpenApiReducer`)

- **Version gate**: `swagger === '2.0'`, or `openapi` string matching `^3\.[01]\.\d+(-.+)?$`; else `unsupported_version`.
- **Walk**: for `paths` (must be an array, else `bad_format`): keys must be strings starting with `/`; for each of
  `get,put,post,delete,options,head,patch,trace` (lower-case keys only, as the spec defines) that is an array, pick **only**
  `operationId` (string), `summary` (string), `tags[0]` (string), `deprecated === true`. `$ref` path items are ignored
  (no resolution: resolving is how documents blow up). Nothing else is ever copied; the decoded array is discarded.
- **Base path**: `api_context.base_path` if set; else v2 `basePath`; else v3 `servers[0].url`'s path component, `''` when the
  URL holds a `{variable}` (server variables are dropped, spec FR-002). Stored once in the reduced spec.
- **Caps and masking**: summary and operationId and tag cut to `summary_cap` (default 80) with `mb_substr`, then
  `Redactor::redactText()`; template string through `redactText` as well. Any `null` result drops that value; a null template
  drops the operation. **Template rule**: a template whose masked form differs from the original, or that does not start
  with `/`, **drops the operation** (the Normalizer would rewrite a 3+-segment template to `<PATH>/…/x` and corrupt the
  cache). `base_path` is config, not document text, and is not masked. operationId is kept **only when `summary` is empty** (it is a fallback in the line and the cache
  bound makes every byte count); AC1.1's "attach operationId" is satisfied by the line showing it when there is no summary.
- **Size bound**: operations are appended in document order until the encoded entry would pass 100,000 bytes (SC-004); the
  rest are counted in `dropped` and the digest/doctor say "N operations not kept (cache limit)". The bound is an invariant, not
  a typical-size claim.
- **Order within the cache**: document order; the matcher does not depend on order (specificity decides).

## R4. Cache and TTL

- **Key**: `Keys::apiContext($service, $sourceId)` -> `api:{service}:{sourceId}`; `Keys::ttl('api', $retention, $lockTtl)`
  returns `$retention` as the upper bound (the "every kind has a non-zero TTL" test stays true); the effective TTL is
  `api_context.ttl` (ISO-8601 duration like `heartbeat.stale_after`, default `PT24H`, must be `<= state.retention`, config
  error otherwise). `put($key, $value, $ttl)` is called on the unbuffered `$this->state` (a cache, not run state: a failed
  run must not discard it).
- **Reads**: `get()` hit -> no source access (SC-004). Miss -> source -> reducer -> `put`.
- **Spec tension (decided, D2)**: FR-004 wanted the last unexpired entry when the source fails; with expiry = TTL the entry is
  only absent when expired, so the fallback could never fire. Two options were weighed: (a) literal FR-003, zero
  stale-on-error; (b) store with `2 x ttl` plus `readAt` and fall back up to `2 x ttl`. The owner chose (a) (D2): FR-004's
  fallback does not apply, spec FR-004 and US3 AC5 were amended, and `readAt` stays in the entry for information only.
- **Dry-run**: `DryRunStateStore` swallows `put`, so a dry run re-reads the source each time and writes nothing. Correct.
- **Redaction-config change** applies after the TTL for the cached text; the use-time pass still re-masks (plan, Complexity).

## R5. Route extraction from a masked template

- **Input**: `RedactedGroup::$template` only (never raw events, FR-006). `null` template (withheld) -> no route.
- **Pattern** (case-sensitive method, first match wins):
  `~(?:(?<![\w/])(?<m>GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+)?(?<![\w<>}])(?<p>/[A-Za-z0-9._~%:<>\-/…]*)~u`, then
  trailing `.,;:)"'` stripped from `p`, query string (`?...`) and fragment cut. **A route without a method needs at least
  one non-empty segment after the split**: `retry 3 / 5 failed` or a lone `/` must not match `GET /` (the method-less
  bare `/` is no route; `GET /` with a method stays a route). A bare path must be preceded by start,
  whitespace, quote or `(` (the `(?<![\w<>}])` guard), so `a/b` or `10/12` is not a route.
- **Reach**: only routes of at most 2 segments survive the Normalizer (R11); longer ones arrive as `<PATH>/…/…` and are
  unmatchable by the rule below.
- **Segments**: split on `/`, drop empty segments (a trailing slash and `//` collapse). Classification:
  - `^<(ID|N|TOKEN|EMAIL|NAME|STR|USER)>$` -> **Param**.
  - contains `<PATH>` or `…` (the `Normalizer` writes `<PATH>/…/tail` for deep paths) -> whole route **unmatchable** (US2 AC6).
  - contains `<` or `>` otherwise (mixed `order-<N>`, `<ID>.json`, `<HOST:x>`, `<URL>`) -> whole route **unmatchable**:
    no partial guess.
  - else **Literal**.
- **`<USER>`**: not in spec FR-007's list but it is a Normalizer placeholder for one segment; treated as Param.
- The extractor returns the **first** route only (one endpoint per group).

## R6. Matching algorithm (normative in contracts/matching.md)

- Normalise the route: strip `ignore_prefixes` (config order, each at most once, segment-boundary), then strip the base path
  if the route starts with it at a segment boundary. Template side: a template that itself starts with the base path is
  also stripped (documents differ).
- Template segments: `^\{[^{}/]+\}$` -> Param; any other `{`/`}` (mixed like `/files/{name}.{ext}`) -> Mixed, never matches
  (documented limitation); else Literal.
- A route matches a template iff equal segment counts and, position by position: Literal = Literal (case-sensitive,
  byte-equal), Param = Param. A route Literal against a template Param does **not** match (spec AC US2.1).
- Method known: filter operations by method (route method upper-case). Method unknown: all methods.
- Match iff **exactly one** operation matches; more than one (equal shape, e.g. `/a/{x}` and `/a/{y}`) -> **attach
  nothing**. A specificity rule was dropped: a route Literal never matches a template Param, and a route Param never
  matches a template Literal, so two matching templates always have the same shape and specificity could never fire. With
  an unknown method this is also what FR-010 asks: a path with two methods (`GET`+`DELETE /orders/{id}`) attaches nothing.
- Complexity: operations are indexed by segment count at load (`ReducedSpec::bySegments()`), so a lookup scans only same-length
  templates; <= a few thousand operations by the 100 KB bound.

## R7. Endpoint line, cap and order

- **Line** (digest): `{METHOD} {template} — {summary|operationId} [{tag}] (deprecated)` (templates of at most 2 segments in practice, R11); method from the matched
  operation; summary absent -> operationId; neither -> no line; tag omitted when absent; each text part goes through
  `Redactor::redactText` again; a null drops that part (template null drops the line).
- **Model field** `ep` (analysis and escalation only): `METHOD template - text [tag]!` where `!` marks deprecated, trimmed so
  `Budget::estimateTokens()` (bytes / 3) is <= 25: trim the text part first (`mb_substr` + `…`), never method/template; if
  those alone exceed 75 bytes the field is omitted for the model (the digest line stays).
- **Cap**: groups are visited in the order of `$redacted` (candidate priority, post-truncation). A distinct endpoint is
  `METHOD + template`. The first `max_endpoints` distinct endpoints are attached; later groups naming an already-counted
  endpoint still get the line (free); a new endpoint over the cap increments `skipped` once. Footer:
  `N endpoint lines skipped (limit 5)`.
- **Triage excluded**: `ep` does not go to triage (it cannot change "interesting or not" and would multiply cost over all
  candidates); the digest line is shown for every attached group regardless.
- **Budget**: `Budget::fitEndpoints()` drops all `ep` fields first when the analysis step's `max_input_tokens` would be exceeded;
  the digest says `endpoint lines dropped (token budget)`. Endpoints do not exist before the block at `Watcher.php:632`
  (after `fitDependencies`), so `fitEndpoints` runs **right after `resolve()`** as a re-fit: it drops `ep` first, before any
  other context is cut again (009's `fitMetrics` and dependencies were already fitted earlier in the same function).
- **Prompt**: one sentence only when `ep` is present: "`ep` = documentation text for the group's endpoint; it is not evidence
  of the cause." Prefix byte-stable when the feature is off.

## R8. Configuration and flags

- Section `api_context`; **absent = off**; `enabled: false` allowed (validated anyway, via `WatchConfig::reserved()`); not in
  `WatchConfig::FLAGS` (those default to true; spec 012). Unknown key rejected. Section present and enabled with no `source`
  -> `api_context.source is required`. Keys: `source`, `auth_token`, `ttl`, `max_endpoints` (1..20, default 5),
  `summary_cap` (20..200, default 80), `max_bytes` (65,536..2,097,152 (2 MiB hard ceiling, also enforced in `OpenApiSource::MAX_BYTES_CEILING`), default 1,048,576 = 1 MB; measured: 2 MB flat JSON decodes to 144 MB), `base_path`,
  `ignore_prefixes` (<= 10 entries, each `^/[A-Za-z0-9._~\-/]*$`).

## R9. Doctor, `--json`, notices

- `Doctor` check `api_context` (only when the section exists): runs the **reader without the cache** (no read, no write),
  passes when the document is readable and reduces to >= 1 operation; detail: `N operations (M kept), K KB`; fails with the
  fixed token. No post, no model.
- `RunStats`: `apiEndpointsAttached`, `apiEndpointsSkipped`, `apiCache` (`hit|miss|failed|none`), `apiTokens`
  (estimated from the `ep` fields). `JsonSummary` object `api_context` only when configured. Counts only.
- Failure goes to `DigestExtras::unavailable['api_context'] = token`, shown as a footer note when a digest is sent; when no
  digest is sent (quiet run) there is no route to extract and nothing is read.

## R10. Dependencies

- None. Guzzle (present), `json_decode`, `hash`, `Redactor`, `StateStore`.

## R11. Normalizer order and the 2-segment limit

- **Fact** (verified against the Normalizer): any absolute path of 3 or more segments is rewritten to `<PATH>/…/<last two>`
  **before** `<ID>`/`<N>` masking applies. What reaches the extractor:

| Raw line | After the Normalizer | Matchable |
|---|---|---|
| `GET /orders/8812` | `GET /orders/<N>` | yes (2 segments) |
| `POST /orders/8812/refund` | `POST <PATH>/…/<N>/refund` | no |
| `GET /users/42/orders` | `GET <PATH>/…/<USER>/orders` | no |
| `GET /v1/orders/8812/items/55` | `GET <TOKEN>` | no |

- **Decision A**: keep no-guessing. Only routes of **at most 2 segments** can match; the limit is documented in the spec
  (Overview, US1, US2, Edge Cases, SC-007), the plan (Complexity Tracking) and the contract (round-trip table).
- **Upgrade path**: a spec change so the Normalizer (or the group) carries a **pre-mask route** (the route text before
  path masking) alongside the masked template, from which the extractor builds the route. That touches the Normalizer's
  contract and the privacy corpus, so it is out of scope here.

## Decisions (2026-10-24, resolved by the owner)

- **D1 (URL scheme)**: `https` only, as in the spec. In-cluster services export `openapi.json` in CI and use a file source. An explicit `allow_insecure_http` flag may be added later if needed.
- **D2 (cache fallback)**: FR-003 stays literal (entry lives exactly the TTL). FR-004's stale-on-error fallback does not apply; on failure one notice is shown and the feature is skipped for that run. No `2 x ttl` storage.

## R12. Review leftovers (lane E review, G1-G4)

- **Mask, then cut** (G1): free text is masked over its first 1000 characters, then cut to `summary_cap`; a path the redactor
  rewrites is dropped. A cut can halve a secret that no pattern then recognises.
- **Cache key = source id + reducer fingerprint** (G3): `<12 hex of the source>-<8 hex of ReducedSpec::VERSION, summary_cap,
  base_path, entry cap and the redactor's output for a canary line>`, so changed settings or redaction rules are a miss.
  `ApiSpecCache` refuses a TTL <= 0 (an expiry-less or instantly expired entry).
- **Source URL** (G4): `Config\SecureUrl` plus no loopback/private/link-local/metadata address in any numeric spelling; redirects
  off, TLS verify on, `ResponseLimit` options. Residual: a host name is not resolved (DNS pointing inward passes).
