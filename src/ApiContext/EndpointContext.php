<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/**
 * What the API-context step produced for one run: a digest line and a short model field per group, how many distinct
 * endpoints the cap left out, and why nothing was attached when the source failed. Everything in it is already masked and capped.
 */
final class EndpointContext
{
    /**
     * @param array<string, string> $lines   fingerprint => digest line
     * @param array<string, string> $model   fingerprint => the short `ep` field for the analysis payload
     * @param ?string               $unavailable failure token (the fixed vocabulary of OpenApiSource), or null
     */
    public function __construct(
        public readonly array $lines = [],
        public readonly array $model = [],
        public readonly int $skipped = 0,
        public readonly int $droppedOps = 0,
        public readonly ?string $unavailable = null,
        public readonly bool $modelDropped = false,
        public readonly string $cache = 'none',
    ) {
    }

    public static function unavailable(string $token): self
    {
        return new self(unavailable: $token, cache: 'failed');
    }

    /** The `ep` fields went over the token budget and were dropped; the digest lines stay. */
    public function withoutModelField(): self
    {
        return new self($this->lines, [], $this->skipped, $this->droppedOps, $this->unavailable, $this->model !== [], $this->cache);
    }
}
