# Contract: configuration

```php
'kubernetes' => [
    'enabled' => true,                         // flag; false = source not built, no call, no key
    'api' => ['in_cluster' => true],           // or ['server' => env('K8S_API'), 'token' => env('K8S_TOKEN') | 'token_file' => '/run/secrets/k8s-token', 'ca_file' => '/etc/ca.crt']
    'namespaces' => ['shop'],                  // required, non-empty; no "all namespaces"
    'label_selector' => 'app in (api,bff)',    // optional; equality and set-based
    'involved_kinds' => ['Pod', 'Deployment', 'ReplicaSet', 'StatefulSet', 'DaemonSet', 'Job', 'HorizontalPodAutoscaler'],
    'critical_reasons' => ['CrashLoopBackOff', 'BackOff', 'OOMKilled', 'ImagePullBackOff', 'ErrImagePull', 'Failed', 'Evicted', 'FailedMount', 'FailedScheduling'],
    'min_repeat_for_probe_failures' => 3,
    'event_cap' => 5000,
    'tolerance' => 120,                        // seconds
    'event_ttl' => 3600,                       // the cluster's --event-ttl, for gap detection
    'allow' => ['registries' => [], 'images' => []],
    'exporter' => false,                       // true if an event exporter also ships these events to Loki/Datadog
],
'events' => ['driver' => 'kubernetes'],        // beside `source`: read these events too (needs `kubernetes`)
// or as the only source: 'source' => ['driver' => 'kubernetes', ...]
```

`token` and `token_file` are mutually exclusive and one is required with `server`; `token_file` and `ca_file` are validated when the config loads (see below) and again on every call. Errors name the key and never the value: `kubernetes.namespaces must be a non-empty list` (checked before any call),
`kubernetes.api.token is required with server`, `kubernetes.label_selector is not a valid selector`,
`kubernetes: unknown key x`, `events.driver must be kubernetes`, `events needs the kubernetes section`.
`source.driver: kubernetes` makes `source.stream_tags` optional. Secrets (`token`) join `WatchConfig::notifySecrets`-style
masking so they never reach a failure reason.

Fleet: `kubernetes` and `events` may sit in `defaults`; each entry sets its own `label_selector`. Entries with the same
`api` share one client (R12).
