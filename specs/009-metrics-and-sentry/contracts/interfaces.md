# Contract: interfaces, HTTP surface, payload

## PHP interfaces (optional siblings of `LogSource`)

```php
namespace Watcher\Metrics;
interface MetricSource {
    /** @throws \Watcher\Source\SourceUnavailable */
    public function instant(string $query, \DateTimeImmutable $at): MetricResult;
    /** @throws \Watcher\Source\SourceUnavailable */
    public function range(string $query, \DateTimeImmutable $start, \DateTimeImmutable $end, int $stepSeconds): MetricResult;
}

namespace Watcher\Issues;
interface IssueSource {
    /** @return list<IssueRecord>  @throws \Watcher\Source\SourceUnavailable */
    public function issues(\Watcher\Schedule\Window $window, int $limit): array;
}
```

`LogSource`, `StateStore`, `Notifier`, `Analyzer` are unchanged. `Watcher::__construct` gains two **nullable** trailing
parameters `?MetricSource $metrics = null`, `?IssueSource $issues = null` (plus the parsed config in `WatchConfig`);
`Factory::metricSource(WatchConfig, Client): ?MetricSource` and `Factory::issueSource(WatchConfig, Client): ?IssueSource`
return null when the section is absent or `enabled: false`.

Shared contract suite: `tests/Contract/MetricSourceContract.php` and `IssueSourceContract.php` run against the fake
and the HTTP adapter fed by recorded fixtures (same pattern as the `LogSource` contract suite): empty result, finite
values only, label drop, `SourceUnavailable` on 401/403/429/5xx/timeout/non-JSON.

## HTTP surface (read-only; pinned by architecture tests)

| Adapter | Method + path | Notes |
|---|---|---|
| `PrometheusSource` direct | `GET {base_url}/api/v1/query?query=&time=` ; `GET .../api/v1/query_range?query=&start=&end=&step=` | when the URL-encoded request line would exceed 2,000 chars: `POST` the same path with `application/x-www-form-urlencoded` body (query endpoints accept it); never any other path |
| `PrometheusSource` via Grafana | same two paths under `{base_url}/api/datasources/proxy/uid/{datasource_uid}` | `Authorization: Bearer {token}`; **UNVERIFIED** proxy path shape, pinned by fixture |
| `SentryIssueSource` | `GET {base_url}/api/0/projects/{organization}/{project}/issues/` + query string, then `Link rel=next` pages | `Authorization: Bearer {token}`; no body; no other path |
| `TraceLinkBuilder` | none | builds strings only |

`tests/Unit/ArchitectureTest.php` gains: `PrometheusSource` source text contains only the two path suffixes and no
`delete|admin|rules|alerts|targets|write|snapshot`; `SentryIssueSource` contains only the issues path, only `'GET'`, and none of
`/events/|/users/|/tags/|/attachments/|/hashes/|breadcrumbs`.

Timeouts/retries: the options from `Factory::httpOptions()` (connect 5 s, total 15 s, redirects <= 3, `Retry` for 429/5xx
as `LokiSource` uses). A 401/403 is `SourceUnavailable('unauthorized')`, not retried.

## Failure vocabulary (no URL, no token, no query, no body)

`unreachable` | `unauthorized` | `timeout` | `bad_response` | `rate_limited` | `too_large` (range over `max_points`) | `window_too_old` (Sentry window outside the stats period: 24h hourly, 14d daily). The adapter exception message
is exactly one of these tokens; the digest line is `Metrics unavailable (<token>)` / `Sentry unavailable (<token>)`.

## Compact payload

See [data-model.md](../data-model.md#compact-model-input-compactpayload-additions). Prompt addition (only when `m` exists):
`m = measured metric values [current, baseline]; they describe impact, not cause.`

## Digest additions (text shape, not a layout contract)

```text
Metrics: error rate 0.4% (was 0.1%, +300%) · p95 820 ms (was 310 ms, +165%)
Metrics: p95 820 ms (no baseline)
Metrics unavailable (timeout)
Sentry unavailable (unauthorized)
Sentry unavailable (window_too_old)
3 Sentry issues dropped
2 metric anomalies not shown (limit 3)
```
Per Sentry finding: `Sentry: https://sentry.example/organizations/acme/issues/1234567/ (regression)`.
Per log finding with a tracing config: `Traces: <url>`.
