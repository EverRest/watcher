<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Operate\JsonSummary;

final class FleetResult
{
    /**
     * @param list<ServiceResult> $services in run order
     * @param list<CorrelatedFinding> $correlated
     * @param bool|null $correlatedPosted null = nothing to post
     * @param bool|null $combinedPosted null = not in combined mode, or nothing to post
     */
    public function __construct(
        public readonly string $fleet,
        public readonly array $services,
        public readonly array $correlated = [],
        public readonly ?bool $correlatedPosted = null,
        public readonly ?bool $combinedPosted = null,
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
        $failed = $skipped = $disabled = 0;
        foreach ($this->services as $s) {
            if ($s->result !== null) {
                $lines[] = $s->service . ': ' . $s->result->summary();
            } else {
                $lines[] = $s->service . ': outcome=' . $s->outcome . ($s->reason === null ? '' : ' reason="' . self::oneLine($s->reason) . '"');
            }
            $failed += $s->outcome === 'failed' ? 1 : 0;
            $skipped += $s->outcome === ServiceResult::SKIPPED_TIME ? 1 : 0;
            $disabled += $s->outcome === 'disabled' ? 1 : 0;
        }
        $lines[] = sprintf('fleet %s: services=%d failed=%d skipped_time=%d correlated=%d', $this->fleet, count($this->services), $failed, $skipped, count($this->correlated)) . ($disabled > 0 ? sprintf(' disabled=%d', $disabled) : '');

        return $lines;
    }

    /** The one machine line (contracts/cli.md): counts, outcome names, numbers; no group content. */
    public function toJson(): string
    {
        $services = [];
        foreach ($this->services as $s) {
            $stats = $s->result?->summaryData?->stats;
            $services[] = [
                'service' => $s->service,
                'outcome' => $s->outcome,
                'reason' => JsonSummary::reason($s->reason),
                'findings' => $stats->findings ?? 0,
            ];
        }

        return json_encode([
            'fleet' => $this->fleet,
            'exit' => $this->exitCode(),
            'services' => $services,
            'correlated' => count($this->correlated),
            'correlated_posted' => $this->correlatedPosted,
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
