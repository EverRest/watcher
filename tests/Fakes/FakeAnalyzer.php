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

        return $this->failure !== null ? throw $this->failure : ($this->triage ?? throw new AnalyzerFailed('no canned triage'));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $this->requests[] = $req;
        $this->analyzeCalls++;

        return $this->failure !== null ? throw $this->failure : ($this->analysis ?? throw new AnalyzerFailed('no canned analysis'));
    }
}
