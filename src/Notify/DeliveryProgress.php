<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Closure;

/** Resume a multi-part chat delivery after a failed post (parts already accepted are skipped). */
final class DeliveryProgress
{
    /**
     * @param (Closure(int): void)|null $persist called after each successful part with a 1-based count
     */
    public function __construct(
        public readonly int $skipParts = 0,
        public readonly ?Closure $persist = null,
    ) {
    }
}
