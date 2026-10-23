<?php

declare(strict_types=1);

namespace Watcher\Analyze;

interface Analyzer
{
    /** @throws AnalyzerFailed */
    public function triage(TriageRequest $req): TriageResult;

    /** @throws AnalyzerFailed */
    public function analyze(AnalysisRequest $req): AnalysisResult;
}
