<?php

declare(strict_types=1);

namespace Watcher\Hygiene\Cost;

use Throwable;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;

/**
 * Records every billed call in the {@see CostLedger} and passes everything else through untouched. Wired only when
 * `hygiene.enabled`. The ledger is advisory: a store failure here never costs the caller a paid result.
 */
final class LedgerAnalyzer implements Analyzer
{
    private int $unbooked = 0;

    public function __construct(
        private readonly Analyzer $inner,
        private readonly CostLedger $ledger,
        private readonly string $analyzeModel,
        private readonly string $escalateModel,
    ) {
    }

    /** Billed calls of this process whose ledger write failed. */
    public function unbooked(): int
    {
        return $this->unbooked;
    }

    public function triage(TriageRequest $req): TriageResult
    {
        try {
            $result = $this->inner->triage($req);
        } catch (AnalyzerFailed $e) {
            $this->book('triage', $e, $req->groups);

            throw $e;
        }
        $this->book('triage', $result, $req->groups);

        return $result;
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        // The router sends escalation through analyze() with the escalation model.
        $step = $this->escalateModel !== $this->analyzeModel && $req->model === $this->escalateModel ? 'escalate' : 'analyze';
        try {
            $result = $this->inner->analyze($req);
        } catch (AnalyzerFailed $e) {
            $this->book($step, $e, $req->groups);

            throw $e;
        }
        $this->book($step, $result, $req->groups);

        return $result;
    }

    /**
     * @param 'triage'|'analyze'|'escalate' $step
     * @param list<\Watcher\Privacy\RedactedGroup> $groups
     */
    private function book(string $step, TriageResult|AnalysisResult|AnalyzerFailed $from, array $groups): void
    {
        $usage = $from instanceof AnalyzerFailed ? $from->usage : $from->usage;
        if ($usage === null) {
            return;
        }
        try {
            $this->ledger->record($step, $usage, $groups);
        } catch (Throwable) {
            // advisory: a store failure never costs the caller a paid result, but it is never silent either
            ++$this->unbooked;
            try {
                $this->ledger->noteUnbooked();
            } catch (Throwable) {
                // the store is down altogether: the count above is all there is
            }
        }
    }
}
