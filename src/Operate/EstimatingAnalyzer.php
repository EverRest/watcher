<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;

/**
 * `--estimate`: no model call (it holds no HTTP client). Every group is "interesting" so the router reaches
 * `analyze()` too; each step records what would have been sent. The analyze figure is therefore an upper bound.
 */
final class EstimatingAnalyzer implements Analyzer
{
    /** @var list<array{step: string, model: string, groups: int}> */
    private array $steps = [];

    public function triage(TriageRequest $req): TriageResult
    {
        $this->steps[] = [
            'step' => 'triage',
            'model' => $req->model,
            'groups' => count($req->groups),
        ];
        $verdicts = [];
        foreach ($req->groups as $g) {
            $verdicts[$g->fingerprint] = 'interesting';
        }

        return new TriageResult($verdicts);
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $this->steps[] = [
            'step' => 'analyze',
            'model' => $req->model,
            'groups' => count($req->groups),
        ];

        return new AnalysisResult([]);
    }

    /** @return list<array{step: string, model: string, groups: int}> */
    public function steps(): array
    {
        return $this->steps;
    }
}
