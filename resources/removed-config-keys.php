<?php

declare(strict_types=1);

/**
 * Configuration keys removed in 0.5.0 (contracts/config-migration.md) and 0.6.0. Each one fails at load with its own message; nothing is
 * converted. Kept as data outside src/ so the shipped code and docs name no removed key (tests/Unit/NoBudgetNamesTest).
 * A key `section.key=value` matches only when that key holds that value (`source.driver=kubernetes`).
 *
 * @return array<string, string> key path => the message WatchConfig throws
 */
return [
    'analysis.daily_budget_usd' => 'analysis.daily_budget_usd was removed in 0.5.0; there is no replacement. Delete this key.',
    'analysis.run_budget_usd' => 'analysis.run_budget_usd was removed in 0.5.0; there is no replacement. Delete this key.',
    'analysis.prices' => 'analysis.prices was removed in 0.5.0; there is no replacement. Delete this key.',
    'analysis.daily_token_limit' => 'analysis.daily_token_limit was removed in 0.5.0; there is no replacement. Delete this key.',
    'analysis.budget_alert_percents' => 'analysis.budget_alert_percents was removed in 0.5.0; there is no replacement. Delete this key.',
    'analysis.max_runs_per_day' => 'analysis.max_runs_per_day was removed in 0.5.0; there is no replacement. Delete this key.',
    'budget' => 'budget was removed in 0.5.0; there is no replacement. Delete this section.',
    'kubernetes' => 'kubernetes section was removed in 0.6.0; there is no replacement. Delete this section.',
    'events' => 'events section was removed in 0.6.0; there is no replacement. Delete this section.',
    'source.driver=kubernetes' => 'source.driver kubernetes was removed in 0.6.0; there is no replacement. Use a log source (loki or datadog). Delete the kubernetes settings.',
];
