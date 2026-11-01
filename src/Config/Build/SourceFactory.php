<?php

declare(strict_types=1);

namespace Watcher\Config\Build;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Clock\ClockInterface;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Issues\IssueSource;
use Watcher\Issues\SentryIssueSource;
use Watcher\Metrics\MetricSource;
use Watcher\Metrics\PrometheusSource;
use Watcher\Source\DatadogSource;
use Watcher\Source\LogSource;
use Watcher\Source\LokiSource;

/** Source construction: the log source, the optional metrics and Sentry sources, and the response size cap. Used by {@see \Watcher\Config\Factory}, which keeps the public entry points. */
final class SourceFactory
{
    public static function source(WatchConfig $c, Client $http, ?ClockInterface $clock = null): LogSource
    {
        try {
            return self::buildSource($c, $http, $clock);
        } catch (\InvalidArgumentException) {
            throw new InvalidConfig('source: invalid settings'); // not chained: the message may carry a URL or key
        }
    }

    private static function buildSource(WatchConfig $c, Client $http, ?ClockInterface $clock): LogSource
    {
        if ($c->source['driver'] === 'datadog') {
            /** @var array<string, mixed> $dd */
            $dd = $c->source['datadog'];

            return new DatadogSource(
                $http,
                (string) ($dd['site'] ?? 'datadoghq.com'),
                WatchConfig::reveal($dd['api_key']),
                WatchConfig::reveal($dd['app_key']),
                clock: $clock,
                levelMode: $c->source['level_mode'] === 'all' ? 'all' : 'status',
                newestFirst: ($dd['newest_first'] ?? false) === true,
                maxResponseBytes: self::maxResponseBytes($c),
            );
        }
        /** @var array<string, mixed> $loki */
        $loki = $c->source['loki'];
        /** @var array<string, mixed>|null $auth */
        $auth = $loki['auth'] ?? null;
        $tenant = $loki['tenant'] ?? null;

        return new LokiSource(
            $http,
            (string) $loki['url'],
            ($auth['type'] ?? null) === 'bearer' ? WatchConfig::reveal($auth['token']) : null,
            ($auth['type'] ?? null) === 'basic' ? (string) $auth['user'] : null,
            ($auth['type'] ?? null) === 'basic' ? WatchConfig::reveal($auth['password']) : null,
            is_string($tenant) ? $tenant : null,
            levelMode: (string) $c->source['level_mode'],
            allowInsecure: ($loki['allow_insecure'] ?? false) === true,
            maxResponseBytes: self::maxResponseBytes($c),
        );
    }

    private static function maxResponseBytes(WatchConfig $c): int
    {
        return (is_int($c->source['max_response_mb'] ?? null) ? $c->source['max_response_mb'] : 16) * 1024 * 1024;
    }

    /**
     * The metrics source of spec 009, or null when the section is absent or `enabled: false`.
     *
     * @param (Closure(): ?string)|null $stop returns a reason once the run must wind down (`RunGuard::reason()`)
     */
    public static function metricSource(WatchConfig $c, ClientInterface $http, ?Closure $stop = null): ?MetricSource
    {
        $m = $c->metrics;
        if ($m === null || !$m->enabled) {
            return null;
        }

        return new PrometheusSource(
            $http,
            $m->baseUrl,
            $m->token(),
            $m->type === 'grafana' ? $m->datasourceUid : null,
            $m->labelAllowlist,
            $m->maxPoints,
            allowInsecure: $m->allowInsecure,
            budgetSeconds: Factory::SOURCE_BUDGET_SECONDS,
            stop: $stop,
            allowPrivateNetwork: $m->allowPrivateNetwork,
        );
    }

    /**
     * The Sentry issue source of spec 009, or null when the section is absent or `enabled: false`.
     *
     * @param (Closure(): ?string)|null $stop returns a reason once the run must wind down (`RunGuard::reason()`)
     */
    public static function issueSource(WatchConfig $c, ClientInterface $http, ClockInterface $clock, ?Closure $stop = null): ?IssueSource
    {
        $s = $c->sentry;
        if ($s === null || !$s->enabled) {
            return null;
        }

        return new SentryIssueSource(
            $http,
            $s->baseUrl,
            $s->organization,
            $s->project,
            $s->token(),
            $s->maxIssues,
            clock: $clock,
            allowInsecure: $s->allowInsecure,
            budgetSeconds: Factory::SOURCE_BUDGET_SECONDS,
            stop: $stop,
            allowPrivateNetwork: $s->allowPrivateNetwork,
        );
    }
}
