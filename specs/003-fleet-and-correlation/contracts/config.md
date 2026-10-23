# Contract: fleet configuration

A fleet is a PHP file returning an array, or a directory (see below). Single-service configs and `watcher run`
are unchanged. Validation happens once, for the whole fleet, before any external call; a rejected fleet runs nothing
(exit 2) and names the entry and key.

```php
return [
    'fleet' => 'platform',                       // required; used for shared keys and messages
    'digest' => 'per_service',                   // per_service (default) | combined
    'timezone' => 'Europe/Kyiv',                 // day boundary of the fleet counters
    'time_budget' => 'PT50M',                    // optional; services not started in time are skipped_time
    'budget' => [                                // optional; fleet-wide caps, in addition to each service's own
        'daily_budget_usd' => 3.0,
        'daily_token_limit' => 2000000,
        'alert_percents' => [25, 50, 75, 90],
    ],
    'notify' => [                                // fleet default destination; entries may override
        'digest' => ['webhook' => getenv('SLACK_DIGEST')],
        'notice' => ['webhook' => getenv('SLACK_NOTICE')],
    ],
    'correlation' => ['enabled' => true, 'tolerance' => 'PT0S'],
    'defaults' => [                              // merged UNDER every entry (array_replace_recursive)
        'state' => ['driver' => 'redis', 'redis' => [/* ... */]],      // must end up identical in all entries
        'analysis' => ['api_key' => getenv('ANTHROPIC_API_KEY')],       // credential must end up identical
        'selection' => ['min_level' => 'error'],
    ],
    'services' => [
        // Each entry = a complete single-service config + two optional fleet keys.
        ['priority' => 10, 'provides' => ['api'], /* service, schedule, source, dependencies, ... */ 'service' => 'api'],
        ['service' => 'bff-a', 'dependencies' => ['api' => ['stream_tags' => ['app' => 'api']]], /* ... */],
        ['service' => 'bff-b', 'dependencies' => ['api' => ['stream_tags' => ['app' => 'api']]], /* ... */],
    ],
];
```

| Key | Level | Notes |
|---|---|---|
| `fleet` | fleet | required |
| `digest` | fleet | `per_service` posts each service's digest to that entry's `notify.digest`; `combined` posts one message to the fleet `notify.digest` |
| `budget.*` | fleet | absent = no fleet cap (per-service caps still apply). Reaching either cap puts the **service being run** in triage-only with a notice that says `fleet cap` or `service cap` |
| `notify` | fleet | default destination merged under each entry's `notify`; also the destination of the combined digest and the correlated finding |
| `time_budget` | fleet | checked before each service starts |
| `correlation.enabled` | fleet | `false` skips the seam entirely |
| `defaults` | fleet | any single-service key |
| `services[]` | fleet | list of entries; `priority` (int, default 0, higher first) and `provides` (list of names, default `[service]`) are fleet keys, stripped before `WatchConfig` |

**Reserved/rejected**: duplicate `service`; `/` in `service`; entries whose merged `state` differ or whose
`analysis.api_key` differ; unknown fleet keys; any `WatchConfig` error (reported as `services[i] (name): <message>`).
Error messages carry key paths, never values.

**Directory form**: `fleet:run --config=<dir>` loads `<dir>/*.php` in name order; each file returns one service
entry (same as an element of `services`). Fleet-level settings come from `<dir>/_fleet.php` (same keys as above
without `services`). A directory without `_fleet.php` is rejected (the fleet name is required).

**Order**: `priority` desc, then `service` asc, then dependencies first for dependencies that are in the fleet
(a dependency name equal to a service name or listed in some entry's `provides`); cycles keep the sorted order.
The order is printed by `fleet:check` and decides who consumes the fleet cap first.

**Schedules**: each entry keeps its own `schedule`; the fleet is triggered on the existing hourly cadence and each
service decides if it is due. Correlation only links services that ran in the same trigger.
