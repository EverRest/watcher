<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * A fleet of three services run by ONE job: an API and two backends-for-frontend (bff-a, bff-b) that call it. The BFFs have no
 * CLI and no scheduler of their own; they exist here only as a log selection. Names are generic: replace them.
 *
 *   php bin/watcher fleet:check  --config=docs/examples/watcher-fleet.php
 *   php bin/watcher fleet:run    --config=docs/examples/watcher-fleet.php --dry-run
 *   php bin/watcher fleet:run    --config=docs/examples/watcher-fleet.php --now=<ISO-8601>
 *   php bin/watcher fleet:status --config=docs/examples/watcher-fleet.php
 *
 * Trigger it hourly (one cron entry or one CronJob); each service runs only in its own `schedule.run_at` hour.
 */
$loki = [
    'driver' => 'loki',
    'loki' => [
        'url' => getenv('WATCHER_LOKI_URL'),
        'auth' => ['type' => 'bearer', 'token' => getenv('WATCHER_LOKI_TOKEN')],
    ],
];

return [
    'fleet' => 'platform',
    'enabled' => Env::bool('WATCHER_FLEET_ENABLED', true), // false: every service is reported `disabled`, nothing is touched
    'digest' => 'per_service',             // 'combined' = one message for the whole fleet
    'timezone' => 'UTC',
    'time_budget' => 'PT50M',               // services not started in time are listed as skipped_time (exit 1)
    'budget' => [
        'daily_budget_usd' => 3.0,           // on top of each service's own cap
        'daily_token_limit' => 2000000,
    ],
    'notify' => [                           // used by entries without their own, and by the correlated finding
        'digest' => ['driver' => 'slack', 'webhook' => getenv('WATCHER_SLACK_DIGEST')],
        'notice' => ['driver' => 'telegram', 'bot_token' => getenv('WATCHER_TG_TOKEN'), 'chat_id' => getenv('WATCHER_TG_CHAT')],
    ],
    'correlation' => ['enabled' => Env::bool('WATCHER_FLEET_CORRELATION_ENABLED', true), 'tolerance' => 'PT0S'],

    // Merged UNDER every entry. `state` and `analysis.api_key` must end up identical in all of them.
    'defaults' => [
        'enabled' => Env::bool('WATCHER_ENABLED', true),   // off by default in code; a single entry can set its own `enabled`
        'environment' => Env::string('WATCHER_ENVIRONMENT'), // optional: int | prod | ...; state keys become <service>@<environment>
        'schedule' => ['run_at' => '06:00', 'timezone' => 'UTC', 'window' => 'PT24H'],
        // Shared by every service; a service's own `observe` replaces it as a whole. Each service gets its own events and its own
        // Prometheus file (watcher_<service>_<environment>.prom), so never set observe.prometheus.name here. All sinks off.
        'observe' => [
            'log' => ['enabled' => Env::bool('WATCHER_OBSERVE_LOG_ENABLED', false)],
            'prometheus' => ['enabled' => Env::bool('WATCHER_OBSERVE_PROMETHEUS_ENABLED', false), 'dir' => Env::string('WATCHER_OBSERVE_PROMETHEUS_DIR')],
        ],
        'analysis' => [
            'api_key' => getenv('WATCHER_ANTHROPIC_KEY'),
            'models' => [
                'triage' => getenv('WATCHER_MODEL_TRIAGE'),
                'analyze' => getenv('WATCHER_MODEL_ANALYZE'),
                'escalate' => getenv('WATCHER_MODEL_ESCALATE'),
            ],
            // USD per million tokens: [input, output, cache_read, cache_write]
            'prices' => [
                (string) getenv('WATCHER_MODEL_TRIAGE') => [0.0, 0.0, 0.0, 0.0], // USD per million tokens from your provider's pricing page,
                (string) getenv('WATCHER_MODEL_ANALYZE') => [0.0, 0.0, 0.0, 0.0], // USD per million tokens from your provider's pricing page,
                (string) getenv('WATCHER_MODEL_ESCALATE') => [0.0, 0.0, 0.0, 0.0], // USD per million tokens from your provider's pricing page,
            ],
            'daily_budget_usd' => 1.0,       // per service
        ],
        'state' => ['driver' => 'file', 'file' => ['path' => getenv('WATCHER_STATE_PATH') ?: '/var/lib/watcher']],
    ],

    'services' => [
        [
            'service' => 'api',
            'priority' => 10,                // runs first; the BFFs are also ordered after it because they depend on it
            'source' => $loki + ['stream_tags' => ['app' => 'api', 'env' => 'prod']],
            'deploy' => ['release' => getenv('API_RELEASE') ?: ''],
        ],
        [
            'service' => 'bff-a',
            'source' => $loki + ['stream_tags' => ['app' => 'bff-a', 'env' => 'prod']],
            // A host in a BFF log line that equals this name becomes <URL:api>: that placeholder is what links the two.
            'dependencies' => ['api' => ['stream_tags' => ['app' => 'api', 'env' => 'prod']]],
            'notify' => ['digest' => ['driver' => 'discord', 'webhook' => getenv('WATCHER_DISCORD_BFF_A')]], // replaces the fleet digest destination as a whole
        ],
        [
            'service' => 'bff-b',
            'source' => $loki + ['stream_tags' => ['app' => 'bff-b', 'env' => 'prod']],
            'dependencies' => ['api' => ['stream_tags' => ['app' => 'api', 'env' => 'prod']]],
            // no `notify`: uses the fleet default above
        ],
    ],
];
