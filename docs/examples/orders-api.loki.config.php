<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * First real config for orders-api (Loki / Grafana environment). Same environment variables as docs/smoke-test.md, plus:
 *   WATCHER_LOKI_LABEL_NAME   Loki label that selects orders-api, e.g. `app` or `service_name` (Grafana > Explore > label browser)
 *   WATCHER_LOKI_LABEL_VALUE  its value, e.g. `orders-api`
 *   WATCHER_LOKI_ENV          optional value of an `env` label (omit if the streams have none)
 *   WATCHER_LOKI_LEVEL_MODE   `label` if the streams carry a `level` label, else `text` (default), see docs/configuration.md
 *
 * Try it safely first:
 *   php bin/watcher check --config=docs/examples/orders-api.loki.config.php
 *   php bin/watcher run   --config=docs/examples/orders-api.loki.config.php --dry-run
 *
 * Log traps found in the orders-api services (docs/audit-2026-10-23.md): several formats share one stream (Monolog lines,
 * nginx/php-fpm access logs, supervisord, raw PHP fatals), the Monolog channel is APP_ENV (not an environment tag),
 * and a startup script can print the whole environment as KEY=value lines.
 */
return [
    'enabled' => Env::bool('WATCHER_ENABLED', true), // the master switch is off by default; WATCHER_ENABLED=0 switches this environment off
    'environment' => Env::string('WATCHER_ENVIRONMENT'), // e.g. int | prod: state keys become orders-api@<environment>
    'service' => 'orders-api',

    'schedule' => [
        'run_at' => '06:00',
        'timezone' => 'Europe/Kyiv',
        'window' => 'PT24H',
    ],

    'source' => [
        'driver' => 'loki',
        'loki' => [
            'url' => getenv('WATCHER_LOKI_URL'),
            'auth' => ['type' => 'bearer', 'token' => getenv('WATCHER_LOKI_TOKEN')],
            'tenant' => getenv('WATCHER_LOKI_TENANT') ?: null,
        ],
        'stream_tags' => array_filter([
            (getenv('WATCHER_LOKI_LABEL_NAME') ?: 'app') => getenv('WATCHER_LOKI_LABEL_VALUE') ?: 'orders-api',
            'env' => getenv('WATCHER_LOKI_ENV') ?: null,   // optional second label if your streams carry the environment
        ]),
        'level_mode' => getenv('WATCHER_LOKI_LEVEL_MODE') ?: 'text',
        'read_min_level' => 'warning',
    ],

    // Keep lines of other formats out of the analysis (they would become clusters of their own).
    'mute' => [
        ['template' => '/"(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) \S+ HTTP\/[\d.]+"/', 'until' => null],
        ['template' => '/^(?:supervisord|spawned:|success:)/', 'until' => null],
    ],

    'watch_rules' => [
        'db-errors' => ['error_type' => '/PDOException|QueryException|SQLSTATE/'],
        'queue-failures' => ['template' => '/failed to process|job failed|max attempts/i'],
    ],

    'selection' => ['min_level' => 'error', 'top_n' => 50, 'top_m' => 10],

    'redaction' => [
        'min_group_events_for_text' => 3,
        'extra_patterns' => [
            'ENVLINE' => '/\b[A-Z][A-Z0-9_]{2,}=\S{4,}/',   // KEY=value lines from a script that prints the environment
        ],
    ],

    'analysis' => [
        'api_key' => getenv('WATCHER_ANTHROPIC_KEY'),
        'models' => [
            'triage' => getenv('WATCHER_MODEL_TRIAGE'),
            'analyze' => getenv('WATCHER_MODEL_ANALYZE'),
            'escalate' => getenv('WATCHER_MODEL_ESCALATE'),
        ],
        'prices' => [
            (string) getenv('WATCHER_MODEL_TRIAGE') => [(float) getenv('WATCHER_PRICE_TRIAGE_IN'), (float) getenv('WATCHER_PRICE_TRIAGE_OUT'), 0.0, 0.0],
            (string) getenv('WATCHER_MODEL_ANALYZE') => [(float) getenv('WATCHER_PRICE_ANALYZE_IN'), (float) getenv('WATCHER_PRICE_ANALYZE_OUT'), 0.0, 0.0],
            (string) getenv('WATCHER_MODEL_ESCALATE') => [(float) getenv('WATCHER_PRICE_ESCALATE_IN'), (float) getenv('WATCHER_PRICE_ESCALATE_OUT'), 0.0, 0.0],
        ],
        'daily_budget_usd' => 0.50,   // raise after the first days; alerts fire at 25/50/75/90%
    ],

    'state' => [
        'driver' => 'file',
        'file' => ['path' => getenv('WATCHER_STATE_PATH') ?: '/tmp/watcher-orders-api'],
    ],

    'notify' => [
        'digest' => ['driver' => Env::string('WATCHER_DIGEST_DRIVER', 'slack'), 'webhook' => getenv('WATCHER_SLACK_WEBHOOK')],
    ],

    'service_context' => getenv('WATCHER_SERVICE_CONTEXT') ?: 'orders-api: Lumen API and queue consumers for event sequences; errors in payments, imports and queue consumers are the most critical.',

    // Event sinks, all off. Enable one to see runs and findings in a dashboard: docs/observability.md.
    'observe' => [
        'log' => ['enabled' => Env::bool('WATCHER_OBSERVE_LOG_ENABLED', false)],
        'file' => ['enabled' => Env::bool('WATCHER_OBSERVE_FILE_ENABLED', false), 'path' => Env::string('WATCHER_OBSERVE_FILE_PATH')],
        'http' => ['enabled' => Env::bool('WATCHER_OBSERVE_HTTP_ENABLED', false), 'url' => Env::string('WATCHER_OBSERVE_HTTP_URL'), 'auth_token' => Env::string('WATCHER_OBSERVE_HTTP_AUTH_TOKEN')],
        'prometheus' => ['enabled' => Env::bool('WATCHER_OBSERVE_PROMETHEUS_ENABLED', false), 'dir' => Env::string('WATCHER_OBSERVE_PROMETHEUS_DIR')],
    ],
];
