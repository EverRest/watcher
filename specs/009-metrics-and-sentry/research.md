# Research: Metrics, Sentry Issues and Trace Links

Only decisions where more than one design was reasonable. Facts are from the code at `baefdeb`. Anything about a
third-party API shape that could not be checked against a live instance is marked **UNVERIFIED** and is pinned by a
recorded fixture or golden test, not by a live call.

## R1. Interface shapes (`MetricSource`, `IssueSource`)

- **Decision**:
  ```php
  interface MetricSource {
      /** @throws SourceUnavailable network, auth, timeout, malformed body, non-success status */
      public function instant(string $query, DateTimeImmutable $at): MetricResult;
      /** @throws SourceUnavailable; refuses (InvalidArgumentException) when ($end-$start)/$stepSeconds > $maxPoints */
      public function range(string $query, DateTimeImmutable $start, DateTimeImmutable $end, int $stepSeconds): MetricResult;
  }
  final class MetricResult { /** @param list<float> $values finite samples only, one per series (instant) or per sample (range, single series) */
      public function __construct(public array $values, public int $series, public array $allowedLabelValues = [], public array $timestamps = []) {} }

  interface IssueSource {
      /** @return list<IssueRecord> at most $limit, unresolved only; labels dropped at the boundary
       *  @throws SourceUnavailable */
      public function issues(Window $window, int $limit): array;
  }
  ```
  `SourceUnavailable` (existing `Watcher\Source\SourceUnavailable`) is reused: it already means "optional read failed,
  say so, continue". Neither interface extends `LogSource`; both live in their own namespace (`Watcher\Metrics`,
  `Watcher\Issues`) so `ArchitectureTest::testEverySourceImplementationIsCovered` (which enumerates `LogSource`
  implementers) is unaffected, and get their own coverage test (T008, T027).
- **Why numbers-in, numbers-out**: the interface returns plain floats and a series count. Labels are dropped **inside the
  adapter**, so no caller can leak one by forgetting to drop it (Principle I, "drop at the boundary"). Only label names
  in the adapter's constructor allowlist survive, as raw strings capped at 5 distinct values; `MetricsReader` passes them
  through the `Normalizer` before they can be shown.
- **Why `series` is returned**: the `aggregate` rule needs "how many series came back" to report `ambiguous` (spec edge case)
  without keeping the series.
- **Alternatives**: one `query(string, Window): float` (hides ambiguity and the aggregate); returning the decoded Prometheus
  array (keeps labels in memory across the boundary, the exact thing the spec forbids).

## R2. Query templating and placeholders

- **Decision**: `QueryTemplate::compile(string $text, array $vars)` at config load. Placeholders match
  `\{([a-z][a-z0-9_]*)\}` and are replaced in **one pass** (`preg_replace_callback`), so a substituted value is never
  scanned again. This pattern cannot match PromQL selectors (`{job="x"}`, `{}`, `{__name__=~"a"}`), which contain `=`, `"`
  or no name.
- **Built-in values** (all from config or the `Window`): `{service}` = `service.name`; `{window}` = window length in
  seconds with an `s` suffix (`86400s`, valid PromQL duration); `{start}`, `{end}`, `{baseline_start}`, `{baseline_end}` =
  Unix seconds (integer). `metrics.vars` entries add names; a `vars` key that shadows a built-in is a config error.
- **Value validation** (injection guard, FR-003): every `{service}` and `vars` value must match
  `^[A-Za-z0-9_.:/\-]{1,64}$`. No quote, brace, backslash, space or newline can be injected into the query. A service name
  outside this set fails config validation naming `service.name` ("metrics queries need a name of letters, digits and _.:/-").
- **Unknown placeholder** = `InvalidConfig("metrics.queries.<name>.query uses unknown placeholder {x}")`, thrown by
  `WatchConfig` before any external call even when the section is `enabled: false` (so a flag flip never reveals a bad query,
  same rule as `watch_rules` in `WatchConfig`).
- **Text never echoed**: `MetricDefinition` keeps `name` and a private `query`; `__toString`/exceptions carry only the name.
- **Limit**: query text <= 2,000 chars (config error beyond), so a pathological config cannot make a 1 MB request.

## R3. Reducing a result to one number; `aggregate`; range mode

- **Decision**: `aggregate` in {`sum`,`max`,`avg`,`last`}, applied by `MetricReading::reduce()` in code over `MetricResult::values`.
  - `values === []` (empty vector, all NaN/Inf filtered out by the adapter) = **no data** (`null`), never `0`.
  - `series > 1` and `aggregate` omitted = `ambiguous` -> metric skipped, digest shows `<name>: ambiguous (N series)`.
  - A single series needs no aggregate.
- **Reconciliation of the spec**: spec edge case says "default `sum` for rates, `max` for latency" but a definition has no
  kind. There is no safe implicit default: `sum` on a latency or `max` on a ratio is a wrong number shown with confidence.
  So: no implicit default; the shipped docs examples declare `aggregate: sum` (error ratio) and `aggregate: max` (p95). This
  is a stricter reading of the same edge case; flagged in plan "Spec drift" and in the open decisions.
- **Instant (default)**: two `instant()` calls per metric: current at `{end}`, baseline at `{baseline_end}`. Authors write
  `sum(rate(http_requests_total{service="{service}",code=~"5.."}[{window}]))`. `{window}` is the lookback, so both calls
  cover the same length. SC-002 (<= 2 queries per metric) holds.
- **Range (`mode: range`)**: one `range()` from `{baseline_start}` to `{end}` with
  `step = max(60, ceil((end - baseline_start) / metrics.max_points))`; `max_points` default 500, so a 48 h span gives a step
  of 346 s -> 500 samples. The adapter refuses client-side when `(end-start)/step > max_points` (spec edge case). Samples are
  partitioned by timestamp into baseline `[baseline_start, baseline_end)` and current `[start, end]`; `aggregate` applies
  per partition. **Single series only** (`series > 1` = ambiguous). Range exists because FR-001 requires the capability and
  some backends bill per query; it is the less-used path and the only place a `ponytail`-style ceiling lives.
- **Relative change**: `(current - baseline) / baseline`; baseline `null` or `<= 0` -> "no baseline" and no relative change
  (a zero baseline would divide by zero and "infinite" is not information).
- **Rounding for display**: 3 significant digits; unit suffix from `unit` (free text, <= 8 chars, validated `^[A-Za-z%/ ]{0,8}$`).

## R4. Windows

- **Decision**: current = the run's `Window`; baseline = the previous window of equal length ending at `$window->start`
  (`new Window($start - length, $start)`). This is the owner decision "baseline is the previous window until spec 004 modes
  apply". No new time logic: only `DateTimeImmutable::sub()` on UTC instants (DST-safe because `Window` is UTC).
- The baseline window is **not** `BaselineResolver`'s chosen comparison (rolling/same-weekday). Documented ceiling in the plan.

## R5. Anomaly candidates

- **Rule (code only)**: for metric `m` of this service with `threshold: {relative, floor}`: anomaly iff
  `baseline !== null && current > floor && (current - baseline)/baseline >= relative` **and** the pre-filter selection holds
  no candidate with `group->level->atLeast(Level::Error)`. "Pre-filter" = `$selection->candidates` before the
  `Keys::reported` loop, so an Error group that was reported yesterday still counts as the explanation. Sentry groups are in
  that list, so a Sentry error also explains a metric move (stated in the spec's intent: "no log finding explains it").
- **Candidate shape**: a synthetic `ProblemGroup`: `fingerprint = 'metric:' . substr(hash('sha256', $service . '|' . $metricName), 0, 12)`
  (12 hex, same length as `Fingerprinter` ids, prefix prevents collision with log ids and `sentry:`); `template =
  'metric anomaly: ' . $name` (declared name, passed through `Normalizer`); `level = Level::Warning`; `errorType = null`;
  `frames = []`; `count = max(1, (int) round(relativeChange * 100))` (percent increase; this is the only number the generic
  growth rule can use); `firstSeen = window start`, `lastSeen = window end`. Reason `CandidateReason::MetricAnomaly`
  (new case, weight 5 = same as `Level`, so it sorts with error-level groups; priority = 5*10 + Warning).
- **Why a synthetic `ProblemGroup`** rather than a new candidate type: everything downstream (`Redactor::redact`,
  `Budget::truncate`, `CompactPayload`, verdict cache, `reported` key, digest sections) takes `ProblemGroup`/`RedactedGroup`.
  A new type would touch every one of them; one enum case and one factory touches two files. The numbers do not travel in
  the template (the `Normalizer` would mask them as `<N>`); they travel in the `m` section (R6) and the group row carries
  `mi` = index of its metric there.
- **Status / cache**: `status = GroupStatus::resolve($count, $prev['groups'][$fp] ?? null, growth_factor)` from the previous
  window's snapshot. The anomaly group is appended to `$allGroups` so the next day's snapshot holds its count; the existing
  `reported` key and verdict cache then give "not re-analysed unless it grew by `growth_factor`" with no new rule (US2 AC3).
- **Cap**: sort by relative change desc, then name; keep `metrics.max_anomalies` (default 3); the digest states
  `N metric anomalies not shown` for the rest (US2 AC5).
- **Model input** (US2 AC4): only metric name, the two numbers (section `m`), window (already in the prompt). No log text.

## R6. Metric context in the compact payload

- **Carrier**: the model input is built in five places that all call `CompactPayload::encode($groups, $deps)`
  (`Watcher.php:626` truncation, `AnthropicAnalyzer.php:131`, `ModelRouter.php:177`, `EstimatingAnalyzer.php:31,48`,
  `Budget::fitDependencies` at `Budget.php:69`). A new immutable `Analyze\PayloadExtras` (`?MetricsContext $metrics`,
  `array $endpoints` fp => line; the endpoints half belongs to spec 010) is carried by `TriageRequest` and `AnalysisRequest`
  as one new optional constructor argument and passed to `encode($groups, $deps = [], ?PayloadExtras $extras = null)`. One
  parameter instead of one per feature; null keeps every existing call and golden byte-identical.
- **Shape**: one key `m` after `d`: `{"n":["error_rate","p95"],"v":[[0.004,0.001],[820,310]]}`: names once, one
  `[current, baseline]` pair per metric (`null` for no data), 3 significant digits. A group row of a metric anomaly carries
  `"mi": <index into n>`.
- **Which step**: the analysis step (and escalation) always gets `m` when readings exist; the **triage** step gets it only when
  the batch holds a metric-anomaly group (its numbers are the group's whole content). So a service with metrics and no anomaly
  pays the <= 60 tokens once (analysis), not twice (SC-002).
- **Budget**: `Budget::fitMetrics()` mirrors `Budget::fitDependencies()`: if the step's `max_input_tokens` would be exceeded,
  `m` is dropped before any group is (metrics are context, not evidence) and the digest says so. The 60-token cap is applied by
  keeping the first `floor(60 / 20)` = 3 readings and counting the rest as dropped (SC-002). Tokens are estimated with
  `Budget::estimateTokens()` (bytes / 3, conservative). **Exception**: an anomaly's own `m` entry is never dropped (without it
  the group has no content); if it cannot fit, the anomaly is dropped as a candidate and counted as "not triaged".
- **Prompt**: one added sentence in the system prompt **only when `m` is present**: "`m` = measured metric values
  [current, baseline]; they describe impact, not cause." The prompt prefix stays byte-stable for provider caching when metrics
  are off.

## R7. Sentry: endpoint, field allowlist, fingerprint, counts

- **Endpoint**: `GET {sentry.base_url}/api/0/projects/{organization}/{project}/issues/` with query
  `query=is:unresolved lastSeen:>={start-iso}`, `sort=freq`, `limit=min(100, max_issues)`, `statsPeriod=` omitted,
  `groupStatsPeriod=24h` (window <= 24 h) or `14d` (longer), `Authorization: Bearer {token}`. Paging follows the `Link`
  header `rel="next"; results="true"` until `max_issues` issues. **UNVERIFIED** against a live Sentry (parameter names
  from public API documentation): pinned by `tests/Fixtures/sentry/issues_page.json`, `issues_page2.json`.
- **Field allowlist** (FR-011), explicit pick, type-checked; everything else is never read into an object:

  | Kept key | Type check | Becomes |
  |---|---|---|
  | `id` | numeric string | `IssueRecord::$id` (digits only; link + fingerprint) |
  | `title` | string, cut to 300 chars **before** masking | `ProblemGroup::$template` after Normalizer |
  | `culprit` | string, cut to 120 chars | `frames[0]` after Normalizer |
  | `level` | one of `fatal,error,warning,info,debug` | `Level` (fatal->Critical, error->Error, warning->Warning, info->Info, debug->Debug) |
  | `firstSeen`, `lastSeen` | ISO-8601 | `DateTimeImmutable` UTC |
  | `status` | `unresolved` only is kept | filter |
  | `substatus` (or `isRegression`) | `regressed` / bool | `isRegression` flag |
  | `stats[<period>]` | list of `[ts, count]` | windowed count (below), then dropped |

  Dropped at the boundary: `permalink`, `shortId`, `metadata`, `count`, `userCount`, `project`, `assignedTo`, `annotations`,
  `tags`, `lifetime`, `stats` after summing, and every unknown key. A title/culprit that is not a string, not valid UTF-8
  or throws in the Normalizer **drops the issue** (Principle I), counted in `RunStats::sentryDropped`.
- **Fingerprint**: `'sentry:' . $id`. The list endpoint returns the group id and no hash; the hash needs the hashes
  endpoint, which FR-010 forbids. The id is Sentry's own, stable for the group's life, and is what the issue URL uses.
  **Spec FR-013 reads "hash or id"**, so this is within it.
- **Window count** (**UNVERIFIED**, owner decision O1): the list's `count` is lifetime and would make every old issue look
  "grown". The windowed count is the sum of `stats` buckets with `bucket_ts` in `[start, end)` (hourly for
  `groupStatsPeriod=24h`, daily for `14d`). Buckets are whole: the count can be off by at most the first/last bucket;
  documented. If `stats` is missing or malformed the issue is dropped (cannot be compared honestly), not guessed.
- **New / grown / level**: `firstSeen >= window start` -> new comes from `GroupStatus` via the snapshot, **not** from
  Sentry's `firstSeen`: a group is "new" when its fingerprint was absent from the previous snapshot (the existing rule). A
  Sentry issue first seen in the window is by definition absent. A regression (it had no events yesterday) is also absent from
  the previous snapshot, so it is `New` with no extra enum case; `isRegression` only adds a "(regression)" word in the digest line.
- **Withholding (FR-012)**: `template = null` when `count < minGroupEventsForText` (the service's
  `redaction.min_group_events_for_text`, the same input `Reducer` receives), else `Normalizer::normalize(title)`; then the
  generic `Redactor::redact()` runs on the group like on any candidate (second layer).
- **Resolved / ignored**: `query=is:unresolved` excludes both server-side; the reader also drops `status !== 'unresolved'`.
- **Link**: `{base_url}/organizations/{organization}/issues/{id}/` built from config and the numeric id; never called.

## R8. Where Sentry groups join the pipeline

- **Decision**: after `$groups = $reducer->reduce($events)` (`Watcher.php:548`), before `$allGroups = $groups`:
  `$groups = [...$groups, ...$sentryGroups]` then the existing re-sort by count desc/fingerprint. All later stages
  (mute filter, `BaselineResolver`, `$rules->apply`, `Selector`, `Budget::truncate`, `writeSnapshot`) see them as
  groups. Watch rules do not fire on them (rules run on events). Muting by `fingerprint` or by template matchers applies.
- `top_n` and the budget are shared (spec AC3). `grouping.message_cap` and the group cap (`source.group_cap`) apply only to log
  clusters; Sentry adds <= `max_issues` groups.
- **No merge** with log groups (owner decision), two entries, each with its own link set.

## R9. Trace ids: capture, validation, links

- **Capture**: trace id comes from `LogEvent::$fields[<trace_field>]` (config `links.tempo.trace_field` or
  `links.datadog_apm.trace_field`, default `trace_id`). A `?Closure(LogEvent, string $fingerprint): void` is added to
  `Reducer` (called right after `$fp` is computed, `Reducer.php:75`); `TraceIdCollector` keeps, per fingerprint, the id of
  the earliest event by `LogEvent::$time` (FR-017: at most one id per group). Valid iff
  `preg_match('/^[0-9a-f]{16,32}$/i', $id)` and `strlen` in range; lower-cased.
- **Where it lives**: a local variable in `Watcher::attempt()`; passed to `TraceLinkBuilder::forGroup($redactedGroup, $traceId, $window)`.
  It is not a property of `ProblemGroup` or `RedactedGroup`, so it cannot reach `CompactPayload`, `Keys`, verdict cache,
  snapshot or `RunStats` by construction. An architecture test greps `src/Analyze`, `src/State`, `src/Privacy` and
  `RedactedGroup` for `trace` (T041).
- **Sentry groups and metric anomalies** have no trace id; they get the service-level search link only.
- **URL shapes (all UNVERIFIED, golden tests)**:
  - Tempo via Grafana Explore: `{base}/explore?schemaVersion=1&panes=` + rawurlencode(JSON
    `{"a":{"datasource":uid,"queries":[{"refId":"A","queryType":"traceql","query":<q>,"datasource":{"type":"tempo","uid":uid}}],"range":{"from":ms,"to":ms}}}`) + `&orgId=1`,
    where `<q>` is the trace id (lower-case hex) or, with no id, `{resource.service.name="<service>"}` (escaped like
    `LogLinkBuilder::esc`). Service name from `links.tempo.service_name` (default `service.name`).
  - Datadog APM: `{app_url}/apm/traces?query=` + rawurlencode(`trace_id:<id>` or `service:<svc>` + stream-tag `env:` when
    present) + `&start=<ms>&end=<ms>`. Datadog stores 64-bit ids as decimal in some UIs; the search form with `trace_id:`
    accepts both forms per Datadog docs, so this avoids `/apm/trace/<id>` whose id format is stricter (owner decision O3).
- **Selection of backend**: `links.tempo` wins over `links.datadog_apm` when both are set, same precedence as
  `LogLinkBuilder` (Grafana before Datadog). `links.datadog_apm` requires `links.datadog.app_url`.

## R10. Configuration shape and flags

- **Decision**: new top-level sections `metrics` and `sentry`, and new sub-keys `links.tempo`, `links.datadog_apm`.
  **Absent section = off.** A present section may carry `enabled: false` (reuses `WatchConfig::reserved()`). They are
  **not** added to `WatchConfig::FLAGS`: `FLAGS` all default to **true** (spec 012), and these default to off. `Watcher`
  asks `$c->metrics !== null`, not `$c->on(...)`. `FeatureFlagsArchitectureTest` still passes (no flag names privacy).
- Both sections are validated even when `enabled: false`. Unknown keys rejected with the key path. Secrets: `metrics.source.auth.token`
  and `sentry.auth_token` read from the host (`getenv()` in the PHP config, as `source.loki.auth.token` does); never in an
  error message (messages carry key paths only, `WatchConfig` header comment).
- `links.tempo` / `links.datadog_apm` are added to the `self::only($links, [...])` whitelist (`WatchConfig.php:770`).
  `links.logs` flag semantics are unchanged: `links.tempo`/`datadog_apm` are active when configured and `links.logs` is on.

## R11. Digest and notices

- **Decision**: one `DigestExtras` value object (new, shared with spec 010) appended as the **last** optional `DigestData`
  constructor argument: `metricsLine`, `metricsNote` (truncation / dropped), `unavailable` (map tag => fixed failure token;
  tags `metrics`, `sentry`, and `api_context` from spec 010), `endpointLines`, `endpointsSkipped`. Appending one nullable argument avoids reshuffling the 40-argument
  positional constructor and keeps every existing call site and byte output unchanged when null.
- **Metrics line** under the digest header, one per service (the service is the run): `Metrics: error rate 0.4% (was 0.1%, +300%),
  p95 820 ms (was 310 ms, +165%)`; `no baseline` instead of a percentage (US1 AC5); `ambiguous (N series)`.
- **Unavailable**: `Metrics unavailable` / `Sentry unavailable` in the digest footer notes (the same place as
  `failedDependencies`). When the run is otherwise quiet there is no digest, so the failure goes through the existing host
  log (`StderrLogger`, `warning`) and the heartbeat is unaffected; this matches how dependency read failures behave today.
  If metrics were the *only* reason a digest would exist (anomaly) and the read failed, nothing is sent (nothing to say).
- **Exit status**: unchanged (existing optional-context rule: `readDependencies` failures do not change the outcome).
- **Links**: per-fingerprint `$links[$fp]` gains `'trace'` (string|null) and `'sentry'` (string|null); `DigestRenderer`
  prints them where `'log'`/`'code'` are printed.

## R12. Counts for `--json`, budgets

- `RunStats` gains `metricQueries`, `sentryRequests`, `sentryIssues`, `sentryDropped`, `metricAnomalies`,
  `metricTokens`, `sentryTokens` (ints). `sentryTokens` = estimated tokens of the Sentry groups' rows in the payload
  (`Budget::estimateTokens(CompactPayload::encode(sentryOnly))`), `metricTokens` = estimate of the `m` section + anomaly rows.
  They are counts only (FR-022), added to `JsonSummary` under keys `metrics` and `sentry` only when those parts are
  configured (absent otherwise: byte-identical default). `RunSummaryTest`/`JsonSummaryPrivacyTest` extended.

## R13. Doctor

- Two independent checks added to `Doctor` (run only when the section exists): `metrics` = `instant('vector(1)', now)`,
  pass iff one finite value `1.0`; `sentry` = `issues(Window 5 min, limit 1)` pass iff no exception. Reasons come from a
  fixed vocabulary (`unreachable`, `unauthorized`, `timeout`, `bad_response`); never the URL, token or query. No post, no
  model call. With `--json` they appear in the existing check list.

## R14. Dependencies

- None. Guzzle already issues the HTTP calls (`Factory::http()` options: timeouts, redirects limited as for Loki). JSON only.

## Open owner decisions (carry into the report)

- **O1 (Sentry windowed count)**: windowed count = sum of `stats` buckets (hour/day granularity). Accept, or add the
  events-stats endpoint (needs FR-010 widened)?
- **O2 (Aggregate default)**: no implicit `aggregate` (ambiguous + skipped when >1 series). Accept, or keep spec's
  "sum for rates / max for latency" via a `kind` field?
- **O3 (Datadog trace URL)**: search URL `apm/traces?query=trace_id:` rather than `apm/trace/<id>` (id format risk). Accept?
- **O4 (Anomaly level)**: synthetic group is `Level::Warning`, reason weight 5. Accept, or add a distinct display tag?
