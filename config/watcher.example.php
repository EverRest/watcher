<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * Plain-PHP example: return this array from the file you pass to `bin/watcher run --config=<file>`
 * (or hand it to Watcher::fromConfig()). Every key is shown with its default; keys marked "required"
 * have none. Secrets and model ids come from the environment, never from code.
 * Unknown top-level keys are rejected, so a typo cannot silently disable a setting.
 *
 * Feature flags: every feature has an `enabled` key and a WATCHER_* variable (Env reads it; table in docs/configuration.md).
 * Only the master `enabled` defaults to false; the example turns it on. All other flags default to true. Flags must be real
 * booleans: the string 'false' is rejected. There is no flag for redaction: privacy is not optional.
 */
return [
    'enabled' => Env::bool('WATCHER_ENABLED', true),     // master switch, default FALSE. false: the run touches nothing and reports `disabled`
    'environment' => Env::string('WATCHER_ENVIRONMENT'), // optional (int, prod, ...): state keys become <service>@<environment>; two processes with the same prefix share state
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
        'enabled' => Env::bool('WATCHER_WATCH_RULES_ENABLED', true), // reserved key; false = no rule is evaluated
        'billing-calls' => ['template' => '/billing/i'],
        'db-errors' => ['error_type' => '/PDOException|SQLSTATE/'],
        'one-tenant' => ['field' => 'tenant', 'equals' => 'acme'],
    ],

    // Known noise. Fingerprint or the same matcher form, with an optional end date (null = forever). Default [].
    'mute' => [
        'enabled' => Env::bool('WATCHER_MUTE_ENABLED', true), // reserved key beside the list items; false = nothing is muted
        ['fingerprint' => 'a1b2c3d4e5f6', 'until' => '2030-01-31'],
        ['template' => '/Creating default object from empty value/', 'until' => null],
    ],

    'selection' => [
        'min_level' => 'error',                          // lowest level that is a candidate, default error
        'growth_factor' => 3.0,                          // "grew" = count at least this many times yesterday's (> 1), default 3.0
        'top_n' => 50,                                   // candidates kept per run, default 50
        'top_m' => 10,                                   // groups deep-analysed per run (<= top_n), default 10
        'escalation' => ['enabled' => Env::bool('WATCHER_ESCALATION_ENABLED', true)], // false: only triage + analysis, no stronger model
    ],

    'level_anomaly' => ['enabled' => Env::bool('WATCHER_LEVEL_ANOMALY_ENABLED', true)], // second read for a level (below read_min_level) whose total jumped
    'purge' => ['enabled' => Env::bool('WATCHER_PURGE_ENABLED', true)],                 // expired-file purge of the file state store

    // What a window is compared with. Default 'previous' = the window before (no change in behaviour).
    'baseline' => [
        'enabled' => Env::bool('WATCHER_BASELINE_ENABLED', true), // false: previous-window comparison only, whatever `mode` says
        'mode' => 'previous',                            // previous | rolling (mean of N windows) | weekday (median of N same weekdays)
        'windows' => null,                               // rolling default 7 (>= 2), weekday default 4 (>= 3); needs state.retention >= windows x span + 1 day
        'max_age' => 'P7D',                              // oldest snapshot a missing previous window may fall back to; <= state.retention
        'exclude' => [],                                 // local dates ('2026-12-25') of window ends left out of rolling/weekday values
        'since' => null,                                 // ignore windows that ended before this local date (history reset)
    ],

    // How messages are grouped into templates.
    'grouping' => [
        'enabled' => Env::bool('WATCHER_GROUPING_ENABLED', true), // false: the old exact token-count clusters
        'similarity' => 0.5,                             // 0 < x <= 1: share of literal words that must match, default 0.5
        'message_cap' => 50000,                          // >= 1000: distinct masked messages clustered per run, default 50000
    ],

    'analysis' => [
        'provider' => 'anthropic',                       // only anthropic is available
        'triage' => ['enabled' => Env::bool('WATCHER_TRIAGE_ENABLED', true)], // false: no triage call, candidates go straight to analysis (top_m still applies)
        'advice' => ['enabled' => Env::bool('WATCHER_ADVICE_ENABLED', true)], // false: no how_to_check / related_checks (fewer output tokens)
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
    ],

    'redaction' => [
        'min_group_events_for_text' => 3,                // clusters smaller than this send no message text, default 3
        'extra_patterns' => [                            // name => regex, replaced by <NAME>; default []
            'ORDERREF' => '/\bORD-\d{6,}\b/',
        ],
    ],

    'state' => [
        'driver' => 'redis',                             // required; redis is the default choice (in Kubernetes the only one that keeps history): redis | pdo | file
        'retention' => 2592000,                          // seconds every stored item lives, default 30 days
        'redis' => [                                     // an injected \Redis in 'instance' wins over the connection keys
            'host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0,
            'client' => 'auto',                          // auto (ext-redis, else predis/predis) | phpredis | predis
            'compress_min_bytes' => 512,                 // values larger than this are zlib-compressed; 0 = never
            'prefix' => 'watcher:',                      // key prefix: give each environment that shares a database its own
        ],
        'pdo' => [                                       // table is NEVER created implicitly: see PdoStateStore::createTableSql()
            'dsn' => getenv('WATCHER_DB_DSN'), 'username' => null, 'password' => null, 'table' => 'watcher_state',
        ],
        'file' => ['path' => '/var/lib/watcher'],        // single runner only
    ],

    'notify' => [
        'digest' => ['driver' => Env::string('WATCHER_DIGEST_DRIVER', 'slack'), 'webhook' => getenv('WATCHER_SLACK_WEBHOOK'), 'enabled' => Env::bool('WATCHER_DIGEST_ENABLED', true)], // required; enabled=false: no model call, the run ends quiet
        // Telegram: ['driver' => 'telegram', 'bot_token' => getenv('WATCHER_TG_TOKEN'), 'chat_id' => getenv('WATCHER_TG_CHAT'), 'thread_id' => 7],
        // Discord:  ['driver' => 'discord', 'webhook' => getenv('WATCHER_DISCORD_WEBHOOK')],
        'notice' => ['enabled' => Env::bool('WATCHER_NOTICE_ENABLED', true)], // failure notices; only `enabled` = use the digest destination (null = same). false: host log and exit code only
    ],

    'heartbeat' => [                                     // optional
        'stale_after' => 'PT36H',                        // ISO-8601; `watcher status` is non-zero when the last ok run is older. Default 1.5 x schedule.window
        'enabled' => Env::bool('WATCHER_HEARTBEAT_ENABLED', true), // false: no liveness record is written; `status` reports disabled
        'weekly_summary' => Env::bool('WATCHER_HEARTBEAT_WEEKLY_SUMMARY', false), // true: one counts-and-cost line per ISO week to the notice destination, default false
    ],

    'service_context' => [
        'enabled' => Env::bool('WATCHER_SERVICE_CONTEXT_ENABLED', true),
        'text' => 'Order intake and fulfilment. Critical: payment capture, stock reservation.', // <= 1000 chars; default ''. A plain string is still accepted
    ],

    'dependencies' => [                                  // other services whose logs give context; default []
        'enabled' => Env::bool('WATCHER_DEPENDENCIES_ENABLED', true), // reserved key (a dependency cannot be named "enabled")
        'billing' => ['stream_tags' => ['app' => 'billing']],
        'auth' => ['stream_tags' => ['app' => 'auth']],
    ],
    'dependency_read_cap' => 5000,                       // events read per dependency, default 5000
    'dependency_domains' => ['svc.cluster.local'],       // a host becomes <URL:name> only if it equals a DECLARED dependency name or is name.<domain> for one of these; [] turns every non-exact dependency host into <URL>

    'deploy' => [
        'release' => getenv('WATCHER_RELEASE') ?: null,  // shown next to findings to correlate with deploys
        'commit' => getenv('WATCHER_COMMIT') ?: null,
        'version_label' => null,                         // log label carrying the running version, if the source has one
        'enabled' => Env::bool('WATCHER_DEPLOY_ENABLED', true), // false: no release line, no version query
    ],

    'links' => [
        'grafana' => ['base_url' => 'https://grafana.example.com', 'datasource_uid' => 'loki'],
        'datadog' => ['app_url' => null],                // e.g. https://app.datadoghq.eu
        'logs' => ['enabled' => Env::bool('WATCHER_LINKS_LOGS_ENABLED', true)], // false: no log-search link per finding
        'code' => ['enabled' => Env::bool('WATCHER_LINKS_CODE_ENABLED', true)], // false: no code link per stack frame
    ],

    'repo' => [
        'provider' => 'gitlab',                          // gitlab | github
        'web_url' => 'https://git.example.com/group/orders-api',
        'path_map' => ['/var/www/html/' => 'app/'],      // container path => repository path, for stack-frame links
    ],

    // Optional, read-only metrics and Sentry issues (spec 009, docs/configuration.md). Absent = off. Read-only tokens; an internal host needs
    // allow_private_network (RFC 1918, *.svc) and plain http also allow_insecure. Env: WATCHER_METRICS_URL, WATCHER_METRICS_TOKEN, WATCHER_SENTRY_TOKEN.
    // 'metrics' => [
    //     'source' => ['type' => 'prometheus', 'base_url' => Env::string('WATCHER_METRICS_URL'), 'auth' => ['type' => 'bearer', 'token' => Env::string('WATCHER_METRICS_TOKEN')], 'allow_private_network' => false],
    //     'queries' => ['error_rate' => ['query' => 'sum(rate(http_requests_total{service="{service}",code=~"5.."}[{window}])) / sum(rate(http_requests_total{service="{service}"}[{window}]))', 'aggregate' => 'sum', 'threshold' => ['relative' => 0.5, 'floor' => 0.01]]],
    // ],
    // 'sentry' => ['organization' => 'acme', 'project' => 'orders-api', 'auth_token' => Env::string('WATCHER_SENTRY_TOKEN'), 'max_issues' => 50],
    // OpenAPI endpoint documentation (docs/configuration.md#api_context, spec 010). OFF unless this section exists. Routes of at most 2 segments only.
    // 'api_context' => [
    //     'source' => Env::string('WATCHER_OPENAPI_SOURCE'),         // absolute JSON file path, or an https URL (a secret if it holds a token)
    //     'auth_token' => Env::string('WATCHER_OPENAPI_TOKEN'),      // optional bearer token
    //     'ttl' => 'PT24H',                                          // the reduced spec is cached this long, then read again
    //     'max_endpoints' => 5,                                      // 1..20 per run
    // ],
    // Log hygiene reports (spec 006): `vendor/bin/watcher hygiene --config=...`. Off by default; the cost report works regardless.
    'hygiene' => [
        'enabled' => false,
    ],

    // Event sinks (docs/observability.md). Every sink is OFF until its own `enabled` is true. With driver `none` under notify.digest the
    // chat stays silent and a sink is the only channel (at least one must be on). `log` text defaults to on, the others to off.
    'observe' => [
        'top_groups' => Env::int('WATCHER_OBSERVE_TOP_GROUPS', 20),   // 0..100 group events per run
        'log' => [
            'enabled' => Env::bool('WATCHER_OBSERVE_LOG_ENABLED', false),
            'stream' => Env::string('WATCHER_OBSERVE_LOG_STREAM', 'stderr'),           // stderr | stdout (stderr under --json)
            'include_text' => Env::bool('WATCHER_OBSERVE_LOG_INCLUDE_TEXT', false),
        ],
        'file' => [
            'enabled' => Env::bool('WATCHER_OBSERVE_FILE_ENABLED', false),
            'path' => Env::string('WATCHER_OBSERVE_FILE_PATH'),                        // absolute; required when enabled
            'rotate' => Env::string('WATCHER_OBSERVE_FILE_ROTATE', 'size'),            // size | daily
            'max_bytes' => Env::int('WATCHER_OBSERVE_FILE_MAX_BYTES', 10485760),
            'max_files' => Env::int('WATCHER_OBSERVE_FILE_MAX_FILES', 10),
            'retention_days' => Env::int('WATCHER_OBSERVE_FILE_RETENTION_DAYS', 30),
            'include_text' => Env::bool('WATCHER_OBSERVE_FILE_INCLUDE_TEXT', false),
        ],
        'http' => [
            'enabled' => Env::bool('WATCHER_OBSERVE_HTTP_ENABLED', false),
            'url' => Env::string('WATCHER_OBSERVE_HTTP_URL'),                          // https; a secret, masked everywhere
            'auth_header' => Env::string('WATCHER_OBSERVE_HTTP_AUTH_HEADER', 'Authorization'),
            'auth_scheme' => Env::string('WATCHER_OBSERVE_HTTP_AUTH_SCHEME', 'Bearer'), // '' (set in code) sends the token alone
            'auth_token' => Env::string('WATCHER_OBSERVE_HTTP_AUTH_TOKEN'),
            'batch_size' => Env::int('WATCHER_OBSERVE_HTTP_BATCH_SIZE', 100),
            'timeout' => Env::int('WATCHER_OBSERVE_HTTP_TIMEOUT', 5),
            'allowed_hosts' => Env::list('WATCHER_OBSERVE_HTTP_ALLOWED_HOSTS'),
            'allow_insecure' => Env::bool('WATCHER_OBSERVE_HTTP_ALLOW_INSECURE', false),
            'allow_private_network' => Env::bool('WATCHER_OBSERVE_HTTP_ALLOW_PRIVATE_NETWORK', false),
            'include_text' => Env::bool('WATCHER_OBSERVE_HTTP_INCLUDE_TEXT', false),
        ],
        'prometheus' => [
            'enabled' => Env::bool('WATCHER_OBSERVE_PROMETHEUS_ENABLED', false),
            'dir' => Env::string('WATCHER_OBSERVE_PROMETHEUS_DIR'),                    // node_exporter textfile directory
            'name' => Env::string('WATCHER_OBSERVE_PROMETHEUS_NAME'),                  // default watcher_<service>[_<environment>]; unset in a fleet
            'file_mode' => Env::string('WATCHER_OBSERVE_PROMETHEUS_FILE_MODE'),        // default 0644
        ],
    ],
];
