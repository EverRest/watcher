<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Schedule\Window;

/**
 * Deep links into the tracing backend (spec 009 US4); strings only, never called. Principle I: built from config, the
 * stream tags, the window and, when valid, one trace id (the only place an id may appear). Tempo wins over Datadog APM
 * when both are configured, like Grafana before Datadog in LogLinkBuilder.
 */
final class TraceLinkBuilder
{
    /**
     * @param array<string, mixed>  $links   links config (tempo, datadog_apm, datadog)
     * @param array<string, string> $tags    stream tags (`env` is used for Datadog)
     * @param string                $service service.name, the default for the search form
     */
    public function __construct(private readonly array $links, private readonly array $tags, private readonly string $service)
    {
    }

    public function forGroup(?string $traceId, Window $window): ?string
    {
        $id = $traceId !== null && preg_match('/^[0-9a-f]{16,32}$/D', $traceId) === 1 ? $traceId : null;
        $tempo = $this->links['tempo'] ?? null;
        $apm = $this->links['datadog_apm'] ?? null;
        $datadog = $this->links['datadog'] ?? null;

        if (is_array($tempo) && self::set($tempo['base_url'] ?? null) && self::set($tempo['datasource_uid'] ?? null)) {
            $service = self::set($tempo['service_name'] ?? null) ? (string) $tempo['service_name'] : $this->service;

            return $this->tempo((string) $tempo['base_url'], (string) $tempo['datasource_uid'], $id, $service, $window);
        }
        if (is_array($apm) && is_array($datadog) && self::set($datadog['app_url'] ?? null)) {
            $service = self::set($apm['service'] ?? null) ? (string) $apm['service'] : $this->service;

            return $this->datadog((string) $datadog['app_url'], $id, $service, $window);
        }

        return null;
    }

    /**
     * UNVERIFIED: the Grafana Explore URL shape for a Tempo TraceQL query is version dependent; this is the only place
     * that knows it. Pinned by a golden test, not by a live instance.
     */
    private function tempo(string $base, string $uid, ?string $id, string $service, Window $window): string
    {
        $query = $id ?? '{resource.service.name="' . self::esc($service) . '"}';
        $panes = json_encode(['a' => [
            'datasource' => $uid,
            'queries' => [['refId' => 'A', 'queryType' => 'traceql', 'query' => $query, 'datasource' => ['type' => 'tempo', 'uid' => $uid]]],
            'range' => ['from' => $window->start->format('Uv'), 'to' => $window->end->format('Uv')],
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return rtrim($base, '/') . '/explore?schemaVersion=1&panes=' . rawurlencode($panes) . '&orgId=1';
    }

    /** UNVERIFIED: the `apm/traces?query=trace_id:<id>` search form (owner decision D3); golden test only. */
    private function datadog(string $appUrl, ?string $id, string $service, Window $window): string
    {
        if ($id !== null) {
            $query = 'trace_id:' . $id;
        } else {
            $query = 'service:' . (preg_match('~^[\w.\-/]+$~', $service) === 1 ? $service : '"' . self::esc($service) . '"');
            $env = $this->tags['env'] ?? null;
            if ($env !== null && $env !== '') {
                $query .= ' env:' . (preg_match('~^[\w.\-/]+$~', $env) === 1 ? $env : '"' . self::esc($env) . '"');
            }
        }

        return rtrim($appUrl, '/') . '/apm/traces?query=' . rawurlencode($query)
            . '&start=' . $window->start->format('Uv') . '&end=' . $window->end->format('Uv');
    }

    private static function esc(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '"' => '\\"']);
    }

    private static function set(mixed $v): bool
    {
        return is_string($v) && $v !== '';
    }
}
