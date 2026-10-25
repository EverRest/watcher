# Metric queries (spec 009)

Two examples for the `metrics.queries` section (see `docs/configuration.md`). Queries are PromQL; `{service}`, `{window}`, `{start}`, `{end}`,
`{baseline_start}` and `{baseline_end}` are replaced from the config and the run window (nothing from logs). The window is read twice: the run
window and the previous window of equal length. A PromQL selector such as `{job="x"}` is left alone.

```php
'metrics' => [
    'source' => ['type' => 'prometheus', 'base_url' => 'https://prometheus.example.com', 'auth' => ['type' => 'bearer', 'token' => getenv('WATCHER_METRICS_TOKEN')]],
    'queries' => [
        // Request error ratio. A ratio of two sums is one series; `aggregate: sum` also makes a many-series answer one number.
        'error_rate' => [
            'query' => 'sum(rate(http_requests_total{service="{service}",code=~"5.."}[{window}])) / sum(rate(http_requests_total{service="{service}"}[{window}]))',
            'aggregate' => 'sum',
            'unit' => '%',
            'threshold' => ['relative' => 0.5, 'floor' => 0.01],   // an anomaly only when +50% AND above 0.01
        ],
        // p95 from a histogram; per-pod series are reduced with `max`.
        'p95_latency' => [
            'query' => 'histogram_quantile(0.95, sum by (le, pod) (rate(http_request_duration_seconds_bucket{service="{service}"}[{window}])))',
            'aggregate' => 'max',
            'unit' => 's',
            'threshold' => ['relative' => 0.5, 'floor' => 0.2],
        ],
    ],
],
```

Rules: more than one series without `aggregate` is reported as `ambiguous` and skipped (never guessed); no data is `no data`, never 0; a metric without
`threshold` only adds to the digest line. Metric names are `[a-z][a-z0-9_]{0,39}`; the model sees the name and the numbers, never a query or a label value.
