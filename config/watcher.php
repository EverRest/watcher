<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * Publishable configuration. Required keys have no default: a missing one makes
 * Watcher::fromConfig() throw InvalidConfig naming the key (exit code 2).
 * Model ids are never defaulted in code. Set them per tier in the environment,
 * e.g. a small/cheap model for triage, a mid-size one for analysis and the
 * largest for escalation, and give every one of them a price below.
 *
 * Every feature has an on/off flag, set here or by its WATCHER_* variable (table: docs/configuration.md).
 * Only `enabled` defaults to off: a deployment must opt in, per environment (WATCHER_ENABLED=1).
 * All other flags default to on (today's behaviour). There is no flag for redaction: privacy is not optional.
 */
return [
    'enabled' => Env::bool('WATCHER_ENABLED', false), // master switch: false = the run does nothing and reports `disabled`
    'environment' => Env::string('WATCHER_ENVIRONMENT'), // optional (int, prod, ...): state keys become <service>@<environment>
    'service' => env('WATCHER_SERVICE'),           // required; key prefix for all state

    'schedule' => [
        'run_at' => env('WATCHER_RUN_AT'),         // required, HH:MM
        'timezone' => env('WATCHER_TIMEZONE', 'UTC'),
        'window' => 'PT24H',                       // ISO-8601 duration
        'lock_ttl' => 900,                         // seconds
        'run_deadline' => Env::int('WATCHER_RUN_DEADLINE', 0) ?: null, // seconds; default lock_ttl minus a margin (810)
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
            'newest_first' => false,
        ],
        'stream_tags' => [],                       // e.g. ['app' => 'my-service', 'env' => 'prod']
        'read_min_level' => 'warning',
        'anomaly_cap' => 20000,
        'event_cap' => 200000,
        'group_cap' => 5000,
        'level_mode' => 'text', // text | label | all, see docs/configuration.md
    ],

    // Explicit matchers; only the rule name leaves the service. One of
    // template | error_type | field (+ equals | matches). Example:
    //   'quota' => ['template' => '/QuotaTracking/'],
    'watch_rules' => ['enabled' => Env::bool('WATCHER_WATCH_RULES_ENABLED', true)],

    // [['fingerprint' => 'abc123', 'until' => '2030-01-31'], ['template' => '/regex/', 'until' => null]]
    'mute' => ['enabled' => Env::bool('WATCHER_MUTE_ENABLED', true)], // add entries as list items next to `enabled`

    'selection' => [
        'min_level' => 'error',
        'growth_factor' => 3.0,
        'top_n' => 50,
        'top_m' => 10,
        'escalation' => ['enabled' => Env::bool('WATCHER_ESCALATION_ENABLED', true)], // false: only triage + analysis
    ],

    'level_anomaly' => ['enabled' => Env::bool('WATCHER_LEVEL_ANOMALY_ENABLED', true)],  // second pass on a level whose total grew
    'budget_alerts' => ['enabled' => Env::bool('WATCHER_BUDGET_ALERTS_ENABLED', true)],  // 25/50/75/90 % notices and the exhausted notice
    'purge' => ['enabled' => Env::bool('WATCHER_PURGE_ENABLED', true)],                  // expired-file purge (file state store only)
    'baseline' => ['enabled' => Env::bool('WATCHER_BASELINE_ENABLED', true)],            // false: previous-window comparison only
    'grouping' => ['enabled' => Env::bool('WATCHER_GROUPING_ENABLED', true)],            // false: exact token-count clusters
    'kubernetes' => ['enabled' => Env::bool('WATCHER_KUBERNETES_ENABLED', true)],        // pod events: add `events` + this section's settings to use (docs/kubernetes.md)

    'analysis' => [
        'provider' => 'anthropic',
        'triage' => ['enabled' => Env::bool('WATCHER_TRIAGE_ENABLED', true)], // false: no triage call, candidates go straight to analysis
        'advice' => ['enabled' => Env::bool('WATCHER_ADVICE_ENABLED', true)], // false: no how_to_check / related_checks (fewer output tokens)
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
        'driver' => env('WATCHER_STATE', 'redis'), // redis (default, the only one that survives a CronJob pod) | pdo | file
        'retention' => 2592000,                    // seconds (30 days)
        'redis' => [                               // an injected \Redis ('instance') wins over the connection keys
            'host' => env('WATCHER_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('WATCHER_REDIS_PORT', 6379),
            'password' => env('WATCHER_REDIS_PASSWORD'),
            'database' => (int) env('WATCHER_REDIS_DATABASE', 0),
            'client' => env('WATCHER_REDIS_CLIENT', 'auto'),                              // auto | phpredis | predis
            'compress_min_bytes' => (int) env('WATCHER_REDIS_COMPRESS_MIN_BYTES', 512),  // 0 = never compress
            'prefix' => env('WATCHER_REDIS_PREFIX', 'watcher:'),                         // give each environment sharing a database its own
        ],
        // The table is never created implicitly: see PdoStateStore::createTableSql().
        'pdo' => ['dsn' => env('WATCHER_DB_DSN'), 'username' => env('WATCHER_DB_USERNAME'), 'password' => env('WATCHER_DB_PASSWORD'), 'table' => 'watcher_state'],
        'file' => ['path' => env('WATCHER_STATE_PATH', '/var/lib/watcher')], // single runner only
    ],

    'notify' => [
        'digest' => ['driver' => 'slack', 'webhook' => env('WATCHER_SLACK_WEBHOOK'), 'enabled' => Env::bool('WATCHER_DIGEST_ENABLED', true)],
        // Failure and budget notices. Only `enabled`: use the digest destination. Add driver + webhook for a channel of its own.
        'notice' => ['enabled' => Env::bool('WATCHER_NOTICE_ENABLED', true)],
    ],

    'service_context' => [
        'enabled' => Env::bool('WATCHER_SERVICE_CONTEXT_ENABLED', true),
        'text' => env('WATCHER_SERVICE_CONTEXT'),  // what the service does and what is critical, <= 1000 chars
    ],

    // 'other-service' => ['stream_tags' => ['app' => 'other-service']]
    'dependencies' => ['enabled' => Env::bool('WATCHER_DEPENDENCIES_ENABLED', true)],
    'dependency_read_cap' => 5000,
    'dependency_domains' => [],

    'deploy' => [
        'release' => env('WATCHER_RELEASE'),
        'commit' => env('WATCHER_COMMIT'),
        'version_label' => null,
        'enabled' => Env::bool('WATCHER_DEPLOY_ENABLED', true),
    ],

    'heartbeat' => [
        // 'stale_after' => 'PT36H',               // default: 1.5 x schedule.window
        'weekly_summary' => Env::bool('WATCHER_HEARTBEAT_WEEKLY_SUMMARY', false), // one counts-and-cost line per ISO week
        'enabled' => Env::bool('WATCHER_HEARTBEAT_ENABLED', true),
    ],

    'links' => [
        'grafana' => ['base_url' => env('WATCHER_GRAFANA_URL'), 'datasource_uid' => env('WATCHER_LOKI_DS_UID')],
        'datadog' => ['app_url' => null],
        'logs' => ['enabled' => Env::bool('WATCHER_LINKS_LOGS_ENABLED', true)],
        'code' => ['enabled' => Env::bool('WATCHER_LINKS_CODE_ENABLED', true)],
    ],

    'repo' => [
        'provider' => 'gitlab',                    // gitlab | github
        'web_url' => env('WATCHER_REPO_URL'),
        'path_map' => [],                          // ['/var/www/html/' => 'app/']
    ],
];
