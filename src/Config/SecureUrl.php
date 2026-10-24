<?php

declare(strict_types=1);

namespace Watcher\Config;

/**
 * The one rule for a URL that carries a credential (Slack webhook, Loki base URL): https, a host, no userinfo.
 * Plain http is accepted only for a loopback, RFC1918 or cluster-local (`.svc`, `.cluster.local`) host, and only when the operator
 * set `allow_insecure`: a trust decision about the network between the two, never a default.
 * The answer is a fixed phrase: the URL (it may be the credential) is never echoed.
 * Callers that fetch from a URL they were given (metrics, Sentry, an OpenAPI spec) opt in to {@see internalHost()}, the one SSRF
 * host classifier; Loki and Slack keep their rule.
 */
final class SecureUrl
{
    /**
     * @param bool $refuseInternal opt-in: also refuse a host that {@see internalHost()} classifies as internal. `$allowInsecure`
     *        doubles as "loopback is expected" (a local test setup); `$allowPrivate` admits RFC 1918 (an internal Prometheus).
     */
    public static function problem(string $url, bool $allowInsecure = false, bool $refuseInternal = false, bool $allowPrivate = false): ?string
    {
        $p = parse_url($url);
        if ($p === false || ($p['host'] ?? '') === '') {
            return 'must be a URL with a host';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'must not carry credentials in the URL';
        }
        if ($refuseInternal && self::internalHost($p['host'], $allowInsecure, $allowPrivate)) {
            return 'must not point at a loopback, link-local, private-network or metadata address';
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

    /**
     * The one SSRF host classifier: true when `$host` (as parse_url returns it) is, or cannot be proven not to be, an internal
     * address. Refused: 0/8, 127/8 (unless `$allowLoopback`), 10/8, 172.16/12, 192.168/16 (unless `$allowPrivate`), 169.254/16,
     * 100.64/10, 224/4 and 240/4, in every inet_aton spelling (decimal, hex, octal, 1-4 parts); IPv6 ::/96 (`::1` unless
     * `$allowLoopback`), fc00::/7, fe80::/10, fec0::/10, ff00::/8, NAT64 64:ff9b::/96 and 64:ff9b:1::/48, Teredo 2001::/32,
     * IPv4-mapped ::ffff:0:0/96 and 6to4 2002::/16 (the embedded IPv4 is classified); `localhost`, `*.localhost` and the
     * metadata names. Fail closed: one trailing dot is the same host, but an empty label, a `%zone` or a ':' host that does not
     * parse is refused, whatever the allowances.
     * ponytail: a host NAME is never resolved, so DNS that answers with a private address (rebinding) passes; resolve once and
     * connect to that address (curl CURLOPT_RESOLVE) if URLs ever come from untrusted input.
     */
    public static function internalHost(string $host, bool $allowLoopback = false, bool $allowPrivate = false): bool
    {
        $host = strtolower($host);
        if ($host === '' || str_contains($host, '%')) {
            return true;
        }
        if ($host[0] === '[' || str_contains($host, ':')) {
            $bin = @inet_pton($host[0] === '[' ? (str_ends_with($host, ']') ? substr($host, 1, -1) : '') : $host);

            return $bin === false || strlen($bin) !== 16 || self::v6($bin, $allowLoopback, $allowPrivate);
        }
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if ($host === '' || in_array('', explode('.', $host), true)) {
            return true;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return !$allowLoopback;
        }
        if ($host === 'metadata' || $host === 'metadata.google.internal') {
            return true;
        }
        $v4 = self::ipv4($host);

        return $v4 !== null && self::v4($v4, $allowLoopback, $allowPrivate);
    }

    private static function v4(int $ip, bool $allowLoopback, bool $allowPrivate): bool
    {
        $a = $ip >> 24;
        if ($a === 127) {
            return !$allowLoopback;
        }
        if ($a === 10 || ($ip >> 20) === 0xAC1 || ($ip >> 16) === 0xC0A8) {
            return !$allowPrivate;
        }

        return $a === 0 || $a >= 224 || ($ip >> 16) === 0xA9FE || ($ip >> 22) === 0x191;
    }

    private static function v6(string $bin, bool $allowLoopback, bool $allowPrivate): bool
    {
        if (str_starts_with($bin, str_repeat("\0", 12))) { // ::/96: unspecified, loopback, IPv4-compatible
            return !($allowLoopback && $bin === str_repeat("\0", 15) . "\1");
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) { // ::ffff:0:0/96 IPv4-mapped
            return self::v4(self::v4At($bin, 12), $allowLoopback, $allowPrivate);
        }
        if (str_starts_with($bin, "\x20\x02")) { // 6to4 carries the IPv4 in bytes 2-5
            return self::v4(self::v4At($bin, 2), $allowLoopback, $allowPrivate);
        }
        $head = ord($bin[0]) << 8 | ord($bin[1]);

        return (str_starts_with($bin, "\x00\x64\xff\x9b") && (substr($bin, 4, 8) === str_repeat("\0", 8) || substr($bin, 4, 2) === "\x00\x01")) // NAT64 /96 and /48
            || str_starts_with($bin, "\x20\x01\0\0") // Teredo 2001::/32
            || ($head & 0xFFC0) === 0xFEC0 // fec0::/10 site-local
            || ($head & 0xFE00) === 0xFC00 // fc00::/7 unique local
            || ($head & 0xFFC0) === 0xFE80 // fe80::/10 link-local
            || ($head & 0xFF00) === 0xFF00; // ff00::/8 multicast
    }

    private static function v4At(string $bin, int $at): int
    {
        return ord($bin[$at]) << 24 | ord($bin[$at + 1]) << 16 | ord($bin[$at + 2]) << 8 | ord($bin[$at + 3]);
    }

    /** inet_aton spellings: 1-4 parts, each decimal, 0x hex or leading-0 octal, the last part filling the remaining bytes; null when the host is a name. */
    private static function ipv4(string $host): ?int
    {
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }
        $n = [];
        foreach ($parts as $p) {
            if (preg_match('/^(0x[0-9a-f]*|0[0-7]*|[1-9][0-9]*)$/D', $p) !== 1) {
                return null;
            }
            $n[] = intval($p, 0);
        }
        $last = array_pop($n);
        if ($last > 256 ** (4 - count($n)) - 1 || array_filter($n, static fn (int $x): bool => $x > 255) !== []) {
            return null;
        }
        $ip = $last;
        foreach ($n as $i => $x) {
            $ip += $x << (24 - 8 * $i);
        }

        return $ip;
    }
}
