<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\Analyzer;
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

    public function testTriageIsAllInterestingAndRecordsTheCallCount(): void
    {
        $a = new EstimatingAnalyzer();

        $r = $a->triage(new TriageRequest([$this->group('a'), $this->group('b')], 'ctx', 'm1', 1500));

        self::assertSame(['a' => 'interesting', 'b' => 'interesting'], $r->verdicts);
        self::assertSame([['step' => 'triage', 'model' => 'm1', 'groups' => 2]], $a->steps());
    }

    public function testAnalyzeReturnsNoFindingsAndRecordsTheCallCount(): void
    {
        $a = new EstimatingAnalyzer();

        $r = $a->analyze(new AnalysisRequest([$this->group('a')], '', 'm2', 2000));

        self::assertSame([], $r->findings);
        self::assertSame([['step' => 'analyze', 'model' => 'm2', 'groups' => 1]], $a->steps());
    }

    public function testConstructorTakesNoHttpLayer(): void
    {
        // No client can be injected, so no network call is possible by construction.
        self::assertSame(0, (new \ReflectionClass(EstimatingAnalyzer::class))->getConstructor()?->getNumberOfParameters() ?? 0);
        self::assertSame([], (new EstimatingAnalyzer())->steps());
        self::assertInstanceOf(Analyzer::class, new EstimatingAnalyzer());
    }
}
