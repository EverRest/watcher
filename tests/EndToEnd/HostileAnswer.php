<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;

/**
 * A model that answers every group with the same text as its title, evidence, cause and fix, with the verdict, severity,
 * kind and token counts the test asks for. The text is what a hostile log line makes the model write (security review F8).
 */
final class HostileAnswer implements Analyzer
{
    /** @var list<Usage> what each call was billed for, in order */
    public array $billed = [];

    /** @var list<int> the number of groups in each triage request */
    public array $triageGroups = [];

    public function __construct(
        private readonly string $answer,
        private readonly string $verdict = 'interesting',
        private readonly Severity $severity = Severity::Error,
        private readonly FindingKind $kind = FindingKind::Bug,
        private readonly float $confidence = 0.9,
        private readonly int $inputTokens = 10,
        private readonly int $outputTokens = 1,
    ) {
    }

    public function triage(TriageRequest $req): TriageResult
    {
        $verdicts = array_fill_keys(array_map(static fn ($g): string => $g->fingerprint, $req->groups), $this->verdict);

        $this->triageGroups[] = count($req->groups);
        $this->billed[] = new Usage($req->model, $this->inputTokens, $this->outputTokens);

        return new TriageResult($verdicts, end($this->billed));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $findings = array_map(
            fn ($g): Finding => new Finding([$g->fingerprint], $this->kind, $this->severity, $this->answer, $this->answer, $this->answer, $this->answer, $this->confidence),
            $req->groups,
        );

        $this->billed[] = new Usage($req->model, $this->inputTokens, $this->outputTokens);

        return new AnalysisResult($findings, end($this->billed));
    }
}
