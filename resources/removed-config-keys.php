<?php

declare(strict_types=1);

/**
 * Configuration keys removed in 0.5.0 and 0.6.0 (contracts/config-migration.md). Each one fails at load with its own message; nothing is
 * converted. Kept as data outside src/ so the shipped code and docs name no removed key (tests/Unit/NoBudgetNamesTest).
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
    'analysis.api_key' => 'analysis.api_key was removed in 0.6.0; use analysis.api_keys.gemini or analysis.api_keys.openai (the provider in use).',
    'analysis.api_keys.anthropic' => 'analysis.api_keys.anthropic was removed in 0.6.0; Anthropic is no longer a provider. Use analysis.api_keys.gemini or analysis.api_keys.openai.',
    'analysis.providers_options.anthropic' => 'analysis.providers_options.anthropic was removed in 0.6.0; Anthropic is no longer a provider. Delete this section.',
];
