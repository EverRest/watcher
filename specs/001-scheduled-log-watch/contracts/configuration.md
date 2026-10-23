# Contract: Configuration

One PHP array per service (FR-014). The Laravel adapter publishes `config/watcher.php` reading env;
plain PHP passes the array directly. Validation happens once in `Watcher::fromConfig()`; any error
throws `InvalidConfig` naming the key (FR-015) and exits with code 2.

```php
return [
    'service' => 'orders-api',                          // required; key prefix for all state

    'schedule' => [
        'run_at'   => '06:00',                     // HH:MM, required
        'timezone' => 'Europe/Kyiv',               // IANA, default UTC
        'window'   => 'PT24H',                     // ISO-8601 duration, default PT24H
        'lock_ttl' => 900,                         // seconds
    ],

    'source' => [
        'driver' => 'loki',                        // loki | datadog
        'loki' => [
            'url'     => env('WATCHER_LOKI_URL'),  // Loki base URL or Grafana datasource proxy URL
            'auth'    => ['type' => 'bearer', 'token' => env('WATCHER_LOKI_TOKEN')], // or basic + tenant
            'tenant'  => null,                     // X-Scope-OrgID when required
        ],
        'datadog' => [
            'site'    => 'datadoghq.eu',
            'api_key' => env('WATCHER_DD_API_KEY'),
            'app_key' => env('WATCHER_DD_APP_KEY'),  // logs_read_data scope only
        ],
        'stream_tags' => ['app' => 'orders-api', 'env' => 'stg'],   // FR-012a
        'read_min_level' => 'warning',             // FR-036: lower levels only counted in totals
        'anomaly_cap'    => 20000,                 // FR-038: second-pass cap for a level whose total grew
        'sample_size' => 3,                        // FR-036
        'event_cap'   => 200000,                   // FR-036 fallback
    ],

    'watch_rules' => [                             // FR-012b: explicit matchers; only the name leaves the service
        'quota'  => ['template'   => '/QuotaTracking/'],
        'sql'    => ['error_type' => '/PDOException|SQLSTATE/'],
        'tenant-a'   => ['field' => 'tenant', 'equals' => 'tenant-a'],      // or 'matches' => '/regex/'
    ],
    'mute' => [                                    // FR-034: fingerprint or the same matcher form
        ['fingerprint' => 'a1b2c3d4e5f6', 'until' => '2026-11-30'],
        ['template' => '/Creating default object from empty value/', 'until' => null],
    ],

    'service_context' => 'Sport entries and qualification: entry forms, quotas per pathway, '
        .'finalisation. Critical: quota reservation, finalisation, exports.',   // FR-040, ≤ 1000 chars

    'dependencies' => [                            // FR-039; hosts also auto-detected from templates
        'core-api'        => ['stream_tags' => ['app' => 'core-api']],
        'users-api' => ['stream_tags' => ['app' => 'users-api']],
        'partners-api'     => ['stream_tags' => ['app' => 'partners-api']],
        'auth-api'        => ['stream_tags' => ['app' => 'auth-api'], 'source' => null], // null → service's source
    ],
    'dependency_read_cap' => 5000,
    'dependency_domains'  => ['svc.cluster.local'],   // FR-039: other hosts kept only if they end with one of these

    'deploy' => [                                  // FR-041
        'release'       => env('WATCHER_RELEASE'),
        'commit'        => env('WATCHER_COMMIT'),
        'version_label' => null,                   // e.g. 'image_tag' if the source has one
    ],

    'links' => [                                   // FR-042
        'grafana' => ['base_url' => env('WATCHER_GRAFANA_URL'), 'datasource_uid' => env('WATCHER_LOKI_DS_UID')],
        'datadog' => ['app_url' => null],          // e.g. https://app.datadoghq.eu
    ],

    'repo' => [                                    // FR-043
        'provider' => 'gitlab',                    // gitlab | github
        'web_url'  => 'https://gitlab.example.com/group/orders-api',
        'path_map' => ['/var/www/html/' => 'app/'],
    ],

    'selection' => [
        'min_level'     => 'error',
        'growth_factor' => 3.0,
        'top_n'         => 50,
        'top_m'         => 10,
    ],

    'analysis' => [
        'provider'  => 'anthropic',
        'api_key'   => env('WATCHER_ANTHROPIC_KEY'),
        'models'    => [                           // no ids in code (FR-017); defaults documented in README
            'triage'   => env('WATCHER_MODEL_TRIAGE'),
            'analyze'  => env('WATCHER_MODEL_ANALYZE'),
            'escalate' => env('WATCHER_MODEL_ESCALATE'),
        ],
        'max_input_tokens'  => ['triage' => 20000, 'analyze' => 30000, 'escalate' => 15000],
        'max_output_tokens' => ['triage' => 1500,  'analyze' => 4000,  'escalate' => 2000],
        'confidence_threshold' => 0.6,
        'escalation_cap'       => 3,
        'daily_budget_usd'     => 0.50,
        'daily_token_limit'    => null,                // FR-046: optional tokens/day cap (input+output+cache)
        'budget_alert_percents'=> [25, 50, 75, 90],    // FR-046: integers 1-99, ascending
        'prices' => [ /* model => [input, output, cache_read, cache_write] USD per MTok; for cost line */ ],
    ],

    'redaction' => [
        'min_group_events_for_text' => 3,          // FR-044: smaller clusters send no message text
        'extra_patterns' => [ /* name => regex, replaced by <NAME> */ ],
    ],

    'state' => [
        'driver'    => 'redis',                    // redis | pdo | file
        'retention' => 2592000,                    // seconds, default 30 days (FR-027)
        'redis'     => ['connection' => null],     // adapter resolves host connection; plain PHP passes \Redis
        'pdo'       => ['dsn' => env('WATCHER_DB_DSN'), 'table' => 'watcher_state'],
        'file'      => ['path' => '/var/lib/watcher'],   // single runner only
    ],

    'notify' => [
        'digest' => ['driver' => 'slack', 'webhook' => env('WATCHER_SLACK_WEBHOOK')],
        'notice' => null,                          // FR-037: null → digest destination
    ],
];
```

## Validation rules

- `analysis.prices` is REQUIRED for every model in `analysis.models` (FR-046); `budget_alert_percents` are integers 1–99 in ascending order.
- Required: `service`, `schedule.run_at`, `source.driver` + its credentials, `analysis.api_key`,
  all three `analysis.models`, `state.driver` + its settings, `notify.digest.webhook`.
- `growth_factor` > 1; `0 < confidence_threshold < 1`; `top_m ≤ top_n`; positive integers elsewhere.
- Every regex in `watch_rules`, `mute`, `redaction.extra_patterns` must compile.
- A watch rule or mute matcher has exactly one of `template`, `error_type`, `field`; `field` requires
  exactly one of `equals`, `matches`. Free-form regex over all field values is not accepted.
- `mute[].until` is a date; past dates are valid (entry simply no longer applies).
- Unknown top-level keys are rejected (typos must not silently disable a setting).
- Secrets are never included in error messages; only the key name is.
