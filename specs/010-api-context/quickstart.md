# Quickstart: validating API Context for Findings

All scenarios run with fakes, temp files and recorded HTTP fixtures (no network). Gates: `composer test`, `composer stan`,
`composer style`, each exit 0 (read `$?`).

| # | Scenario | Expected | Test file |
|---|---|---|---|
| 1 | Fixture OpenAPI (3 operations) + groups naming those routes | each group gets its line; an unmatched route gets none, no error | `tests/EndToEnd/ApiContextTest.php` |
| 2 | Summary missing / operationId only / neither | operationId shown / shown / no line | `tests/Unit/ApiContext/EndpointResolverTest.php` |
| 3 | 8 distinct routes, `max_endpoints` 5 | 5 lines in candidate order, footer `3 endpoint lines skipped`; same route in two groups counts once | same |
| 4 | Feature absent / `enabled: false` | source never opened, no `put`, model input and digest byte-identical (SC-003) | `tests/EndToEnd/ApiContextTest.php` (spy on source + `RecordingStateStore`) |
| 5 | No selected group names a route | no source read, no cache read | same |
| 6 | Match table (35 matcher rows, rows marked S are synthetic templates) | 0 wrong matches; "none" rows attach nothing (SC-002) | `tests/Unit/ApiContext/RouteMatcherTest.php`, `RouteExtractorTest.php` |
| 6b | Round-trip: raw line -> real Normalizer -> extractor -> matcher (`GET /orders/8812` matches; `POST /orders/8812/refund` -> `<PATH>/…/<N>/refund` attaches nothing; 3+ segments never match) | the 2-segment limit is pinned | `tests/Unit/ApiContext/RouteRoundTripTest.php` |
| 7 | Fixture with seeded values in examples, descriptions, parameters, extensions, server variables, and an e-mail in a summary | cache entry, model input, digest, `--json` hold no seeded value (SC-001) | `tests/Unit/ApiContext/OpenApiReducerTest.php`, `tests/EndToEnd/PrivacyTest.php` |
| 8 | Swagger 2.0, OpenAPI 3.0, 3.1 | same reduction; `openapi: 4.0` -> `unsupported_version` | `OpenApiReducerTest.php` |
| 9 | YAML document / `.yaml` path | refused in < 1 s with `unsupported_format` (SC-006 reinterpreted) | `OpenApiSourceTest.php`, `WatchConfigTest.php` |
| 10 | JSON nesting bomb / over `max_bytes` (default 1 MB) / gzip bomb / slow stream (15 s deadline) | refused < 1 s (deadline case: `timeout` at 15 s on a fake clock), < 32 MB, `bad_format` / `too_large` / `timeout` | `OpenApiSourceTest.php` |
| 11 | Second run within TTL | 0 source reads (cache hit); reduced entry for a document at `max_bytes` (1 MB) < 100 KB (SC-004) | `ApiContextReaderTest.php` |
| 12 | Source unreachable / 401 / oversize / malformed | one `API context unavailable (<token>)` note; log findings identical (SC-005) | `tests/EndToEnd/ApiContextTest.php` |
| 13 | Two services, one document | separate cache keys | `ApiSpecCacheTest.php` |
| 14 | Token budget | <= 25 input tokens per `ep`; 0 output tokens; `ep` absent in triage; first dropped when over budget | `tests/Unit/Analyze/BudgetTest.php`, `CompactPayloadTest.php` |
| 15 | `watcher check` | source readable, size, operation count, kept count; no post, no tokens | `tests/Unit/Operate/DoctorTest.php` |
| 16 | `--json` | `api_context` counts present only when configured | `tests/EndToEnd/RunSummaryTest.php` |
| 17 | `--dry-run` | source read, no cache write | `tests/EndToEnd/DryRunTest.php` |
| 18 | Invalid config: missing `source` (enabled), bad scheme, `.yaml`, ttl, caps, unknown key (FR-018) | `InvalidConfig` naming the key path before any external call; no source opened | `tests/Unit/Config/WatchConfigTest.php`, `tests/EndToEnd/ApiContextTest.php` |
| 19 | Credential never leaks (FR-005): seeded `auth_token` | absent from notices, logs, digest, `--json`, cache entry and key, model input, exception messages | `tests/EndToEnd/PrivacyTest.php`, `OpenApiSourceTest.php` |
