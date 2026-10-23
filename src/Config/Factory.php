<?php

declare(strict_types=1);

namespace Watcher\Config;

use GuzzleHttp\Client;
use Psr\Clock\ClockInterface;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Notify\Notifier;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Source\LogSource;
use Watcher\Source\LokiSource;
use Watcher\State\FileStateStore;
use Watcher\State\StateStore;

/** Builds the real collaborators from a validated config. Drivers that do not exist yet fail by name, before any call. */
final class Factory
{
    public static function http(): Client
    {
        return new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    public static function source(WatchConfig $c, Client $http): LogSource
    {
        if ($c->source['driver'] !== 'loki') {
            throw self::unavailable('source.driver', (string) $c->source['driver']);
        }
        /** @var array<string, mixed> $loki */
        $loki = $c->source['loki'];
        /** @var array<string, string>|null $auth */
        $auth = $loki['auth'] ?? null;
        $tenant = $loki['tenant'] ?? null;

        return new LokiSource(
            $http,
            (string) $loki['url'],
            ($auth['type'] ?? null) === 'bearer' ? $auth['token'] : null,
            ($auth['type'] ?? null) === 'basic' ? $auth['user'] : null,
            ($auth['type'] ?? null) === 'basic' ? $auth['password'] : null,
            is_string($tenant) ? $tenant : null,
        );
    }

    public static function state(WatchConfig $c, ClockInterface $clock): StateStore
    {
        if ($c->state['driver'] !== 'file') {
            throw self::unavailable('state.driver', (string) $c->state['driver']);
        }

        return new FileStateStore((string) $c->state['file']['path'], $clock);
    }

    public static function analyzer(WatchConfig $c, Client $http): Analyzer
    {
        if ($c->analysis['provider'] !== 'anthropic') {
            throw self::unavailable('analysis.provider', (string) $c->analysis['provider']);
        }

        return new AnthropicAnalyzer((string) $c->analysis['api_key'], $http);
    }

    public static function notifier(Client $http): Notifier
    {
        return new SlackWebhookNotifier($http);
    }

    private static function unavailable(string $key, string $value): InvalidConfig
    {
        return new InvalidConfig("$key '$value' is not available yet");
    }
}
