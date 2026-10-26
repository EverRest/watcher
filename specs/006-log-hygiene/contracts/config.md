# Contract: `hygiene` configuration

```php
'hygiene' => [
    'enabled' => false,           // default false; WATCHER_HYGIENE_ENABLED via Env::bool
    'read_min_level' => 'info',   // lowest level the pass reads (debug..emergency)
    'event_cap' => 200000,        // events read; more is sampled and said so
    'max_line_length' => 2000,    // message length above which a line is "long"
    'noisy_share' => 0.20,        // share of all events from one site (0 < x <= 1)
    'variable_distinct' => 20,    // distinct messages under one template to call it variable free text
    'repeat_days' => 7,           // an unchanged finding is sent at most this often
    'growth_factor' => 2.0,       // count ratio that makes a finding "growing" (> 1)
    'min_history_days' => 14,     // days of history before proposals
    'mute_min_daily' => 100,      // mean events/day for a mute proposal
    'watch_lead' => 1800,         // seconds a precursor may precede the severe family
    'decline_days' => 90,
    'spike_factor' => 2.0,        // cost day flagged above this x median of previous days (> 1)
    'max_seconds' => 900,         // time budget of one pass; past it the read stops and the report says it sampled
],
```

Validation (all at start, `InvalidConfig` naming `hygiene.<key>`): unknown key rejected; `enabled` boolean; levels from `Level::tryParse`; integers positive; `variable_distinct` at least 5 (the k-anonymity floor of a variable-text row); `noisy_share` in (0, 1]; factors > 1. `hygiene` may be omitted entirely (defaults; `enabled=false`).

Flag: `$config->hygiene->enabled` (a `Watcher\Hygiene\HygieneSettings`). It is deliberately **not** in `WatchConfig::FLAGS`: every entry there defaults to true and the existing suites assert that. It does not touch redaction, the normalizer or the allowlist.

Fleet: each service's `hygiene` section is its own; `watcher hygiene` takes a single-service config (fleet support is out of scope).
