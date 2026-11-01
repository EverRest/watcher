<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * Mixed-provider config for the offline checks (specs/016-model-providers/quickstart.md, section 4): triage on Gemini, the
 * other steps on OpenAI. Keys come from the environment; the model ids are placeholders, not real values.
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
        'provider' => 'openai',
        'providers' => ['triage' => 'gemini'],
        'api_keys' => [
            'openai' => Env::string('WATCHER_OPENAI_KEY'),
            'gemini' => Env::string('WATCHER_GEMINI_KEY'),
        ],
        'models' => ['triage' => 'placeholder-triage-model', 'analyze' => 'placeholder-analyze-model', 'escalate' => 'placeholder-escalate-model'],
    ],
    'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/watcher-provider-mix']],
    'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example.test/PLACEHOLDER']],
];
