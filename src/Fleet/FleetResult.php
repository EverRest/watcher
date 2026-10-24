<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Operate\JsonSummary;

final class FleetResult
{
    /**
     * @param list<ServiceResult> $services in run order
     * @param list<object> $correlated CorrelatedFinding objects (empty until correlation exists)
     * @param bool|null $correlatedPosted null = nothing to post
     * @param bool|null $combinedPosted null = not in combined mode, or nothing to post
     * @param float|null $fleetCostTodayUsd fleet counter after the run (counted even without a fleet cap; 0 in a dry run)
     */
    public function __construct(
        public readonly string $fleet,
        public readonly array $services,
        public readonly array $correlated = [],
        public readonly ?bool $correlatedPosted = null,
        public readonly ?bool $combinedPosted = null,
        public readonly ?float $fleetCostTodayUsd = null,
    ) {
    }

    public function exitCode(): int
    {
        $code = $this->correlatedPosted === false || $this->combinedPosted === false ? 1 : 0;
        foreach ($this->services as $s) {
            $code = max($code, $s->exitCode());
        }

        return $code;
    }

    /** @return list<string> one line per service, then the fleet line; no line breaks, no double quotes */
    public function lines(): array
    {
        $lines = [];
        $failed = $skipped = 0;
        foreach ($this->services as $s) {
            if ($s->result !== null) {
                $lines[] = $s->service . ': ' . $s->result->summary();
            } else {
                $lines[] = $s->service . ': outcome=' . $s->outcome . ($s->reason === null ? '' : ' reason="' . self::oneLine($s->reason) . '"');
            }
            $failed += $s->outcome === 'failed' ? 1 : 0;
            $skipped += $s->outcome === ServiceResult::SKIPPED_TIME ? 1 : 0;
        }
        $lines[] = sprintf('fleet %s: services=%d failed=%d skipped_time=%d correlated=%d', $this->fleet, count($this->services), $failed, $skipped, count($this->correlated));

        return $lines;
    }

    /** The one machine line (contracts/cli.md): counts, outcome names, numbers; no group content. */
    public function toJson(): string
    {
        $services = [];
        $cost = 0.0;
        foreach ($this->services as $s) {
            $stats = $s->result?->summaryData?->stats;
            $spent = $stats->costUsd ?? $s->result?->usage?->costUsd ?? 0.0;
            $cost += $spent;
            $services[] = [
                'service' => $s->service,
                'outcome' => $s->outcome,
                'reason' => JsonSummary::reason($s->reason),
                'cost_usd' => $spent,
                'findings' => $stats->findings ?? 0,
            ];
        }

        return json_encode([
            'fleet' => $this->fleet,
            'exit' => $this->exitCode(),
            'services' => $services,
            'correlated' => count($this->correlated),
            'correlated_posted' => $this->correlatedPosted,
            'cost_usd' => $cost,
            'fleet_cost_today_usd' => $this->fleetCostTodayUsd,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function invalidJson(): string
    {
        return '{"fleet":null,"exit":2,"error":"invalid_config"}';
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace('"', "'", $text)));
    }
}
