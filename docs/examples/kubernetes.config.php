<?php

declare(strict_types=1);

use Watcher\Config\Env;

/*
 * Loki logs plus Kubernetes pod events for one service (spec 008). Names are generic: replace them.
 * Trigger watcher HOURLY: the cluster keeps events about an hour (docs/kubernetes.md).
 * Do not enable this if an event exporter already ships the same events to Loki.
 */
return [
    'service' => 'orders-api',
    'enabled' => true,
    'schedule' => ['run_at' => '06:00', 'timezone' => 'Europe/Kyiv'],
    'source' => [
        'driver' => 'loki',
        'loki' => ['url' => getenv('WATCHER_LOKI_URL')],
        'stream_tags' => ['app' => 'orders-api', 'env' => 'prod'],
    ],
    'kubernetes' => [
        'enabled' => Env::bool('WATCHER_KUBERNETES_ENABLED', true),
        'api' => ['in_cluster' => true], // the pod's service account; see docs/kubernetes.md for the Role
        'namespaces' => ['shop'],
        'label_selector' => 'app in (orders-api,orders-worker)',
    ],
    'events' => ['driver' => 'kubernetes'],
    'analysis' => [
        'api_key' => getenv('WATCHER_ANTHROPIC_KEY'),
        'models' => ['triage' => getenv('WATCHER_MODEL_TRIAGE'), 'analyze' => getenv('WATCHER_MODEL_ANALYZE'), 'escalate' => getenv('WATCHER_MODEL_ESCALATE')],
    ],
    'state' => ['driver' => 'redis', 'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1']],
    'notify' => ['digest' => ['driver' => 'slack', 'webhook' => getenv('WATCHER_SLACK_WEBHOOK')]],
];
