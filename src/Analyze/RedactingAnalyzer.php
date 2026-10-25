<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Privacy\Redactor;

/** Model output is scrubbed at the boundary (Principle I). */
final class RedactingAnalyzer implements Analyzer
{
    public function __construct(private readonly Analyzer $inner, private readonly Redactor $redactor)
    {
    }

    public function triage(TriageRequest $req): TriageResult
    {
        return $this->inner->triage($req);
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $r = $this->inner->analyze($req);

        return new AnalysisResult(array_map($this->redactor->redactFinding(...), $r->findings), $r->usage);
    }
}
