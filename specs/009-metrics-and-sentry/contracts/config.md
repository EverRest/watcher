# Contract: configuration (all sections optional; absent = off)

Validated once at start by `WatchConfig`; errors name the key path only, never a value. Unknown keys are rejected. Sections
are validated even with `enabled: false`.

## `metrics`

```php
'metrics' => [
    'enabled' => true,                       // false = off, section still validated
    'source' => [
        'type' => 'prometheus',              // prometheus | grafana
        'base_url' => getenv('WATCHER_METRICS_URL'),          // required; https (SecureUrl, owner decision 2026-10-25)
        'allow_insecure' => false,           // plain http for a loopback / private / cluster-local host
        'allow_private_network' => false,    // RFC 1918 and *.svc / *.cluster.local hosts (shared classifier)
        'datasource_uid' => '',              // required when type = grafana
        'auth' => ['type' => 'bearer', 'token' => getenv('WATCHER_METRICS_TOKEN')], // optional; READ-ONLY credential
    ],
    'label_allowlist' => [],                 // label NAMES whose values may be shown (<= 5 values each, masked)
    'max_anomalies' => 3,                    // 0..10
    'max_points' => 500,                     // 10..11000 (Prometheus caps at 11,000)
    'vars' => ['namespace' => 'prod'],       // extra {placeholders}; values ^[A-Za-z0-9_.:/\-]{1,64}$
    'queries' => [
        'error_rate' => [                    // name ^[a-z][a-z0-9_]{0,39}$ ; at most 6 metrics
            'query' => 'sum(rate(http_requests_total{service="{service}",code=~"5.."}[{window}])) / sum(rate(http_requests_total{service="{service}"}[{window}]))',
            'aggregate' => 'sum',            // sum | max | avg | last ; omit only for single-series queries
            'unit' => '',                    // optional display suffix
            'mode' => 'instant',             // instant | range (range = single series only)
            'threshold' => ['relative' => 0.5, 'floor' => 0.01],   // omit = context line only, never an anomaly
        ],
    ],
],
```

| Rule | Error text (key path only) |
|---|---|
| unknown placeholder in a query | `metrics.queries.error_rate.query uses unknown placeholder {x}` |
| `{service}` or a var value outside `^[A-Za-z0-9_.:/\-]{1,64}$` | `metrics.vars.namespace has characters not allowed in a query value` |
| `base_url` missing / refused by `SecureUrl` | `metrics.source.base_url is required` / `metrics.source.base_url must use https (...)` / `must not point at a loopback, link-local, private-network or metadata address` |
| `datasource_uid` missing for `grafana` | `metrics.source.datasource_uid is required for type grafana` |
| more than 6 queries, empty `queries`, bad name/aggregate/mode/threshold | `metrics.queries ...` per key |
| section present, `queries` empty | `metrics.queries must declare at least one metric` |

## `sentry`

```php
'sentry' => [
    'enabled' => true,
    'base_url' => 'https://sentry.io',       // self-hosted: its URL
    'organization' => 'acme',                // slug ^[a-z0-9][a-z0-9_-]{0,63}$
    'project' => 'orders-api',               // slug, same pattern
    'auth_token' => getenv('WATCHER_SENTRY_TOKEN'),   // read scope for issues only (project:read / event:read is NOT needed)
    'max_issues' => 50,                      // 1..100 per run
    'allow_insecure' => false,               // as metrics.source
    'allow_private_network' => false,        // as metrics.source
],
```

## `links.tempo` and `links.datadog_apm`

```php
'links' => [
    'tempo' => ['base_url' => 'https://grafana.example', 'datasource_uid' => 'tempo', 'trace_field' => 'trace_id', 'service_name' => null],
    // or, reusing links.datadog.app_url:
    'datadog_apm' => ['trace_field' => 'trace_id', 'service' => null],
],
```

`trace_field` default `trace_id`, pattern `^[A-Za-z0-9_.-]{1,40}$`. `service_name`/`service` default `service.name`.
`links.datadog_apm` without `links.datadog.app_url` = `links.datadog_apm requires links.datadog.app_url`. `links.tempo` and
`links.datadog_apm` both set = tempo is used (documented precedence).

## Environment variables in `config/watcher.example.php`

`WATCHER_METRICS_URL`, `WATCHER_METRICS_TOKEN`, `WATCHER_SENTRY_TOKEN` (secrets from the host; examples use `getenv`).
