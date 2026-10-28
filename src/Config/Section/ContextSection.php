<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;

/** Validates `dependencies`, `repo` and `links`: where a finding leads. */
final class ContextSection
{
    /** @return array<string, array<string, mixed>> */
    public static function dependencies(mixed $deps): array
    {
        if (!is_array($deps)) {
            throw new InvalidConfig('dependencies must be a map');
        }
        foreach ($deps as $name => $dep) {
            if (!is_array($dep) || !is_array($dep['stream_tags'] ?? null)) {
                throw new InvalidConfig('dependencies.' . $name . '.stream_tags must be a map');
            }
            SectionFields::only($dep, ['stream_tags', 'source'], 'dependencies.' . $name);
            if (($dep['source'] ?? null) !== null) {
                throw new InvalidConfig('dependencies.' . $name . '.source is not available yet; the service source serves all dependencies');
            }
        }

        return $deps;
    }

    /** @return array<string, mixed>|null */
    public static function repo(mixed $repo): ?array
    {
        if ($repo === null) {
            return null;
        }
        if (!is_array($repo) || !in_array($repo['provider'] ?? null, ['gitlab', 'github'], true)) {
            throw new InvalidConfig('repo.provider must be gitlab or github');
        }
        SectionFields::only($repo, ['provider', 'web_url', 'path_map'], 'repo');
        SectionFields::str($repo, 'web_url', 'repo.web_url');

        return $repo;
    }

    /**
     * @param array<string, mixed> $links
     * @return array<string, mixed>
     */
    public static function links(array $links): array
    {
        SectionFields::only($links, ['grafana', 'datadog', 'logs', 'code', 'tempo', 'datadog_apm'], 'links');
        foreach (['grafana' => ['base_url', 'datasource_uid'], 'datadog' => ['app_url']] as $name => $keys) {
            if (is_array($links[$name] ?? null)) {
                SectionFields::only($links[$name], $keys, 'links.' . $name);
            }
        }

        // Spec 009 US4: trace links. Built, never fetched; the trace id exists only inside the link URL.
        foreach (['tempo' => ['base_url', 'datasource_uid', 'trace_field', 'service_name'], 'datadog_apm' => ['trace_field', 'service']] as $name => $keys) {
            if (!array_key_exists($name, $links)) {
                continue;
            }
            if (!is_array($links[$name])) {
                throw new InvalidConfig('links.' . $name . ' must be a map');
            }
            SectionFields::only($links[$name], $keys, 'links.' . $name);
            foreach ($keys as $k) {
                $v = $links[$name][$k] ?? null;
                $bad = $v !== null && (!is_string($v) || $v === '' || ($k === 'trace_field' && preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $v) !== 1));
                if ($bad || ($name === 'tempo' && in_array($k, ['base_url', 'datasource_uid'], true) && $v === null)) {
                    throw new InvalidConfig('links.' . $name . '.' . $k . ($v === null ? ' is required' : ' is invalid'));
                }
            }
        }
        if (isset($links['tempo']['base_url']) && SecureUrl::problem((string) $links['tempo']['base_url']) !== null) {
            throw new InvalidConfig('links.tempo.base_url must be an https URL without credentials');
        }
        if (array_key_exists('datadog_apm', $links) && !is_string($links['datadog']['app_url'] ?? null)) {
            throw new InvalidConfig('links.datadog_apm requires links.datadog.app_url');
        }

        return $links;
    }
}
