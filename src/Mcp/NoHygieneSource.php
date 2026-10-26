<?php

declare(strict_types=1);

namespace Watcher\Mcp;

final class NoHygieneSource implements HygieneSource
{
    public function latest(): ?array
    {
        return null;
    }
}
