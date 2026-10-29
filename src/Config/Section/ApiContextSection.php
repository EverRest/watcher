<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;

/** Validates the `api_context` section (OpenAPI context, spec 010). */
final class ApiContextSection
{
    /**
     * The `api_context` section (spec 010): validated even when `enabled` is false, so a later flip never reveals a bad value. Key paths only.
     *
     * @return array<string, mixed>
     */
    public static function apiContext(mixed $sec, int $retention): array
    {
        if (!is_array($sec)) {
            throw new InvalidConfig('api_context must be a map');
        }
        SectionFields::only($sec, ['enabled', 'source', 'auth_token', 'ttl', 'max_endpoints', 'summary_cap', 'max_bytes', 'base_path', 'ignore_prefixes', 'allow_insecure', 'allow_private_network', 'allowed_roots'], 'api_context');
        $enabled = SectionFields::flag($sec, 'enabled', 'api_context.enabled');
        $insecure = SectionFields::flag($sec, 'allow_insecure', 'api_context.allow_insecure', false);
        $private = SectionFields::flag($sec, 'allow_private_network', 'api_context.allow_private_network', false);
        $source = $sec['source'] ?? null;
        if ($source !== null && $source !== '' || $enabled) {
            $source = is_string($source) && $source !== '' ? $source : throw new InvalidConfig('api_context.source is required');
            if (preg_match('~\.ya?ml(?:[?#].*)?$~i', $source) === 1) {
                throw new InvalidConfig('api_context.source must be a JSON document (YAML is not supported yet)');
            }
            if (preg_match('~^https?://~i', $source) === 1) {
                if (\Watcher\ApiContext\OpenApiSource::urlProblem($source, $insecure, $private) !== null) {
                    throw new InvalidConfig('api_context.source must be an https URL; plain http needs allow_insecure and allow_private_network and a loopback, private-network or cluster-local host, and no internal address is allowed otherwise');
                }
            } elseif (preg_match('~^(?:file:(?://)?)?/~i', $source) !== 1 || str_contains(preg_replace('~^file:~i', '', $source) ?? '', '://')) {
                throw new InvalidConfig('api_context.source must be an https URL or an absolute file path');
            }
        }
        $token = $sec['auth_token'] ?? null;
        if ($token !== null && (!is_string($token) || $token === '')) {
            throw new InvalidConfig('api_context.auth_token must be a non-empty string');
        }
        $ttl = $retention < 86400 ? $retention : 86400;
        if (array_key_exists('ttl', $sec)) {
            $ttl = is_string($sec['ttl']) ? SectionFields::seconds($sec['ttl']) : null;
            if ($ttl === null || $ttl < 1 || $ttl > $retention) {
                throw new InvalidConfig('api_context.ttl must be a duration between 1 second and state.retention');
            }
        }
        $basePath = $sec['base_path'] ?? null;
        if ($basePath !== null && (!is_string($basePath) || preg_match('~^/[A-Za-z0-9._\-/]*$~D', $basePath) !== 1 || in_array('..', explode('/', $basePath), true))) {
            throw new InvalidConfig('api_context.base_path must be a path starting with /');
        }
        $prefixes = $sec['ignore_prefixes'] ?? [];
        if (!is_array($prefixes) || !array_is_list($prefixes) || count($prefixes) > 10 || array_filter($prefixes, static fn (mixed $p): bool => is_string($p) && preg_match('~^/[A-Za-z0-9._\-/]*$~D', $p) === 1 && !in_array('..', explode('/', $p), true)) !== $prefixes) {
            throw new InvalidConfig('api_context.ignore_prefixes must be a list of at most 10 paths starting with /');
        }
        $roots = $sec['allowed_roots'] ?? [];
        if (!is_array($roots) || !array_is_list($roots) || count($roots) > 10 || array_filter($roots, static fn (mixed $p): bool => is_string($p) && str_starts_with($p, '/') && !str_contains($p, '://') && !in_array('..', explode('/', $p), true)) !== $roots) {
            throw new InvalidConfig('api_context.allowed_roots must be a list of at most 10 absolute paths');
        }

        return [
            'enabled' => $enabled,
            'source' => is_string($source) ? $source : null,
            'auth_token' => $token,
            'ttl' => $ttl,
            'max_endpoints' => SectionFields::boundedInt($sec, 'max_endpoints', 5, 1, 20),
            'summary_cap' => SectionFields::boundedInt($sec, 'summary_cap', 80, 20, 200),
            'max_bytes' => SectionFields::boundedInt($sec, 'max_bytes', 1048576, 65536, 2097152),
            'base_path' => $basePath,
            'ignore_prefixes' => $prefixes,
            'allow_insecure' => $insecure,
            'allow_private_network' => $private,
            'allowed_roots' => $roots,
        ];
    }
}
