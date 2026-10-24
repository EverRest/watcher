# Data Model: Incident Workflow (v1)

Config shapes: [contracts/config.md](contracts/config.md). Text layouts: [contracts/ticket-draft.md](contracts/ticket-draft.md),
[contracts/known-issues.md](contracts/known-issues.md), [contracts/weekly-report.md](contracts/weekly-report.md).

## Objects (new, namespace `Watcher\Incident` and `Watcher\Report`)

| Object | Kind | Role |
|---|---|---|
| `KnownIssue` | immutable value | `fingerprint`, `ticket`, `resolved: bool`, `until: ?string` from `known_issues`. |
| `KnownIssues` | service | `partition(list<ProblemGroup>, DateTimeImmutable $now): KnownResult` using `KnownReferences`; pure apart from the state read. |
| `KnownResult` | immutable value | `kept` (groups to select from), `lines` (`list<KnownLine>`), `grown` (fingerprints -> `known_grown`), `labels` (fingerprint -> label text), counts. |
| `KnownReferences` | state wrapper | `get/put` of the reference count with expiry; writes through the run's `BufferedStateStore`. |
| `TicketDraft` | pure builder | `Finding` + `RedactedGroup` + release + links + known key -> `Message` with one `draft` section. |
| `DraftPlan` | pure | selects and orders findings (severity desc, count desc, fingerprint asc), applies `max_per_run`, skips already-sent markers. |
| `RouteTable` | pure | ordered `Route{name, severities, Destination}`; `assign(list<Finding>): array<routeIndex|'default', list<Finding>>`. |
| `WeeklyReport` | pure | `EventLog` + week + `KnownIssues` config -> `ReportData`; renderer -> `Message`. |
| `EventLog` | reader | reads `observe.file` files for a week, allowlist-parses lines, counts skipped; no writes. |

`CandidateReason::KnownGrown = 'known_grown'` (new case). `Message` section kind `draft` (new, formatter-handled). No new
contract: `Notifier` is reused; concrete classes only (Principle VIII).

## State (existing store, new keys, all with expiry)

| Key (`Keys::` kind) | Value | TTL | Used for |
|---|---|---|---|
| `known:<service>:<fp>` (`Keys::KNOWN`) | `{ref: int, seen: ISO date}` | `state.retention`, renewed per sighting | reference count (FR-002) |
| `sent:<service>:<windowEnd>:r<i>` (`Keys::DELIVERED` + route suffix) | `{at}` | `DELIVERED_TTL` | route delivered marker (FR-022) |
| `draft:<service>:<windowEnd>:<fp>` (`Keys::DRAFT`) | `{at}` | `DELIVERED_TTL` | draft sent marker (FR-012) |
| `rsent:<service>:<isoWeek>` (`Keys::REPORTSENT`) | `{at}` | 14 days (like `WEEKSENT`) | report sent marker (FR-032) |

`<service>` is the existing state-key prefix (`service@environment`). Stored: counts, dates, ticket-free markers. Never
stored: log text, finding text, draft text, ticket keys (keys live in configuration only).

## Derivations

- Known (suppressed): entry open, `until` null or >= today UTC, `status` open, `count < growth_factor * ref` (a missing
  `ref` is set to `count` at first sight).
- `known_grown`: same but `count >= growth_factor * ref`; after delivery `ref := count`.
- Route assignment: first route with `finding.severity in route.severities`; else default.
- Report week: ISO week in UTC, `window_end` of `run` events decides the day.
- Recurring: distinct days (by `window_end` date) a fingerprint appears in `group` events; top 5 by days, then summed count.
- Stopped after release R: seen before the first run whose `release` is R, absent from every `group` event of at least 2
  later runs of the week.
- Noisiest: summed `count` over the week's `group` events, top 5.
- Spend: sum of `run.tokens_in/out/cached` and `run.cost_usd` per `service@environment`.
