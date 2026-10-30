<?php

declare(strict_types=1);

namespace Watcher\Config\Build;

use GuzzleHttp\Client;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\ModelProvider;
use Watcher\Analyze\OpenAiProvider;
use Watcher\Analyze\ProviderAnalyzer;
use Watcher\Analyze\ProviderRegistry;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;

/** Analyzer construction: one model provider per configured step provider, with its key and retries. Used by {@see \Watcher\Config\Factory}, which keeps the public entry points. */
final class AnalyzerFactory
{
    public static function analyzer(WatchConfig $c): Analyzer
    {
        $options = Factory::analyzerHttpOptions($c->schedule['lock_ttl']);
        $http = new Client($options);

        $providers = [];
        foreach (array_unique(array_values($c->analysis['step_providers'])) as $name) {
            $providers[$name] = self::modelProvider($name, $c, $http, $options['timeout']);
        }
        return new ProviderAnalyzer(new ProviderRegistry($providers, $c->analysis['step_providers']), $c->on('advice'));
    }

    /** The adapter for one provider name, with its key, base URL and retries from configuration (spec 016). */
    private static function modelProvider(string $name, WatchConfig $c, Client $http, int $timeout): ModelProvider
    {
        $key = WatchConfig::reveal($c->analysis['api_keys'][$name]);
        $opt = $c->analysis['providers_options'][$name];

        return match ($name) {
            'openai' => new OpenAiProvider($http, $key, $opt['base_url'], $timeout, $opt['retries']),
            'gemini' => new GeminiProvider($http, $key, $opt['base_url'], $timeout, $opt['retries']),
            default => throw new \LogicException("no adapter for provider $name"),
        };
    }
}
