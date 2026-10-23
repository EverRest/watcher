<?php

declare(strict_types=1);

namespace Watcher\Source;

use InvalidArgumentException;

final class StreamSelector
{
    /** @param array<string, string> $tags */
    public function __construct(public readonly array $tags)
    {
        foreach (array_keys($tags) as $key) {
            if ((string) $key === '') {
                throw new InvalidArgumentException('Stream tag keys must be non-empty.');
            }
        }
    }
}
