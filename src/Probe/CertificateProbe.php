<?php

declare(strict_types=1);

namespace Watcher\Probe;

use Closure;
use Psr\Clock\ClockInterface;

/**
 * TLS certificate expiry of each host, read from its peer certificate on port 443. Verification is OFF on purpose: an expired
 * certificate fails a verified handshake and the reason (its date) would be lost. Nothing is sent to the host but the handshake.
 */
final class CertificateProbe implements Probe
{
    /** @param list<string> $hosts @param (Closure(string): ?array<string, mixed>)|null $peer the parsed leaf certificate, null when unreadable */
    public function __construct(
        private readonly array $hosts,
        private readonly int $warnDays,
        private readonly int $failDays,
        private readonly ClockInterface $clock,
        private readonly ?Closure $peer = null,
    ) {
    }

    public function name(): string
    {
        return 'certificate';
    }

    public function check(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $peer = $this->peer ?? self::peer(...);
        $out = [];
        foreach ($this->hosts as $host) {
            $cert = $peer($host);
            if (!is_int($cert['validTo_time_t'] ?? null)) {
                $out[] = new ProbeResult(ProbeResult::UNKNOWN, 'certificate:' . $host, "certificate $host could not be read");

                continue;
            }
            $out[] = ProbeResult::expiry('certificate:' . $host, "certificate $host", $cert['validTo_time_t'] - $now, $this->warnDays, $this->failDays);
        }

        return $out;
    }

    /** @return array<string, mixed>|null the parsed leaf certificate; null when the host cannot be reached or sends none */
    public static function peer(string $host): ?array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'peer_name' => $host,
        ]]);
        // ponytail: hosts are connected one after another, 5 s each, before the run guard and lock exist; parallel connects or one shared deadline when many hosts are configured
        $sock = @stream_socket_client("ssl://$host:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
        if ($sock === false) {
            return null; // the reason is not kept: it can name the host, and the probe reports only "could not be read"
        }
        $cert = stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? null;
        fclose($sock);

        $parsed = $cert === null ? false : openssl_x509_parse($cert);

        return $parsed === false ? null : $parsed;
    }
}
