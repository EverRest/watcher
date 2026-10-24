<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * Minimal config for the first live smoke run (docs/smoke-test.md): Loki as the log source, file state in a
 * throwaway directory, a private Slack channel, a tiny daily budget. Copy it, set the environment variables
 * listed in the guide, adjust `service` and `stream_tags`, and run:
 *
 *   php bin/watcher run --config=smoke.config.php --now=<ISO-8601>
 */
return [
    'enabled' => Env::bool('WATCHER_ENABLED', true), // the master switch is off by default; this guide turns it on
    'service' => getenv('WATCHER_SERVICE') ?: 'smoke-service',

    'schedule' => [
        'run_at' => '06:00',        // the run is due from 06:00 to 06:59 (timezone below); --now must fall in that hour
        'timezone' => 'UTC',
        'window' => 'PT24H',
    ],

    'source' => [
        'driver' => 'loki',
        'loki' => [
            'url' => getenv('WATCHER_LOKI_URL'),
            'auth' => ['type' => 'bearer', 'token' => getenv('WATCHER_LOKI_TOKEN')],
            'tenant' => getenv('WATCHER_LOKI_TENANT') ?: null,
        ],
        // Pick labels that exist in your Loki (Grafana > Explore > label browser).
        'stream_tags' => ['app' => getenv('WATCHER_SERVICE') ?: 'smoke-service'],
    ],

    'analysis' => [
        'api_key' => getenv('WATCHER_ANTHROPIC_KEY'),
        'models' => [
            'triage' => getenv('WATCHER_MODEL_TRIAGE'),
            'analyze' => getenv('WATCHER_MODEL_ANALYZE'),
            'escalate' => getenv('WATCHER_MODEL_ESCALATE'),
        ],
        // USD per million tokens: [input, output, cache_read, cache_write]. Copy the current numbers from your
        // provider's pricing page; the cost line and the budget alerts are only as good as these.
        'prices' => [
            (string) getenv('WATCHER_MODEL_TRIAGE') => [(float) getenv('WATCHER_PRICE_TRIAGE_IN'), (float) getenv('WATCHER_PRICE_TRIAGE_OUT'), 0.0, 0.0],
            (string) getenv('WATCHER_MODEL_ANALYZE') => [(float) getenv('WATCHER_PRICE_ANALYZE_IN'), (float) getenv('WATCHER_PRICE_ANALYZE_OUT'), 0.0, 0.0],
            (string) getenv('WATCHER_MODEL_ESCALATE') => [(float) getenv('WATCHER_PRICE_ESCALATE_IN'), (float) getenv('WATCHER_PRICE_ESCALATE_OUT'), 0.0, 0.0],
        ],
        'daily_budget_usd' => 0.10,  // hard cap for the smoke run
    ],

    'state' => [
        'driver' => 'file',
        'file' => ['path' => getenv('WATCHER_STATE_PATH') ?: '/tmp/watcher-smoke'],
    ],

    'notify' => [
        'digest' => ['driver' => 'slack', 'webhook' => getenv('WATCHER_SLACK_WEBHOOK')],
    ],

    'service_context' => getenv('WATCHER_SERVICE_CONTEXT') ?: '',
];
