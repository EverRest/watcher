# Data Model: Scheduled Log Watch

All entities are immutable value objects unless marked *persisted*. Persisted items live in the
StateStore with an expiry (default retention 30 days, FR-027). Field types are PHP types.

## Transient (never persisted)

### LogEvent

One normalised entry from a source (FR-003). Exists only inside a run; never stored (Principle I).

| Field | Type | Notes |
|---|---|---|
| `time` | `DateTimeImmutable` | UTC |
| `level` | `Level` enum | `debug, info, notice, warning, error, critical, alert, emergency` (PSR-3 order) |
| `message` | `string` | raw message, used only to derive the template |
| `errorType` | `?string` | exception class / error code |
| `frames` | `list<string>` | `file:line` or `Class::method`, max 5 kept |
| `fields` | `array<string,scalar>` | parsed context; never leaves the service (FR-009) |
| `labels` | `array<string,string>` | stream labels from the source |

### Totals

`array<level, int>` counted by the backend over the whole window (FR-036, research R1/R2). Feeds
the digest summary line and the baseline notice; covers levels below `read_min_level`. Compared with
the previous snapshot's totals: a level that grew ≥ `growth_factor` triggers the level-anomaly second
pass (FR-038) up to `anomaly_cap`.

Sources stream server-filtered `LogEvent`s lazily; the Reducer folds them into `ProblemGroup`s
keeping at most `sample_size` events per group in memory, discarded at the end of the run.

### ProblemGroup

Output of the Reducer, input to the Redactor and Selector (FR-005, FR-006).

| Field | Type | Rule |
|---|---|---|
| `fingerprint` | `string` | 12-hex prefix of sha256 over `level\|errorType\|frame0\|tokenCount\|firstTwoWords` (firstTwoWords = first two tokens of the Normalizer-masked message, lowercased). Deliberately independent of the mined template and of cluster volume, so the same problem keeps its fingerprint when its daily count drops below `min_group_events_for_text`. ponytail: messages identical in the first two words, token count, error type and top frame share a fingerprint; upgrade path is a persisted template tree |
| `template` | `string` | normalised message: digits → `<N>`, uuids/hex ids → `<ID>`, emails → `<EMAIL>`, ... |
| `level` | `Level` | highest level seen in the group |
| `errorType` | `?string` | |
| `frames` | `list<string>` | ≤ 5 |
| `count` | `int` | ≥ 1 |
| `firstSeen`, `lastSeen` | `DateTimeImmutable` | |
| `previousCount` | `?int` | from the previous window; `null` → no baseline |
| `status` | `GroupStatus` enum | `new` (previousCount null or 0), `grown` (count ≥ factor × previousCount), `unchanged` |
| `watchMatches` | `list<string>` | names of matching watch rules (FR-012b) |

### RedactedGroup

What crosses the perimeter (FR-009–FR-011). Only these fields exist, so nothing else can leak:
`fingerprint, template (pattern-scanned), level, errorType, frames, count, previousCount, status,
watchMatches, firstSeen, lastSeen`. Construction fails closed: a field that cannot be redacted is
replaced by `null`/omitted, never copied raw.

### Candidate

`RedactedGroup` + `reasons: list<CandidateReason>` (`new, grown, level, watch_rule, level_anomaly, top_volume`)
+ `priority: int` (used for budget truncation, FR-021). Muted groups never become candidates (FR-034).

### Finding

Validated model output (FR-023); see [contracts/analyzer-output.schema.json](contracts/analyzer-output.schema.json).

| Field | Type |
|---|---|
| `fingerprints` | `list<string>` (non-empty, each must be a candidate fingerprint) |
| `kind` | `bug, regression, anomaly, noise` |
| `severity` | `info, warn, error, critical` |
| `title` | `string` ≤ 80 |
| `evidence` | `string` ≤ 240 |
| `suspectedCause` | `string` ≤ 240 (always shown as hypothesis) |
| `suggestedAction` | `string` ≤ 200 |
| `confidence` | `float` 0–1 |
| `escalated` | `bool` (set by code, not by model) |

### Usage

Per step: `model, inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens, costUsd`. Summed per
run for the digest footer (FR-030) and for the daily cap (FR-022).

### Digest / Notice

Rendered message. See [contracts/slack-messages.md](contracts/slack-messages.md).

### DependencyContext (FR-039)

| Field | Type |
|---|---|
| `name` | `string` (declared name or detected host) |
| `declared` | `bool` |
| `totals` | `array<level,int>` and `previousTotals` |
| `topErrors` | `list<RedactedGroup>` ≤ 5 |
| `referencedBy` | `list<string>` fingerprints of the service's candidates that mention the host |

### DeployInfo (FR-041)

`release: ?string`, `commit: ?string`, `deployedAt: ?DateTimeImmutable` (from version label if any),
`changedSincePrevious: bool`.

### Links (FR-042, FR-043)

Per finding: `logUrl: string`; per frame: `codeUrl: ?string` (null when the path is not mapped).

## Persisted (StateStore, keyed by service)

| Item | Key | Value | Expiry |
|---|---|---|---|
| **WindowSnapshot** | `snap:{service}:{windowEnd}` | `map<fingerprint, count>` + totals + `{release, commit}` | retention |
| **DependencySnapshot** | `snap:{service}:dep:{name}:{windowEnd}` | totals + `map<fingerprint, count>` of error groups | retention |
| **Verdict** | `verdict:{service}:{fingerprint}` | `{triage: noise\|known\|interesting, finding?: Finding, atCount: int, at}` | retention |
| **Reported** | `reported:{service}:{fingerprint}` | `{lastReportedAt, atCount}` | retention |
| **RunRecord** | `run:{service}:{windowEnd}` | `{outcome, usage, startedAt, finishedAt}` — also the idempotency marker (FR-028) | retention |
| **Spend** | `spend:{service}:{yyyy-mm-dd}` | `{usd: float, tokens: int}`, incremented per request | 2 days |
| **BudgetAlert** | `alert:{service}:{yyyy-mm-dd}:{counter}:{percent}` | `{sentAt}` — marks a threshold already announced | 2 days |
| **Lock** | `lock:{service}:{windowEnd}` | owner token | lock TTL (default 15 min) |

No raw message, field, sample or label value is ever written (Principle I). Templates are stored
only inside a `Finding` and only after redaction.

### Rules

- Verdict reuse (FR-018): reuse while `count < growthFactor × verdict.atCount`; otherwise re-triage.
- Dedupe (FR-025): a finding is omitted from the digest when `reported` exists and the group is not `grown`.
- Baseline (FR-035): if no `WindowSnapshot` for the previous window exists, write the snapshot,
  post the baseline notice and stop.

## State transitions

### Run

```
triggered ──not due──► skipped (exit 0, no external call)
    │ due
    ▼
lock acquired? ──no──► skipped (exit 0)
    │ yes
    ▼
already has RunRecord? ──yes──► skipped (exit 0)
    │ no
    ▼
read source ──fail──► notice "source failed" ► failed (exit ≠ 0)
    ▼
no previous snapshot ──► baseline notice ► baselined (exit 0)
    ▼
select ── no candidates ──► quiet (exit 0, no AI, no message)
    ▼
budget left? ──no──► triage-only ► notice "budget exhausted"
    ▼
triage → analyze → escalate ── invalid output twice ──► notice "analysis failed" ► failed
    ▼
digest posted ──delivery fail──► host error output ► failed (exit ≠ 0)
    ▼
reported (exit 0)
```

### Group status per window

`new` → (next window, count stable) `unchanged` → (count ≥ factor × previous) `grown` → ...
A group absent from a window is simply not in that snapshot; if it reappears after its snapshot
expired it is `new` again.

## Configuration (WatchConfig)

Validated once at start (FR-015). Full key list and defaults:
[contracts/configuration.md](contracts/configuration.md).
