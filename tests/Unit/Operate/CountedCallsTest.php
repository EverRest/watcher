<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Operate\RunStats;
use Watcher\Watcher;

/** `calls` keeps the 0.4 meaning: every model request made counts, including one that was billed and then failed. */
final class CountedCallsTest extends TestCase
{
    public function testFailedAnalyzerCallIsRecordedAndRethrown(): void
    {
        $inner = new class () implements Analyzer {
            public int $attempts = 0;

            public function triage(TriageRequest $req): TriageResult
            {
                return new TriageResult([]);
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                return ++$this->attempts === 1 ? throw new AnalyzerFailed('reply was not valid JSON') : new AnalysisResult([]);
            }
        };
        $stats = new RunStats();
        $counted = (new ReflectionMethod(Watcher::class, 'counted'))->invoke(null, $inner, $stats, ['analyze' => 'm1']);
        $req = new AnalysisRequest([], 'svc', 'm1', 100);

        try {
            $counted->analyze($req);
            self::fail('the failure must reach the caller');
        } catch (AnalyzerFailed) {
            // expected: the failure is rethrown after it is counted
        }
        $counted->analyze($req);

        self::assertSame(2, $inner->attempts);
        self::assertSame(2, $stats->calls['analyze']);
    }
}
