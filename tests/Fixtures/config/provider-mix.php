<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * Mixed-provider config for the offline checks (specs/016-model-providers/quickstart.md, section 4): triage on Gemini, the
 * other steps on Anthropic. Keys come from the environment; the model ids and prices are placeholders, not real values.
 */
return [
    'enabled' => true,
    'service' => 'fixture-service',
    'schedule' => ['run_at' => '06:00', 'timezone' => 'UTC', 'window' => 'PT24H'],
    'source' => [
        'driver' => 'loki',
        'loki' => ['url' => 'https://loki.example.test', 'auth' => ['type' => 'bearer', 'token' => Env::string('WATCHER_LOKI_TOKEN')]],
        'stream_tags' => ['app' => 'fixture-service'],
    ],
    'analysis' => [
        'provider' => 'anthropic',
        'providers' => ['triage' => 'gemini'],
        'api_keys' => [
            'anthropic' => Env::string('WATCHER_ANTHROPIC_KEY'),
            'gemini' => Env::string('WATCHER_GEMINI_KEY'),
        ],
        'models' => ['triage' => 'placeholder-triage-model', 'analyze' => 'placeholder-analyze-model', 'escalate' => 'placeholder-escalate-model'],
        'prices' => [
            'placeholder-triage-model' => [0.0, 0.0, 0.0, 0.0],
            'placeholder-analyze-model' => [0.0, 0.0, 0.0, 0.0],
            'placeholder-escalate-model' => [0.0, 0.0, 0.0, 0.0],
        ],
        'daily_budget_usd' => 0.10,
    ],
    'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/watcher-provider-mix']],
    'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example.test/PLACEHOLDER']],
];
