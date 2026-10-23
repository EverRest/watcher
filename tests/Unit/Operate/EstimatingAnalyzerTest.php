<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\Budget;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\TriageRequest;
use Watcher\Level;
use Watcher\Operate\EstimatingAnalyzer;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class EstimatingAnalyzerTest extends TestCase
{
    private function group(string $fp): RedactedGroup
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new RedactedGroup($fp, "tpl $fp", Level::Error, null, [], 3, null, GroupStatusLabel::New, [], $t, $t);
    }

    public function testTriageIsAllInterestingZeroUsageAndRecordsEstimate(): void
    {
        $a = new EstimatingAnalyzer();
        $groups = [$this->group('a'), $this->group('b')];

        $r = $a->triage(new TriageRequest($groups, 'ctx', 'm1', 1500));

        self::assertInstanceOf(Analyzer::class, $a);
        self::assertSame(['a' => 'interesting', 'b' => 'interesting'], $r->verdicts);
        self::assertSame(0, $r->usage->inputTokens + $r->usage->outputTokens);
        self::assertSame(0.0, $r->usage->costUsd);
        self::assertSame('m1', $r->usage->model);
        self::assertSame(
            [['step' => 'triage', 'model' => 'm1', 'groups' => 2, 'tokensIn' => Budget::estimateTokens(CompactPayload::encode($groups) . 'ctx'), 'maxTokensOut' => 1500]],
            $a->steps(),
        );
    }

    public function testAnalyzeReturnsEmptyZeroUsageAndRecords(): void
    {
        $a = new EstimatingAnalyzer();
        $groups = [$this->group('a')];

        $r = $a->analyze(new AnalysisRequest($groups, '', 'm2', 2000));

        self::assertSame([], $r->findings);
        self::assertSame(0.0, $r->usage->costUsd);
        self::assertSame('analyze', $a->steps()[0]['step']);
        self::assertSame(1, $a->steps()[0]['groups']);
        self::assertSame(2000, $a->steps()[0]['maxTokensOut']);
    }

    public function testConstructorTakesNoHttpLayer(): void
    {
        // No client can be injected, so no network call is possible by construction.
        self::assertSame(0, (new \ReflectionClass(EstimatingAnalyzer::class))->getConstructor()?->getNumberOfParameters() ?? 0);
        self::assertSame([], (new EstimatingAnalyzer())->steps());
    }
}
