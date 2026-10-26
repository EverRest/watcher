<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/** The seam spec 006 fills in (research R7). The shipped implementation says "not enabled". */
interface HygieneSource
{
    /** @return array<string, mixed>|null the machine-readable hygiene report of spec 006, null when there is none */
    public function latest(): ?array;
}
