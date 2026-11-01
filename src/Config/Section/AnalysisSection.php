<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;

/** Validates the `analysis` section: steps, providers, keys and per-provider options. */
final class AnalysisSection
{
    public const STEPS = ['triage', 'analyze', 'escalate'];

    /** Model-provider names this release knows. Adding one needs its adapter (specs/016-model-providers). */
    public const PROVIDERS = ['openai', 'gemini'];

    public const PROVIDER_BASE_URLS = [
        'openai' => 'https://api.openai.com',
        'gemini' => 'https://generativelanguage.googleapis.com',
    ];

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function analysis(array $a): array
    {
        SectionFields::only($a, ['provider', 'providers', 'api_keys', 'providers_options', 'client', 'models', 'max_input_tokens', 'max_output_tokens', 'confidence_threshold', 'escalation_cap', 'triage', 'advice'], 'analysis');
        $models = SectionFields::section($a, 'models');
        SectionFields::only($models, self::STEPS, 'analysis.models');
        foreach (self::STEPS as $step) {
            SectionFields::str($models, $step, 'analysis.models.' . $step);
        }
        $conf = SectionFields::num($a, 'confidence_threshold', 0.6, 'analysis.confidence_threshold');
        if ($conf <= 0 || $conf >= 1) {
            throw new InvalidConfig('analysis.confidence_threshold must be between 0 and 1 exclusive');
        }
        $limits = [];
        foreach (['max_input_tokens' => [20000, 30000, 15000], 'max_output_tokens' => [1500, 4000, 2000]] as $key => $defaults) {
            $given = SectionFields::section($a, $key);
            SectionFields::only($given, self::STEPS, 'analysis.' . $key);
            foreach (self::STEPS as $i => $step) {
                $limits[$key][$step] = SectionFields::posInt($given, $step, $defaults[$i], 'analysis.' . $key . '.' . $step);
            }
        }

        // The transport is chosen per provider now; the key is accepted (every existing config has `http`) and ignored.
        if (array_key_exists('client', $a) && $a['client'] !== 'http') {
            throw new InvalidConfig('analysis.client is no longer a transport choice; every provider uses the package HTTP client. Delete the key.');
        }
        $client = 'http';
        [$provider, $stepProviders, $apiKeys, $options] = self::providers($a);

        return array_merge($a, $limits, [
            'provider' => $provider,
            'step_providers' => $stepProviders,
            'api_keys' => $apiKeys,
            'providers_options' => $options,
            'client' => $client,
            'models' => $models,
            'confidence_threshold' => $conf,
            'escalation_cap' => SectionFields::posInt($a, 'escalation_cap', 3, 'analysis.escalation_cap'),
        ]);
    }

    /**
     * The provider of each step, the key of each provider in use, and the options of each provider (every provider listed).
     *
     * @param array<string, mixed> $a
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>, 3: array<string, array{base_url: string, retries: int}>}
     */
    public static function providers(array $a): array
    {
        $provider = SectionFields::optStr($a, 'provider', 'gemini', 'analysis.provider');
        if ($provider === 'anthropic') {
            throw new InvalidConfig('analysis.provider = anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use gemini or openai.');
        }
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidConfig('analysis.provider must be one of: ' . implode(', ', self::PROVIDERS));
        }

        $perStep = SectionFields::section($a, 'providers');
        SectionFields::only($perStep, self::STEPS, 'analysis.providers');
        $steps = [];
        foreach (self::STEPS as $step) {
            $name = $perStep[$step] ?? $provider;
            if ($name === 'anthropic') {
                throw new InvalidConfig('analysis.providers.' . $step . ' = anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use gemini or openai.');
            }
            if (!is_string($name) || !in_array($name, self::PROVIDERS, true)) {
                throw new InvalidConfig('analysis.providers.' . $step . ' must be one of: ' . implode(', ', self::PROVIDERS));
            }
            $steps[$step] = $name;
        }

        $keys = SectionFields::section($a, 'api_keys');
        SectionFields::only($keys, self::PROVIDERS, 'analysis.api_keys');
        $apiKeys = [];
        foreach (self::PROVIDERS as $name) {
            $key = $keys[$name] ?? null;
            if (is_string($key) && $key !== '') {
                $apiKeys[$name] = $key;
            }
        }
        foreach (array_unique(array_values($steps)) as $name) {
            if (!isset($apiKeys[$name])) {
                throw new InvalidConfig('analysis.api_keys.' . $name . ' is required for the provider in use');
            }
        }

        $given = SectionFields::section($a, 'providers_options');
        SectionFields::only($given, self::PROVIDERS, 'analysis.providers_options');
        $options = [];
        foreach (self::PROVIDERS as $name) {
            $opt = SectionFields::section($given, $name);
            SectionFields::only($opt, ['base_url', 'retries'], 'analysis.providers_options.' . $name);
            $url = SectionFields::optStr($opt, 'base_url', self::PROVIDER_BASE_URLS[$name], 'analysis.providers_options.' . $name . '.base_url');
            if (!str_starts_with($url, 'https://')) {
                throw new InvalidConfig('analysis.providers_options.' . $name . '.base_url must be https');
            }
            $retries = $opt['retries'] ?? 0;
            if (!is_int($retries) || $retries < 0 || $retries > 3) {
                throw new InvalidConfig('analysis.providers_options.' . $name . '.retries must be 0 to 3');
            }
            $options[$name] = ['base_url' => $url, 'retries' => $retries];
        }

        return [$provider, $steps, $apiKeys, $options];
    }
}
