# Data Model: API Context for Findings

One new key kind, no new table. Everything else is an in-memory value object.

## Cache entry (the only persisted item)

| Key | `api:{service}:{sourceId}` (`Keys::apiContext`), `sourceId` = first 12 hex of `sha256(source string)` |
|---|---|
| Value | `{"v":1,"base":"/api","ops":[["POST","/orders/{id}/refund","Refund an order","payments",false,""],...],"dropped":0,"readAt":"2026-10-24T06:00:00Z"}` |
| `ops` row | positional: `[METHOD, template, summary, tag, deprecated, operationId]`; `operationId` is `""` when `summary` is non-empty (R3); `summary` and `tag` may be `""` |
| Size | <= 100,000 bytes encoded (reducer stops adding rows; `dropped` counts the rest) |
| TTL | `api_context.ttl` (default `PT24H`, <= `state.retention`); `Keys::ttl('api')` = retention (upper bound) |
| Holds | masked, capped strings only; never the raw document, URL, path, token, examples, schemas, descriptions |
| Does not hold | anything from a log event |

## Value objects (namespace `Watcher\ApiContext`)

| Type | Fields | Invariants |
|---|---|---|
| `OperationSummary` | `method` (upper-case, 7 verbs + TRACE), `template` (starts `/`), `summary`, `tag`, `deprecated`, `operationId` | strings already cut to `summary_cap` and masked; no other field exists |
| `ReducedSpec` | `base`, `ops` list<OperationSummary>, `dropped` int | `fromCache(array)` rejects a wrong `v` or a malformed row (returns null -> treated as a miss) |
| `Route` | `method` ?string, `segments` list<Segment>, `unmatchable` bool | built only from a masked template; `Segment` = `{kind: literal\|param, text}` |
| `EndpointContext` | `method`, `template`, `summary`, `tag`, `deprecated`, `operationId` | `digestLine(): ?string`, `modelField(): ?string` (<= 75 bytes) |
| `Resolution` | `byFingerprint` map => EndpointContext, `skipped` int, `distinct` int | `skipped` counts distinct endpoints over the cap, once each |

## Shared carriers (with spec 009; whichever lands first creates them)

| Type | Field added by 010 |
|---|---|
| `Notify\DigestExtras` | `endpointLines` map fingerprint => string, `endpointsSkipped` int, `endpointsDropped` bool; `unavailable['api_context']` = token |
| `Analyze\PayloadExtras` | `endpoints` map fingerprint => string (the `ep` field) |
| `Operate\RunStats` | `apiEndpointsAttached`, `apiEndpointsSkipped`, `apiCache`, `apiTokens` |

## Compact model input

A group row gains `"ep": "POST /orders/{id}/refund - Refund an order [payments]!"` only in analysis/escalation requests,
only for groups with an attached endpoint, only when `api_context` is configured and the endpoint survived
`Budget::fitEndpoints`. Never in triage. Never any other part of the OpenAPI document.

## `--json` summary addition (counts only; key present only when configured)

```json
"api_context": {"attached": 3, "skipped": 1, "cache": "hit", "tokens": 61}
```
