<?php

declare(strict_types=1);

namespace Watcher\State;

/**
 * Optional: a store that can add to the two daily spend counters only when both stay within their caps, in one atomic
 * step (M2: the budget check and the reservation must not be two operations, or parallel runs each pass the check).
 * Found with `instanceof`; a store without it keeps the plain check-then-book path.
 */
interface ReservableCounters
{
    /**
     * Adds $usd to $usdKey and $tokens to $tokensKey when $usdKey + $usd <= $usdCap and, if given, $tokensKey + $tokens <= $tokenCap.
     * Both keys carry an expiry by construction.
     *
     * @return bool|null true = reserved, false = it would exceed a cap (nothing added), null = this store cannot reserve (a wrapper around one that cannot)
     */
    public function reserve(string $usdKey, string $tokensKey, float $usd, float $tokens, float $usdCap, ?float $tokenCap, int $ttlSeconds): ?bool;
}
