# Research: provider error contracts (checked 2026-11-15)

Every fact below was read from the official provider documentation on 2026-11-15 with WebFetch. Each item is marked
VERIFIED (stated on the page) or UNVERIFIED (not stated; kept transient, see the decision column).

## OpenAI

Source: https://developers.openai.com/api/docs/guides/error-codes (redirected from https://platform.openai.com/docs/guides/error-codes), checked 2026-11-15.

| Item | Status | Finding | Decision |
|---|---|---|---|
| 401 authentication | VERIFIED | Page names four messages ("Invalid Authentication", "Incorrect API key provided", "You must be a member of an organization to use the API", "IP not authorized"). No `error.code` or `error.type` string is given for them. | 401 → `authentication failed`. |
| 403 | VERIFIED | Unsupported country, region or territory. | 403 → `authentication failed` (owner rule). |
| Invalid or revoked key as a distinct code | UNVERIFIED | Not on the page. `invalid_api_key` is not named. | Not used for any reason. |
| Expired key | UNVERIFIED | Not on the page. | Kept transient; reason `key expired` is not emitted. |
| Billing exhaustion (429) | VERIFIED | Codes named: `credit_balance_exhausted`, `organization_spend_limit_exceeded`, `project_spend_limit_exceeded`, `organization_usage_limit_exceeded`. The page says `error.type` can be `insufficient_quota`, and "Retrying billing, spend, or quota errors won't restore API access." | Allowlist for reason `quota exhausted`, read from `error.code` or `error.type`. Not retried. |
| Rate limit (429) | VERIFIED | "Rate limit reached for requests" (no code) and `slow_down` (type `rate_limit_error`). Handled by pacing and `Retry-After`. | Transient, as today. |
| 500, 503 | VERIFIED | 500 server error, retry after a wait. 503 `service_unavailable_error` / `server_is_overloaded`. | Transient. |

## Gemini

Sources: https://aistudio.google.com/docs/troubleshooting (redirected from https://ai.google.dev/gemini-api/docs/troubleshooting); https://aistudio.google.com/docs/api-errors; https://aistudio.google.com/docs/rate-limits. Checked 2026-11-15.

| Item | Status | Finding | Decision |
|---|---|---|---|
| Error body shape | VERIFIED for the Interactions API page; UNVERIFIED for `generateContent` | The api-errors page documents an `error` object with snake_case `code` (`authentication`, `payment_required`, `permission_denied`, `rate_limit_exceeded`, `quota_exceeded`, `too_many_requests`, `failed_precondition`, ...). The current code and this package's tests use `generateContent`'s `error.status` form (`RESOURCE_EXHAUSTED`). The two forms are not reconciled by the page. | Read `error.status` and `error.code` (string only) against the allowlists below. |
| Missing, invalid or expired key | VERIFIED (api-errors, snake_case form) | HTTP 401, code `authentication`: "The API key is missing, invalid, or expired." | 401 → `authentication failed`. Expired is not split out (not distinguishable here). |
| 403 | VERIFIED | `permission_denied`: key lacks permission, project restricted, verification or billing change needed. A leaked or blocked key returns 403 PERMISSION_DENIED: "Your API key was reported as leaked. Please use another API key." | 403 → `authentication failed`. |
| Prepay credit depleted | VERIFIED (api-errors) | HTTP 402, code `payment_required`. | 402 → `quota exhausted`. Form UNVERIFIED for `generateContent`. |
| Per-minute vs daily quota | VERIFIED but not separable for `generateContent` | api-errors names `rate_limit_exceeded` (per-minute or per-second) and `quota_exceeded` (daily quota), both 429. The rate-limits page says spend-based limits return "a 429 RESOURCE_EXHAUSTED error" and that exceeding RPM, TPM or RPD "will trigger a rate limit error" with no distinguishing code. | Owner rule: no distinction usable for `generateContent` → **every Gemini 429 is transient** (no `quota exhausted` from a 429). |
| `error.details[].reason` / `QuotaFailure` | UNVERIFIED | Not on any of the three pages. | Not used. |
| 400 expired key | UNVERIFIED | The owner notes an expired Gemini key usually arrives as 400. Not confirmed on the pages above. | Kept transient (today's behaviour). |
| 500, 503, 504 | VERIFIED | `api_error`, `service_unavailable`, `deadline_exceeded`. | Transient. |

## Allowlists in the implementation

Read only from the parsed JSON of a non-200 body, only these fields: `error.code`, `error.type`, `error.status` (strings).

- `quota exhausted` (any status): OpenAI `insufficient_quota`, `credit_balance_exhausted`, `organization_spend_limit_exceeded`, `project_spend_limit_exceeded`, `organization_usage_limit_exceeded`; Gemini `payment_required` (or HTTP 402).
- `authentication failed` (HTTP 401 or 403, any body).
- `key expired`: reserved; no code is verified, so nothing maps to it.
- `no key`: config only (no key set).

Anything else, including unknown codes, is transient.

## Open points for the owner

1. Gemini `generateContent` error body: confirm whether it carries the snake_case codes above or only the `error.status` strings, and whether a spend-limit 429 is distinguishable. Until then all Gemini 429 are transient.
2. Expired-key detection for both providers is UNVERIFIED. The `key expired` reason exists but nothing emits it.
