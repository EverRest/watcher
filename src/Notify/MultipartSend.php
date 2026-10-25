<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Closure;
use Watcher\Source\Retry;

/** Shared send loop for Telegram and Discord multi-part messages. */
final class MultipartSend
{
    /**
     * @param list<string>                       $parts
     * @param Closure(string, Retry): ?string $post
     */
    public static function run(array $parts, Retry $retry, ?DeliveryProgress $progress, Closure $post, string $driver): void
    {
        $skip = $progress === null ? 0 : $progress->skipParts;
        $persist = $progress?->persist;
        foreach ($parts as $i => $text) {
            if ($i < $skip) {
                continue;
            }
            $failure = $post($text, $retry);
            if ($failure !== null) {
                throw new DeliveryFailed($driver . ' delivery failed: ' . $failure . (count($parts) > 1 ? sprintf(' at part %d of %d', $i + 1, count($parts)) : ''));
            }
            $persist?->__invoke($i + 1);
        }
    }
}
