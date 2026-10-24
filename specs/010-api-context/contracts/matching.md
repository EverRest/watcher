# Contract: route extraction and matching (normative; the test data file is this table)

## Extraction (from a masked template, never from events)

1. `route = first match` of the pattern in research R5; the method is optional and upper-case in the text.
2. Cut `?query` and `#fragment`; strip trailing `.,;:)"'`; split on `/`, drop empty segments.
3. Segment kinds: `<ID> <N> <TOKEN> <EMAIL> <NAME> <STR> <USER>` alone = **Param**; contains `<PATH>` or `…` = route
   **unmatchable**; any other `<`/`>` = route **unmatchable**; else **Literal**.
4. Normalise: strip `ignore_prefixes` (config order, once each), then the base path (segment boundary).

## Matching

Equal segment count, then per position: Literal=Literal (byte-equal, case-sensitive) or Param=Param (template `{x}` whole
segment). Everything else fails, including route Literal vs template Param and route Param vs template Literal. Template
segments with mixed text and braces never match. Method known: only that method. Specificity = template Literal count; keep
the maximum; exactly one operation left = match, otherwise **nothing attached**.

## Case table (SC-002: >= 30, 0 wrong matches). `T` = template text of a group; operations are in the fixture.

Operations: `POST /orders/{id}/refund` (op1), `GET /orders/{id}` (op2), `DELETE /orders/{id}` (op3),
`GET /orders/recent` (op4), `GET /users/{userId}` (op5), `GET /users/{userId}/orders/{orderId}` (op6),
`GET /files/{name}.{ext}` (op7), `GET /health` (op8), `GET /a/{x}/c` (op9), `GET /a/{y}/c` (op10),
`POST /v2/orders/{id}/refund` (op11), `GET /` (op12). Base path `/api` unless noted.

| # | Template text `T` | Config | Expected |
|--:|---|---|---|
| 1 | `POST /orders/<ID>/refund failed: timeout` | | op1 |
| 2 | `POST /api/orders/<ID>/refund failed` | | op1 (base stripped from route) |
| 3 | `post /orders/<ID>/refund failed` | | op1 (lower-case `post` is not a method; the bare path has exactly one operation) |
| 4 | `GET /orders/<N>` | | op2 |
| 5 | `/orders/<ID>` (no method) | | none (op2 and op3 share the path) |
| 6 | `/orders/<ID>/refund` (no method) | | op1 (single operation for the path) |
| 7 | `GET /orders/recent` | | op4 |
| 8 | `GET /orders/<ID>` vs op4 | | op2 (Param never matches Literal `recent`) |
| 9 | `GET /orders/latest` | | none (Literal vs Param does not match) |
| 10 | `GET /users/<N>` | | op5 |
| 11 | `GET /users/<EMAIL>` | | op5 |
| 12 | `GET /users/<TOKEN>/orders/<N>` | | op6 |
| 13 | `GET /users/<NAME>/orders/<STR>` | | op6 |
| 14 | `GET /users/<USER>` | | op5 |
| 15 | `GET /users/<N>/orders` | | none (segment count) |
| 16 | `GET /files/<PATH>` | | none (unmatchable) |
| 17 | `GET /files/<PATH>/…/x.png` | | none (unmatchable) |
| 18 | `GET /files/report.pdf` | | none (op7 is Mixed) |
| 19 | `GET /files/<N>.pdf` | | none (mixed route segment) |
| 20 | `GET /health` | | op8 |
| 21 | `GET /health/` | | op8 (trailing slash) |
| 22 | `GET /health?probe=1` | | op8 (query ignored) |
| 23 | `GET /HEALTH` | | none (case-sensitive) |
| 24 | `GET /a/<ID>/c` | | none (op9 and op10 tie) |
| 25 | `POST /v2/orders/<ID>/refund` | `ignore_prefixes: ['/v2']` | op1 (prefix stripped; op11 also contains /v2 literally: **op11 does not match** since the route lost `/v2`; documented) |
| 26 | `POST /v2/orders/<ID>/refund` | none | op11 |
| 27 | `POST /orders/<ID>/refund` | `base_path: '/api'` | op1 |
| 28 | `POST /api/api/orders/<ID>/refund` | | none (base stripped once) |
| 29 | `GET /` | | op12 |
| 30 | `GET /orders/<ID>, retrying` | | op2 (trailing punctuation stripped) |
| 31 | `error at /var/log/app.log` | | none (no operation) |
| 32 | `connect ECONNREFUSED <HOST:orders-db>/orders/<ID>` | | none (mixed segment `<HOST:...>` before the slash, bare path guard) |
| 33 | `a/b/c 10/12` | | none (no route) |
| 34 | template `null` (withheld) | | none |
| 35 | `GET /orders/<ID>` and op2 deprecated | | op2 with deprecated marker |
| 36 | `GET /orders/<ID>` and op2 has no summary, operationId `getOrder` | | op2, line shows `getOrder` |
| 37 | `GET /orders/<ID>` and op2 has neither | | none (no line) |

Each row is a row of `tests/Fixtures/api_context/match-cases.php`; the test fails if a "none" row attaches anything.
(Row 32: the Normalizer writes `<HOST:name>` as a masked host; the guard forbids a route starting inside it.)
