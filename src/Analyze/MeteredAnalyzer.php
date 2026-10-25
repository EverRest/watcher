<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Psr\Log\LoggerInterface;
use Watcher\Fleet\FleetSeam;
use Watcher\Operate\RunStats;

/** Books each analyzer call into Spend as it returns. */
final class MeteredAnalyzer implements Analyzer
{
    /** @var array<string, true> */
    private array $warned = [];

    /**
     * @param array<string, string> $models configured model per step, to tell an escalation from the main analysis
     */
    public function __construct(
        private readonly Analyzer $inner,
        private readonly Spend $spend,
        private readonly CostCalculator $calculator,
        private readonly ?LoggerInterface $log,
        private readonly ?RunStats $stats,
        private readonly array $models,
        private readonly ?FleetSeam $fleet = null,
    ) {
    }

    public function triage(TriageRequest $req): TriageResult
    {
        try {
            $r = $this->inner->triage($req);
        } catch (AnalyzerFailed $e) {
            throw $this->bookFailed($e, 'triage');
        }
        $this->book($r->usage, 'triage');

        return $r;
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        try {
            $r = $this->inner->analyze($req);
        } catch (AnalyzerFailed $e) {
            throw $this->bookFailed($e, $this->analyzeStep($req));
        }
        $this->book($r->usage, $this->analyzeStep($req));

        return $r;
    }

    /** @return 'analyze'|'escalate' */
    private function analyzeStep(AnalysisRequest $req): string
    {
        $escalate = $this->models['escalate'] ?? null;

        return $escalate !== null && $req->model === $escalate && $escalate !== ($this->models['analyze'] ?? null) ? 'escalate' : 'analyze';
    }

    /** @param 'triage'|'analyze'|'escalate' $step */
    private function bookFailed(AnalyzerFailed $e, string $step): AnalyzerFailed
    {
        if ($e->usage !== null) {
            $this->book($e->usage, $step);
        } else {
            $this->spend->release();
        }

        return $e;
    }

    /** @param 'triage'|'analyze'|'escalate' $step */
    private function book(Usage $u, string $step): void
    {
        $cost = 0.0;
        try {
            $this->spend->add($u, $cost = $this->calculator->cost($u));
            $this->fleet?->book($u, $cost);
        } catch (UnknownModelPrice) {
            $this->spend->add($u, 0.0);
            $this->fleet?->book($u, 0.0);
            if (!isset($this->warned[$u->model])) {
                $this->warned[$u->model] = true;
                $this->log?->warning('paid analyzer call has no price configured; recorded at cost 0', ['model' => $u->model]);
            }
        }
        $this->stats?->addUsage($step, new Usage($u->model, $u->inputTokens, $u->outputTokens, $u->cacheReadTokens, $u->cacheWriteTokens, $cost));
    }
}
