<?php

declare(strict_types=1);

namespace Watcher\Analyze;

final class Alert
{
    /**
     * @param string       $counter 'cost' or 'tokens'
     * @param list<string> $claimed every state key claimed by the check that produced this alert
     */
    public function __construct(
        public readonly string $counter,
        public readonly int $percent,
        public readonly float $value,
        public readonly float $limit,
        public readonly array $claimed = [],
    ) {
    }

    public function noticeText(string $service): string
    {
        return $this->counter === 'cost'
            ? sprintf('budget %d%% — $%.2f of $%.2f spent today (%s)', $this->percent, $this->value, $this->limit, $service)
            : sprintf('budget %d%% — %d of %d tokens used today (%s)', $this->percent, $this->value, $this->limit, $service);
    }
}
