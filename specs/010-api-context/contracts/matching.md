# Contract: route extraction and matching (normative; the test data file is this table)

## Extraction (from a masked template, never from events)

1. `route = first match` of the pattern in research R5; the method is optional and upper-case in the text. A route
   **without** a method needs at least one non-empty segment (`retry 3 / 5 failed` and a lone `/` are not routes).
2. Cut `?query` and `#fragment`; strip trailing `.,;:)"'`; split on `/`, drop empty segments.
3. Segment kinds: `<ID> <N> <TOKEN> <EMAIL> <NAME> <STR> <USER>` alone = **Param**; contains `<PATH>` or `…` = route
   **unmatchable**; any other `<`/`>` = route **unmatchable**; else **Literal**.
4. Normalise: strip `ignore_prefixes` (config order, once each), then the base path (segment boundary).

## Reach (normative, research R11)

The Normalizer rewrites an absolute path of 3 or more segments to `<PATH>/…/<last two>` **before** `<ID>`/`<N>` apply, so
only routes of **at most 2 segments** arrive at the extractor as matchable. The matcher itself handles any segment count;
table rows marked **S** use a *synthetic* template that the Normalizer cannot produce (they test the matcher in isolation).
Rows marked **R** are shapes that can really arrive. The round-trip section below is the truth about reach.

## Matching

Equal segment count, then per position: Literal=Literal (byte-equal, case-sensitive) or Param=Param (template `{x}` whole
segment). Everything else fails, including route Literal vs template Param and route Param vs template Literal. Template
segments with mixed text and braces never match. Method known: only that method. Exactly one operation matches = match;
more than one (equal shape) = **nothing attached** (there is no specificity rule: a route Literal never matches a template
Param, so matching templates always have the same shape).

## Case table: matcher level (SC-002, with the round-trip table below >= 30 cases, 0 wrong matches)

`T` = template text of a group; operations are in the fixture.

Operations: `POST /orders/{id}/refund` (op1), `GET /orders/{id}` (op2), `DELETE /orders/{id}` (op3),
`GET /orders/recent` (op4), `GET /users/{userId}` (op5), `GET /users/{userId}/orders/{orderId}` (op6),
`GET /files/{name}.{ext}` (op7), `GET /health` (op8), `GET /a/{x}` (op9), `GET /a/{y}` (op10),
`POST /v2/orders/{id}/refund` (op11), `GET /` (op12). Base path `/api` unless noted.

| # | Kind | Template text `T` | Config | Expected |
|--:|:-:|---|---|---|
| 1 | S | `POST /orders/<ID>/refund failed: timeout` | | op1 |
| 2 | S | `POST /api/orders/<ID>/refund failed` | | op1 (base stripped from route) |
| 3 | S | `post /orders/<ID>/refund failed` | | op1 (lower-case `post` is not a method; the bare path has exactly one operation) |
| 4 | R | `GET /orders/<N>` | | op2 |
| 5 | R | `/orders/<ID>` (no method) | | none (op2 and op3 share the path) |
| 6 | S | `/orders/<ID>/refund` (no method) | | op1 (single operation for the path) |
| 7 | R | `GET /orders/recent` | | op4 |
| 8 | R | `GET /orders/<ID>` (op4 also in the fixture) | | op2 (Param never matches Literal `recent`) |
| 9 | R | `GET /orders/latest` | | none (Literal vs Param does not match) |
| 10 | R | `GET /users/<N>` | | op5 |
| 11 | R | `GET /users/<EMAIL>` | | op5 |
| 12 | S | `GET /users/<TOKEN>/orders/<N>` | | op6 |
| 13 | S | `GET /users/<NAME>/orders/<STR>` | | op6 |
| 14 | R | `GET /users/<USER>` | | op5 |
| 15 | S | `GET /users/<N>/orders` | | none (segment count) |
| 16 | S | `GET /files/<PATH>` | | none (unmatchable) |
| 17 | S | `GET /files/<PATH>/…/x.png` | | none (unmatchable) |
| 18 | R | `GET /files/report.pdf` | | none (op7 is Mixed) |
| 19 | S | `GET /files/<N>.pdf` | | none (mixed route segment) |
| 20 | R | `GET /health` | | op8 |
| 21 | R | `GET /health/` | | op8 (trailing slash) |
| 22 | R | `GET /health?probe=1` | | op8 (query ignored) |
| 23 | R | `GET /HEALTH` | | none (case-sensitive) |
| 24 | R | `GET /a/<ID>` | | none (op9 and op10 are equal shape: tie) |
| 25 | S | `POST /v2/orders/<ID>/refund` | `ignore_prefixes: ['/v2']` | op1 (prefix stripped; op11 does not match since the route lost `/v2`; documented) |
| 26 | S | `POST /v2/orders/<ID>/refund` | none | op11 |
| 27 | S | `POST /orders/<ID>/refund` | `base_path: '/api'` | op1 |
| 28 | S | `POST /api/api/orders/<ID>/refund` | | none (base stripped once) |
| 29 | R | `GET /` | | op12 |
| 30 | R | `GET /orders/<ID>, retrying` | | op2 (trailing punctuation stripped) |
| 31 | R | `error at /var/log/app.log` | | none (no operation) |
| 32 | R | `connect ECONNREFUSED <HOST:orders-db>/orders/<ID>` | | none (mixed segment `<HOST:...>` before the slash, bare path guard) |
| 33 | R | `a/b/c 10/12` | | none (no route) |
| 34 | X | template `null` (withheld) | | none (no route) |
| 35 | X | `GET /orders/<ID>` with op2 deprecated, summary `Get an order` | | op2 (line level: deprecated marker; EndpointResolver, later) |
| 36 | X | `GET /orders/<ID>` with op2 summary empty, operationId `getOrder` | | op2 (line level: operationId fallback) |
| 37 | X | `GET /orders/<ID>` with op2 summary, tag and operationId empty | | op2, no line to show (`noLine`) |
| 38 | R | `retry 3 / 5 failed` | | none (method-less route needs >= 1 non-empty segment; must not match op12 `GET /`) |
| 39 | R | `a // b` | | none (no non-empty segment) |
| 40 | R | `retry 3 / 5 then GET /health` | | op8 (the first real route wins over the prose slash) |
| 41 | R | `see /` (lone `/`, no method) | | none (same rule) |

Rows marked **X** are extractor or line-level cases kept in the same table so the list is one list (the `null` template is also
an extractor case in T006; the line-level rows wait for the resolver in T019). The table has **41** rows and the test asserts
exactly that, and that this table and the fixture carry the same row numbers.

Each matcher row is a row of `tests/Fixtures/api_context/match-cases.php`; the test fails if a "none" row attaches anything.
(Row 32: the Normalizer writes `<HOST:name>` as a masked host; the guard forbids a route starting inside it.)

## Round-trip table (raw line -> real `Normalizer` -> extractor -> matcher)

Run by `tests/Unit/ApiContext/RouteRoundTripTest.php` with the **real** Normalizer, so the Normalizer's order is pinned.
Operations as above. The expected column is what the pipeline does, not what one might wish.

| # | Raw line | After the Normalizer | Expected |
|--:|---|---|---|
| R1 | `GET /orders/8812` | `GET /orders/<N>` | op2 |
| R2 | `POST /orders/8812/refund` | `POST <PATH>/…/<N>/refund` | none (3 segments, unmatchable) |
| R3 | `GET /users/42/orders` | `GET <PATH>/…/<USER>/orders` | none |
| R4 | `GET /v1/orders/8812/items/55` | `GET <TOKEN>` | none |
| R5 | `GET /users/42` | `GET /users/<N>` | op5 |
| R6 | `GET /health` | `GET /health` | op8 |
| R7 | `GET /orders/recent` | `GET /orders/recent` | op4 |

If the Normalizer changes order or output, this test fails first: update research R11 with it.
