<?php

declare(strict_types=1);

namespace Watcher\Analyze;

/** Renews the run lock before every model call (A4-05). */
final class RenewingAnalyzer implements Analyzer
{
    public function __construct(private readonly Analyzer $inner, private readonly \Closure $renew)
    {
    }

    public function triage(TriageRequest $req): TriageResult
    {
        ($this->renew)();

        return $this->inner->triage($req);
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        ($this->renew)();

        return $this->inner->analyze($req);
    }
}
