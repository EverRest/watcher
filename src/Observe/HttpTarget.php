<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;

/**
 * A validated `observe.http.url` (FR-017). The URL may carry a path token, so it is exposed only through url(),
 * which the sink calls; no message, string cast or debug dump of this object holds it.
 */
final class HttpTarget
{
    /** Held in a closure so var_export/print_r of the object carry no URL. */
    private readonly \Closure $url;

    private function __construct(#[\SensitiveParameter] string $url)
    {
        $this->url = static fn (): string => $url;
    }

    /** @param list<string> $allowedHosts */
    public static function parse(#[\SensitiveParameter] string $url, array $allowedHosts, bool $allowInsecure, bool $allowPrivateNetwork, ?string $logSourceHost): self
    {
        // The shared rule and the one SSRF host classifier (SecureUrl::internalHost): https; http only for a loopback, private or
        // cluster-local host with allow_insecure; loopback, link-local, CGNAT, metadata and reserved ranges refused in every spelling.
        $problem = SecureUrl::problem($url, $allowInsecure);
        $p = parse_url($url);
        $host = strtolower(rtrim(trim($p['host'] ?? '', '[]'), '.'));
        if ($problem !== null || $p === false || $host === '') {
            throw new InvalidConfig(match (true) {
                str_contains((string) $problem, 'credentials') => 'observe.http.url must not contain credentials',
                default => 'observe.http.url must be an https URL with a host',
            });
        }
        // For an event endpoint one flag admits loopback and RFC 1918 hosts; link-local (cloud credentials), CGNAT, metadata and the
        // reserved ranges stay refused whatever is allowed.
        if (SecureUrl::internalHost($p['host'] ?? '', $allowPrivateNetwork, $allowPrivateNetwork)) {
            throw new InvalidConfig('observe.http.url host is not allowed');
        }
        if ($allowedHosts !== [] && !in_array($host, $allowedHosts, true)) {
            throw new InvalidConfig('observe.http.url host is not in observe.http.allowed_hosts');
        }
        // Names are never resolved (owner decision O5, a documented limit): `169.254.169.254.nip.io` is accepted.
        if ($logSourceHost !== null) {
            $source = strtolower(rtrim(trim((string) (str_contains($logSourceHost, '://') ? parse_url($logSourceHost, PHP_URL_HOST) : $logSourceHost), '[]'), '.'));
            if ($source !== '' && $source === $host) {
                throw new InvalidConfig('observe.http.url must not point at the log source');
            }
        }

        return new self($url);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    public function url(): string
    {
        return ($this->url)();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('HttpTarget is not serializable');
    }
}
