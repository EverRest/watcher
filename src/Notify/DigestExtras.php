<?php

declare(strict_types=1);

namespace Watcher\Notify;

/**
 * What the optional sources of spec 009 add to the digest: the metrics line, the metric anomalies left out, and one note per
 * source that could not be read. Every string is masked text or a fixed token (never a URL, token, query or body).
 */
final class DigestExtras
{
    /** The only reasons a note may carry; anything else is shown as `unreachable`. */
    public const TOKENS = ['unreachable', 'unauthorized', 'timeout', 'bad_response', 'rate_limited', 'too_large', 'window_too_old'];

    /**
     * @param array<string, string> $unavailable source tag (`metrics`, `sentry`) => failure token
     */
    public function __construct(
        public readonly ?string $metricsLine = null,
        public readonly array $unavailable = [],
        public readonly int $sentryDropped = 0,
        public readonly int $anomaliesNotShown = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->metricsLine === null && $this->unavailable === [] && $this->sentryDropped === 0 && $this->anomaliesNotShown === 0;
    }

    /** @return list<string> the notes of the footer: fixed text and tokens only */
    public function notes(): array
    {
        $out = [];
        foreach ($this->unavailable as $tag => $token) {
            $out[] = ($tag === 'sentry' ? 'Sentry' : ($tag === 'metrics' ? 'Metrics' : 'Source')) . ' unavailable (' . (in_array($token, self::TOKENS, true) ? $token : 'unreachable') . ')';
        }
        if ($this->sentryDropped > 0) {
            $out[] = $this->sentryDropped . ' Sentry issues dropped';
        }
        if ($this->anomaliesNotShown > 0) {
            $out[] = $this->anomaliesNotShown . ' metric anomalies not shown';
        }

        return $out;
    }
}
