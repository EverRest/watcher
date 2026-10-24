# Data Model: Metrics, Sentry Issues and Trace Links

No new persisted key kind, no new table. Everything below is an in-memory value object unless the "Persisted" column
says otherwise. All numbers are finite floats/ints; all text is `Normalizer`-masked before it can leave the process.

## Value objects

| Type (namespace `Watcher\...`) | Fields | Invariants | Persisted |
|---|---|---|---|
| `Metrics\MetricDefinition` | `name` (`^[a-z][a-z0-9_]{0,39}$`), `query` (private, <= 2,000 chars), `aggregate` (`sum\|max\|avg\|last\|null`), `threshold` (`relative` float > 0, `floor` float >= 0, or null), `unit` (`^[A-Za-z%/ ]{0,8}$`), `mode` (`instant\|range`) | built by `WatchConfig`; never `__toString`s the query | no (config) |
| `Metrics\MetricResult` | `values` list<float> (finite only), `series` int, `allowedLabelValues` list<string> (<= 5, raw), `timestamps` list<int> (range only) | empty `values` = no data; `series` counts returned series before filtering | no |
| `Metrics\MetricReading` | `name`, `unit`, `current` ?float, `baseline` ?float, `state` (`ok\|no_data\|no_baseline\|ambiguous`), `series` int, `labels` list<string> (masked, <= 5) | `relativeChange()` null unless both numbers and `baseline > 0`; `absoluteChange()` null unless both | no |
| `Metrics\MetricsContext` | `readings` list<MetricReading>, `unavailable` bool | `names()`, `pairs()` for the compact payload; `line()` for the digest | no |
| `Issues\IssueRecord` | `id` (digits), `title` (<= 300), `culprit` (<= 120), `level` (`Level`), `firstSeen`, `lastSeen`, `countInWindow` int >= 0, `isRegression` bool | exactly the allowlist (research R7); constructor rejects anything else by having no other parameter | no |
| `Context\TraceIdCollector` | map fingerprint => `{id, time}` | one entry per fingerprint, earliest by event time; id matches `^[0-9a-f]{16,32}$` (stored lower-case) | **never** |
| `Analyze\PayloadExtras` (shared with 010) | `metrics` ?MetricsContext, `endpoints` map fp => string | immutable; null/empty = no change to the payload | no |
| `Notify\DigestExtras` (shared with 010) | `metricsLine` ?string, `metricsNote` ?string, `unavailable` map<`metrics\|sentry\|api_context`, failure token>, `endpointLines` map fp => string, `endpointsSkipped` int | all strings already masked; counts only otherwise | no |

## Synthetic problem groups

| Kind | `fingerprint` | `template` | `level` | `count` | Reason | Source of truth |
|---|---|---|---|---|---|---|
| Sentry issue | `sentry:{id}` | masked title, or null when `count < min_group_events_for_text` | mapped from Sentry level | sum of `stats` buckets in window | existing (`New`/`Grown`/`Level`/`TopVolume`) | `IssueGroupBuilder` |
| Metric anomaly | `metric:` + 12 hex of `sha256(service\|metricName)` | `metric anomaly: {name}` | `Warning` | `max(1, round(relativeChange*100))` (percent increase) | `MetricAnomaly` | `MetricAnomalies` |

Both are ordinary `ProblemGroup`s: they pass `Redactor::redact()` (layer 2), `Budget::truncate`, `CompactPayload`,
`Keys::reported`, `Keys::verdict`, and are written to the window snapshot's `groups` map (fingerprint => count) so the
next run compares them with the existing rule. `GroupStatus` for a metric anomaly is resolved against
`$prev['groups'][$fingerprint]` only (plan, Complexity Tracking).

## State keys (existing, unchanged)

| Key | Use by this feature |
|---|---|
| `snapshot:{service}:{windowEnd}` | `groups` also holds `sentry:*` and `metric:*` fingerprints. Same TTL (`state.retention`). A metric **reading** is not stored; only the anomaly's percent count is. |
| `reported:{service}:{fingerprint}` | dedupe for both kinds (existing rule: re-report only when `Grown`) |
| `verdict:{service}:{fingerprint}` | verdict cache for both kinds |
| `run:`, `hb:`, `spend:` | unchanged |

No key holds a trace id, a query, a label value, a Sentry title or a token.

## Compact model input (`CompactPayload`) additions

| Section | When present | Shape |
|---|---|---|
| `m` | metrics configured and >= 1 reading | `{"n":[name,...],"v":[[current,baseline],...]}`; `null` entries for no data; 3 significant digits |
| group row `mi` | group is a metric anomaly | int index into `m.n` |
| Sentry group rows | Sentry configured | ordinary rows (`fp`, `t`, `lv`, `c`, `st`, ...) with `fp` prefixed `sentry:` |

Never present in the payload: trace ids, metric label values (unless allowlisted and masked, and then only in the digest
line, not in `m`), query text, Sentry user/tag/breadcrumb/permalink/shortId.

## `--json` summary additions (counts only, keys present only when the part is configured)

```json
"metrics": {"queries": 4, "anomalies": 1, "tokens": 38},
"sentry":  {"requests": 1, "issues": 12, "dropped": 0, "tokens": 210}
```
