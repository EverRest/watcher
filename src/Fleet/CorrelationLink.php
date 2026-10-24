<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Privacy\RedactedGroup;

/** One consumer group explained by one origin group (data-model.md). Holds redacted groups only. */
final class CorrelationLink
{
    public function __construct(
        public readonly string $originService,
        public readonly RedactedGroup $origin,
        public readonly string $consumerService,
        public readonly RedactedGroup $consumer,
        /** the declared dependency name that matched */
        public readonly string $dependency,
    ) {
    }
}
