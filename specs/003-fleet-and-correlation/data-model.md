# Data Model: Fleet Mode and Cross-Service Correlation

Persisted state adds **no new key kind**. In-memory types live in `src/Fleet/`. Nothing here holds log text, field
values or secrets (Principle I): service names, fingerprints, counts, levels, times and already-redacted templates only.

## Persisted (existing key kinds, new service component)

Fleet id `F = "fleet/" . {fleet name}`; `Keys` URL-encodes it. Service names containing `/` are rejected at load (R2).

| Key | Writer | Meaning | TTL |
|---|---|---|---|
| `spend:{F}:{day}:usd`, `spend:{F}:{day}:tokens` | `FleetBudget` via `Spend` | fleet daily totals, calendar day in the **fleet** `timezone` (default the first entry's) | `SPEND_TTL` (48 h) |
| `alert:{F}:{day}:{cost\|tokens}:{pct}` | `FleetBudget` via `BudgetAlerts` | fleet threshold already announced | `SPEND_TTL` |
| `hb:{F}`, `trig:{F}` | `FleetRunner` via `Heartbeat` | fleet heartbeat (same record as 002 data-model) | 3 x `state.retention` |

Per-service keys (`snap`, `run`, `lock`, `spend`, `verdict`, `reported`, `hb`...) are untouched and remain per service.

## FleetConfig (validated, immutable)

| Field | Type | Rule |
|---|---|---|
| `name` | string | required, `[A-Za-z0-9._-]+`, used in `F` |
| `services` | `list<ServiceEntry>` | 1..n, order = FleetOrder |
| `digest` | `per_service` \| `combined` | default `per_service` |
| `budget.daily_budget_usd` | float > 0 | optional; absent = no fleet cap |
| `budget.daily_token_limit` | int > 0 \| null | optional |
| `budget.alert_percents` | list<int> | default `[25,50,75,90]` |
| `notify` | map | fleet default destination (webhooks), merged under each entry's `notify` and used for the combined digest and the correlated finding |
| `time_budget` | ISO-8601 duration \| null | default null |
| `correlation.enabled` | bool | default true |
| `correlation.tolerance` | ISO-8601 duration | default `PT0S` |
| `timezone` | IANA | default `UTC`; day boundary for the fleet counters |

Load failures (all before any external call): duplicate `service`; `/` in a service name; `state` sections differ
between entries; `analysis` credential differs between entries; unknown fleet key; invalid entry (the `WatchConfig`
message prefixed with `services[i] (name)`). The first error names the entry and key; duplicates name both entries.

## ServiceEntry

| Field | Type | Meaning |
|---|---|---|
| `config` | `WatchConfig` | complete service configuration after `defaults` merge |
| `priority` | int | default 0, higher first |
| `provides` | `list<string>` | dependency names other services use for this one; default `[service]` |

## FleetResult

| Field | Type |
|---|---|
| `fleet` | string |
| `services` | `list<{service, outcome, reason, result: ?RunResult}>`; `outcome` is a `RunOutcome` value or `skipped_time` |
| `correlated` | `list<CorrelatedFinding>` (empty when none or disabled) |
| `correlatedPosted` | `bool\|null` (null = nothing to post) |
| `exitCode()` | 2 invalid definition; 1 if any `failed`, any `skipped_time`, or a failed correlated/combined post; else 0 |

## FleetSeam (per fleet run)

Registry `service -> list<RedactedGroup>` (candidates after redaction, the same objects sent to the notifier path),
the shared fleet `Spend`, and the annotation map `service -> fingerprint -> text`. Lives for one `FleetRunner::run()`;
not persisted.

## CorrelationLink

| Field | Type |
|---|---|
| `origin` | `{service, fingerprint, level, status, count, previousCount, template, firstSeen, lastSeen}` |
| `consumer` | `{service, fingerprint, level, count, firstSeen, lastSeen}` (no template) |
| `dependency` | string, the declared name that matched |
| `basis` | `{host_reference: true, window_overlap: true, origin_status: new\|grown}` |

## CorrelatedFinding

One per origin group: origin plus `consumers: list<{service, count, groups}>` (links grouped by consumer service),
`window {start, end}` (union of the linked groups), `hypothesis: true`, `text` (rendered). Shape in
contracts/correlated-finding.md.

## Transitions

Service entry: `pending -> (not_due | skipped_done | skipped_locked | reported | quiet | baselined | failed | skipped_time)`.
Consumer group: `candidate -> analysed and reported as usual`, plus an annotation and a place in the correlated finding when linked; unlinked
when no link or when the origin is not in the fleet (FR-016).
