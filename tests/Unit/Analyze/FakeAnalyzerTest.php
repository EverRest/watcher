<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\Tests\Fakes\FakeAnalyzer;

final class FakeAnalyzerTest extends TestCase
{
    private function group(): RedactedGroup
    {
        return new RedactedGroup(
            'fp1',
            'tpl',
            Level::Error,
            null,
            [],
            1,
            null,
            GroupStatusLabel::New,
            [],
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );
    }

    private function finding(): Finding
    {
        return new Finding(['fp1'], FindingKind::Bug, Severity::Error, 't', 'e', 'c', 'a', 0.5);
    }

    public function testRecordsRequestsVerbatimAndCounts(): void
    {
        $fake = new FakeAnalyzer(new TriageResult(['fp1' => 'noise']), new AnalysisResult([$this->finding()]));
        $fake->triage(new TriageRequest([$this->group()], 'svc', 'm', 100));
        $fake->analyze(new AnalysisRequest([$this->group()], 'svc', 'm', 100));

        self::assertSame(1, $fake->triageCalls);
        self::assertSame(1, $fake->analyzeCalls);
        self::assertCount(2, $fake->requests);
        self::assertStringContainsString('"fp":"fp1"', (string) json_encode($fake->requests));
    }

    public function testFailureIsThrownButStillRecorded(): void
    {
        $fake = new FakeAnalyzer(failure: new AnalyzerFailed('boom'));
        try {
            $fake->triage(new TriageRequest([$this->group()], 'svc', 'm', 100));
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertCount(1, $fake->requests);
    }

    public function testFindingValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Finding([], FindingKind::Bug, Severity::Error, 't', 'e', 'c', 'a', 0.5);
    }

    public function testFindingConfidenceRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Finding(['x'], FindingKind::Bug, Severity::Error, 't', 'e', 'c', 'a', 1.5);
    }

    public function testFindingEscalatedDefaultsFalse(): void
    {
        self::assertFalse($this->finding()->escalated);
    }

    public function testTriageResultRejectsBadVerdict(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TriageResult(['fp1' => 'maybe']);
    }

}
