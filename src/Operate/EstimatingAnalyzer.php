<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\Budget;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;

/**
 * `--estimate`: no model call (it holds no HTTP client). Every group is "interesting" so the router reaches
 * `analyze()` too; each step records what would have been sent. The analyze figure is therefore an upper bound.
 */
final class EstimatingAnalyzer implements Analyzer
{
    /** @var list<array{step: string, model: string, groups: int, tokensIn: int, maxTokensOut: int}> */
    private array $steps = [];

    public function triage(TriageRequest $req): TriageResult
    {
        $this->steps[] = [
            'step' => 'triage',
            'model' => $req->model,
            'groups' => count($req->groups),
            'tokensIn' => Budget::estimateTokens(CompactPayload::encode($req->groups, [], $req->extras) . $req->serviceContext),
            'maxTokensOut' => $req->maxOutputTokens,
        ];
        $verdicts = [];
        foreach ($req->groups as $g) {
            $verdicts[$g->fingerprint] = 'interesting';
        }

        return new TriageResult($verdicts, new Usage($req->model, 0, 0));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $this->steps[] = [
            'step' => 'analyze',
            'model' => $req->model,
            'groups' => count($req->groups),
            'tokensIn' => Budget::estimateTokens(CompactPayload::encode($req->groups, $req->dependencyContext, $req->extras) . $req->serviceContext),
            'maxTokensOut' => $req->maxOutputTokens,
        ];

        return new AnalysisResult([], new Usage($req->model, 0, 0));
    }

    /** @return list<array{step: string, model: string, groups: int, tokensIn: int, maxTokensOut: int}> */
    public function steps(): array
    {
        return $this->steps;
    }
}
