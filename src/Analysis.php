<?php

declare(strict_types=1);

namespace Watcher;

use Watcher\Analyze\RouterResult;
use Watcher\Analyze\TriageCheckpoint;
use Watcher\Analyze\TruncationResult;
use Watcher\ApiContext\EndpointContext;
use Watcher\Privacy\RedactedGroup;
use Watcher\State\BufferedStateStore;

/**
 * @internal Carrier between Watcher's own steps.
 *
 * What the model step produced; the digest is built from it, the checkpoint and buffer are settled once it is delivered. */
final class Analysis
{
    /**
     * @param list<RedactedGroup>  $redacted
     * @param array<string, RedactedGroup> $byFingerprint the same groups, keyed
     * @param list<string>         $failedDependencies
     * @param array<string, string> $annotations fleet annotations per fingerprint
     */
    public function __construct(
        public readonly RouterResult $routed,
        public readonly TruncationResult $truncation,
        public readonly array $redacted,
        public readonly array $byFingerprint,
        public readonly int $undeclared,
        public readonly array $failedDependencies,
        public readonly array $annotations,
        public readonly BufferedStateStore $buffer,
        public readonly TriageCheckpoint $checkpoint,
        public readonly ?EndpointContext $endpoints = null,
    ) {
    }
}
