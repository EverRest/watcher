<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;

/** Records every request verbatim so privacy tests can scan json_encode($fake->requests). */
final class FakeAnalyzer implements Analyzer
{
    /** @var list<TriageRequest|AnalysisRequest> */
    public array $requests = [];

    public int $triageCalls = 0;

    public int $analyzeCalls = 0;

    /** @var list<TriageResult> consumed one per triage() call before falling back to $triage */
    public array $triageScript = [];

    /** @var list<AnalysisResult> consumed one per analyze() call before falling back to $analysis */
    public array $analysisScript = [];

    public function __construct(
        public ?TriageResult $triage = null,
        public ?AnalysisResult $analysis = null,
        public ?AnalyzerFailed $failure = null,
    ) {
    }

    public function triage(TriageRequest $req): TriageResult
    {
        $this->requests[] = $req;
        $this->triageCalls++;

        return $this->failure !== null ? throw $this->failure : (array_shift($this->triageScript) ?? $this->triage ?? throw new AnalyzerFailed('no canned triage'));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $this->requests[] = $req;
        $this->analyzeCalls++;

        return $this->failure !== null ? throw $this->failure : (array_shift($this->analysisScript) ?? $this->analysis ?? throw new AnalyzerFailed('no canned analysis'));
    }
}
