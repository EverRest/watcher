<?php

declare(strict_types=1);

/*
 * Publishable configuration. Required keys have no default: a missing one makes
 * Watcher::fromConfig() throw InvalidConfig naming the key (exit code 2).
 * Model ids are never defaulted in code. Set them per tier in the environment,
 * e.g. a small/cheap model for triage, a mid-size one for analysis and the
 * largest for escalation, and give every one of them a price below.
 */
return [
    'service' => env('WATCHER_SERVICE'),           // required; key prefix for all state

    'schedule' => [
        'run_at' => env('WATCHER_RUN_AT'),         // required, HH:MM
        'timezone' => env('WATCHER_TIMEZONE', 'UTC'),
        'window' => 'PT24H',                       // ISO-8601 duration
        'lock_ttl' => 900,                         // seconds
    ],

    'source' => [
        'driver' => env('WATCHER_SOURCE', 'loki'), // loki | datadog
        'loki' => [
            'url' => env('WATCHER_LOKI_URL'),
            'auth' => ['type' => 'bearer', 'token' => env('WATCHER_LOKI_TOKEN')], // or basic
            'tenant' => env('WATCHER_LOKI_TENANT'), // X-Scope-OrgID when required
        ],
        'datadog' => [
            'site' => env('WATCHER_DD_SITE', 'datadoghq.com'),
            'api_key' => env('WATCHER_DD_API_KEY'),
            'app_key' => env('WATCHER_DD_APP_KEY'), // logs_read_data scope only
        ],
        'stream_tags' => [],                       // e.g. ['app' => 'my-service', 'env' => 'prod']
        'read_min_level' => 'warning',
        'anomaly_cap' => 20000,
        'sample_size' => 3,
        'event_cap' => 200000,
        'group_cap' => 5000,
    ],

    // Explicit matchers; only the rule name leaves the service. One of
    // template | error_type | field (+ equals | matches). Example:
    //   'quota' => ['template' => '/QuotaTracking/'],
    'watch_rules' => [],

    // [['fingerprint' => 'abc123', 'until' => '2030-01-31'], ['template' => '/regex/', 'until' => null]]
    'mute' => [],

    'selection' => [
        'min_level' => 'error',
        'growth_factor' => 3.0,
        'top_n' => 50,
        'top_m' => 10,
    ],

    'analysis' => [
        'provider' => 'anthropic',
        'api_key' => env('WATCHER_ANTHROPIC_KEY'),
        'models' => [
            'triage' => env('WATCHER_MODEL_TRIAGE'),
            'analyze' => env('WATCHER_MODEL_ANALYZE'),
            'escalate' => env('WATCHER_MODEL_ESCALATE'),
        ],
        'max_input_tokens' => ['triage' => 20000, 'analyze' => 30000, 'escalate' => 15000],
        'max_output_tokens' => ['triage' => 1500, 'analyze' => 4000, 'escalate' => 2000],
        'confidence_threshold' => 0.6,
        'escalation_cap' => 3,
        'daily_budget_usd' => 0.50,
        'daily_token_limit' => null,
        'budget_alert_percents' => [25, 50, 75, 90],
        // Required for every model above: 'model-id' => [input, output, cache_read, cache_write], USD per million tokens.
        'prices' => [],
    ],

    'redaction' => [
        'min_group_events_for_text' => 3,
        'extra_patterns' => [],                    // name => regex, replaced by <NAME>
    ],

    'state' => [
        'driver' => env('WATCHER_STATE', 'redis'), // redis | pdo | file
        'retention' => 2592000,                    // seconds (30 days)
        'redis' => [                               // an injected \Redis ('instance') wins over the connection keys
            'host' => env('WATCHER_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('WATCHER_REDIS_PORT', 6379),
            'password' => env('WATCHER_REDIS_PASSWORD'),
            'database' => (int) env('WATCHER_REDIS_DATABASE', 0),
        ],
        // The table is never created implicitly: see PdoStateStore::createTableSql().
        'pdo' => ['dsn' => env('WATCHER_DB_DSN'), 'username' => env('WATCHER_DB_USERNAME'), 'password' => env('WATCHER_DB_PASSWORD'), 'table' => 'watcher_state'],
        'file' => ['path' => env('WATCHER_STATE_PATH', '/var/lib/watcher')], // single runner only
    ],

    'notify' => [
        'digest' => ['driver' => 'slack', 'webhook' => env('WATCHER_SLACK_WEBHOOK')],
        'notice' => null,                          // null: use the digest destination
    ],

    'service_context' => env('WATCHER_SERVICE_CONTEXT'), // what the service does and what is critical, <= 1000 chars

    // 'other-service' => ['stream_tags' => ['app' => 'other-service']]
    'dependencies' => [],
    'dependency_read_cap' => 5000,
    'dependency_domains' => [],

    'deploy' => [
        'release' => env('WATCHER_RELEASE'),
        'commit' => env('WATCHER_COMMIT'),
        'version_label' => null,
    ],

    'links' => [
        'grafana' => ['base_url' => env('WATCHER_GRAFANA_URL'), 'datasource_uid' => env('WATCHER_LOKI_DS_UID')],
        'datadog' => ['app_url' => null],
    ],

    'repo' => [
        'provider' => 'gitlab',                    // gitlab | github
        'web_url' => env('WATCHER_REPO_URL'),
        'path_map' => [],                          // ['/var/www/html/' => 'app/']
    ],
];
