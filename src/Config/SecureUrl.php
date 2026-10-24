<?php

declare(strict_types=1);

namespace Watcher\Config;

/**
 * The one rule for a URL that carries a credential (Slack webhook, Loki base URL): https, a host, no userinfo.
 * Plain http is accepted only for a loopback, RFC1918 or cluster-local (`.svc`, `.cluster.local`) host, and only when the operator
 * set `allow_insecure`: a trust decision about the network between the two, never a default.
 * The answer is a fixed phrase: the URL (it may be the credential) is never echoed.
 */
final class SecureUrl
{
    public static function problem(string $url, bool $allowInsecure = false): ?string
    {
        $p = parse_url($url);
        if ($p === false || ($p['host'] ?? '') === '') {
            return 'must be a URL with a host';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'must not carry credentials in the URL';
        }
        $scheme = strtolower($p['scheme'] ?? '');
        if ($scheme === 'https' || ($scheme === 'http' && $allowInsecure && self::trustedNetwork($p['host']))) {
            return null;
        }

        return 'must use https (http only for a loopback, private-network or cluster-local host with allow_insecure: true)';
    }

    private static function trustedNetwork(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost' || $host === '::1'
            || preg_match('/^(?:127(?:\.\d{1,3}){3}|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2})$/D', $host) === 1
            || preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:svc|svc\.cluster\.local|cluster\.local)$/D', $host) === 1;
    }
}
