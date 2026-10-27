<?php

declare(strict_types=1);

/**
 * Configuration keys removed in 0.5.0, 0.6.0 and 0.6.1 (contracts/config-migration.md). Each one fails at load with its own message; nothing is converted.
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
    'analysis.api_key' => 'analysis.api_key was removed in 0.6.1; use analysis.api_keys.gemini or analysis.api_keys.openai (the provider in use).',
    'analysis.api_keys.anthropic' => 'analysis.api_keys.anthropic was removed in 0.6.1; Anthropic is no longer a provider. Use analysis.api_keys.gemini or analysis.api_keys.openai.',
    'analysis.providers_options.anthropic' => 'analysis.providers_options.anthropic was removed in 0.6.1; Anthropic is no longer a provider. Delete this section.',
    'analysis.provider=anthropic' => 'analysis.provider = anthropic was removed in 0.6.1; Anthropic is no longer a provider. Use gemini or openai.',
    'analysis.providers.triage=anthropic' => 'analysis.providers.triage = anthropic was removed in 0.6.1; Anthropic is no longer a provider. Use gemini or openai.',
    'analysis.providers.analyze=anthropic' => 'analysis.providers.analyze = anthropic was removed in 0.6.1; Anthropic is no longer a provider. Use gemini or openai.',
    'analysis.providers.escalate=anthropic' => 'analysis.providers.escalate = anthropic was removed in 0.6.1; Anthropic is no longer a provider. Use gemini or openai.',
];
