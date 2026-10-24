<?php

declare(strict_types=1);

/*
 * Plain-PHP example: return this array from the file you pass to `bin/watcher run --config=<file>`
 * (or hand it to Watcher::fromConfig()). Every key is shown with its default; keys marked "required"
 * have none. Secrets and model ids come from the environment, never from code.
 * Unknown top-level keys are rejected, so a typo cannot silently disable a setting.
 */
return [
    'service' => 'orders-api',                           // required; prefix for all state keys

    'schedule' => [
        'run_at' => '06:00',                             // required, HH:MM; the run is due for one hour from then
        'timezone' => 'UTC',                             // IANA zone, default UTC
        'window' => 'PT24H',                             // ISO-8601 lookback, default PT24H
        'lock_ttl' => 900,                               // seconds the run lock lives, default 900
    ],

    'source' => [
        'driver' => 'loki',                              // required: loki | datadog
        'loki' => [
            'url' => getenv('WATCHER_LOKI_URL'),         // required for loki: Loki base URL or Grafana datasource proxy URL
            'auth' => ['type' => 'bearer', 'token' => getenv('WATCHER_LOKI_TOKEN')], // or ['type' => 'basic', 'user' => ..., 'password' => ...]; READ-ONLY credential
            'tenant' => null,                            // X-Scope-OrgID when the Loki is multi-tenant
        ],
        'datadog' => [                                   // used when driver = datadog
            'site' => 'datadoghq.com',                   // e.g. datadoghq.eu
            'api_key' => getenv('WATCHER_DD_API_KEY'),   // required for datadog
            'app_key' => getenv('WATCHER_DD_APP_KEY'),   // required; application key with the logs_read_data scope only
            'newest_first' => false,                     // true: sort newest first, so an event_cap truncation keeps the NEWEST events. Default false (oldest)
        ],
        'stream_tags' => ['app' => 'orders-api', 'env' => 'prod'], // which logs belong to this service, default []
        'read_min_level' => 'warning',                   // lower levels are only counted in totals, default warning
        'anomaly_cap' => 20000,                          // second-pass event cap for a level whose total grew, default 20000
        'group_cap' => 5000,                             // max distinct problem groups kept in memory per run; the rest is grouped as "other", default 5000
        'event_cap' => 200000,                           // hard cap on events read per run, default 200000
        'level_mode' => 'text',                          // how read_min_level is pushed to the backend: text | label | all (loki); datadog: all = no status filter, else status. Default text
    ],

    // Explicit matchers that raise a group's priority; only the rule NAME leaves the service.
    // Exactly one of template | error_type | field (field + equals | matches). Default [].
    'watch_rules' => [
        'billing-calls' => ['template' => '/billing/i'],
        'db-errors' => ['error_type' => '/PDOException|SQLSTATE/'],
        'one-tenant' => ['field' => 'tenant', 'equals' => 'acme'],
    ],

    // Known noise. Fingerprint or the same matcher form, with an optional end date (null = forever). Default [].
    'mute' => [
        ['fingerprint' => 'a1b2c3d4e5f6', 'until' => '2030-01-31'],
        ['template' => '/Creating default object from empty value/', 'until' => null],
    ],

    'selection' => [
        'min_level' => 'error',                          // lowest level that is a candidate, default error
        'growth_factor' => 3.0,                          // "grew" = count at least this many times yesterday's (> 1), default 3.0
        'top_n' => 50,                                   // candidates kept per run, default 50
        'top_m' => 10,                                   // groups deep-analysed per run (<= top_n), default 10
    ],

    // What a window is compared with. Default 'previous' = the window before (no change in behaviour).
    'baseline' => [
        'mode' => 'previous',                            // previous | rolling (mean of N windows) | weekday (median of N same weekdays)
        'windows' => null,                               // rolling default 7 (>= 2), weekday default 4 (>= 3); needs state.retention >= windows x span + 1 day
        'max_age' => 'P7D',                              // oldest snapshot a missing previous window may fall back to; <= state.retention
        'exclude' => [],                                 // local dates ('2026-12-25') of window ends left out of rolling/weekday values
        'since' => null,                                 // ignore windows that ended before this local date (history reset)
    ],

    // How messages are grouped into templates.
    'grouping' => [
        'similarity' => 0.5,                             // 0 < x <= 1: share of literal words that must match, default 0.5
        'message_cap' => 50000,                          // >= 1000: distinct masked messages clustered per run, default 50000
    ],

    'analysis' => [
        'provider' => 'anthropic',                       // only anthropic is available
        'api_key' => getenv('WATCHER_ANTHROPIC_KEY'),    // required
        'models' => [                                    // required: one id per tier, no defaults in code
            'triage' => getenv('WATCHER_MODEL_TRIAGE'),
            'analyze' => getenv('WATCHER_MODEL_ANALYZE'),
            'escalate' => getenv('WATCHER_MODEL_ESCALATE'),
        ],
        'max_input_tokens' => ['triage' => 20000, 'analyze' => 30000, 'escalate' => 15000],
        'max_output_tokens' => ['triage' => 1500, 'analyze' => 4000, 'escalate' => 2000],
        'confidence_threshold' => 0.6,                   // escalate below this for error+ findings; 0 < x < 1
        'escalation_cap' => 3,                           // escalations per run, default 3
        'daily_budget_usd' => 0.50,                      // hard daily cap per service, default 0.50; each model call is reserved against it first
        'daily_token_limit' => null,                     // optional tokens/day cap (input+output+cache)
        'budget_alert_percents' => [25, 50, 75, 90],     // notices at these shares of the cap; ascending integers 1-99
        // REQUIRED for every model above: model id => [input, output, cache_read, cache_write], USD per million tokens.
        // Fill in your provider's current prices; the ids are the same values as in 'models'.
        'prices' => [
            (string) getenv('WATCHER_MODEL_TRIAGE') => [
                (float) getenv('WATCHER_PRICE_TRIAGE_IN'), (float) getenv('WATCHER_PRICE_TRIAGE_OUT'), 0.0, 0.0,
            ],
            (string) getenv('WATCHER_MODEL_ANALYZE') => [
                (float) getenv('WATCHER_PRICE_ANALYZE_IN'), (float) getenv('WATCHER_PRICE_ANALYZE_OUT'), 0.0, 0.0,
            ],
            (string) getenv('WATCHER_MODEL_ESCALATE') => [
                (float) getenv('WATCHER_PRICE_ESCALATE_IN'), (float) getenv('WATCHER_PRICE_ESCALATE_OUT'), 0.0, 0.0,
            ],
        ],
    ],

    'redaction' => [
        'min_group_events_for_text' => 3,                // clusters smaller than this send no message text, default 3
        'extra_patterns' => [                            // name => regex, replaced by <NAME>; default []
            'ORDERREF' => '/\bORD-\d{6,}\b/',
        ],
    ],

    'state' => [
        'driver' => 'redis',                             // required: redis | pdo | file
        'retention' => 2592000,                          // seconds every stored item lives, default 30 days
        'redis' => [                                     // an injected \Redis in 'instance' wins over the connection keys
            'host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0,
        ],
        'pdo' => [                                       // table is NEVER created implicitly: see PdoStateStore::createTableSql()
            'dsn' => getenv('WATCHER_DB_DSN'), 'username' => null, 'password' => null, 'table' => 'watcher_state',
        ],
        'file' => ['path' => '/var/lib/watcher'],        // single runner only
    ],

    'notify' => [
        'digest' => ['driver' => 'slack', 'webhook' => getenv('WATCHER_SLACK_WEBHOOK')], // required
        // Telegram: ['driver' => 'telegram', 'bot_token' => getenv('WATCHER_TG_TOKEN'), 'chat_id' => getenv('WATCHER_TG_CHAT'), 'thread_id' => 7],
        // Discord:  ['driver' => 'discord', 'webhook' => getenv('WATCHER_DISCORD_WEBHOOK')],
        'notice' => null,                                // failures and budget notices; null = use the digest destination
    ],

    'heartbeat' => [                                     // optional
        'stale_after' => 'PT36H',                        // ISO-8601; `watcher status` is non-zero when the last ok run is older. Default 1.5 x schedule.window
        'weekly_summary' => false,                       // true: one counts-and-cost line per ISO week to the notice destination, default false
    ],

    'service_context' => 'Order intake and fulfilment. Critical: payment capture, stock reservation.', // <= 1000 chars; default ''

    'dependencies' => [                                  // other services whose logs give context; default []
        'billing' => ['stream_tags' => ['app' => 'billing']],
        'auth' => ['stream_tags' => ['app' => 'auth']],
    ],
    'dependency_read_cap' => 5000,                       // events read per dependency, default 5000
    'dependency_domains' => ['svc.cluster.local'],       // a host becomes <URL:name> only if it equals a DECLARED dependency name or is name.<domain> for one of these; [] turns every non-exact dependency host into <URL>

    'deploy' => [
        'release' => getenv('WATCHER_RELEASE') ?: null,  // shown next to findings to correlate with deploys
        'commit' => getenv('WATCHER_COMMIT') ?: null,
        'version_label' => null,                         // log label carrying the running version, if the source has one
    ],

    'links' => [
        'grafana' => ['base_url' => 'https://grafana.example.com', 'datasource_uid' => 'loki'],
        'datadog' => ['app_url' => null],                // e.g. https://app.datadoghq.eu
    ],

    'repo' => [
        'provider' => 'gitlab',                          // gitlab | github
        'web_url' => 'https://git.example.com/group/orders-api',
        'path_map' => ['/var/www/html/' => 'app/'],      // container path => repository path, for stack-frame links
    ],
];
