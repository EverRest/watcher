<?php

declare(strict_types=1);

namespace Watcher\Config;

/**
 * The one rule for a URL that carries a credential (Slack webhook, Loki base URL): https, a host, no userinfo.
 * Plain http is accepted only for a loopback host and only when the operator set `allow_insecure`.
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
        if ($scheme === 'https' || ($scheme === 'http' && $allowInsecure && self::loopback($p['host']))) {
            return null;
        }

        return 'must use https (http only for a loopback host with allow_insecure: true)';
    }

    private static function loopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost' || $host === '::1' || preg_match('/^127(?:\.\d{1,3}){3}$/D', $host) === 1;
    }
}
