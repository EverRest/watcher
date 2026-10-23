<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\InvalidModelOutput;
use Watcher\Analyze\OutputValidator;
use Watcher\Analyze\Severity;

final class OutputValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function finding(array $over = []): array
    {
        return array_merge([
            'fingerprints' => ['fp1'], 'kind' => 'bug', 'severity' => 'error', 'title' => 'T',
            'evidence' => 'E', 'suspected_cause' => 'C', 'suggested_action' => 'A', 'confidence' => 0.8,
        ], $over);
    }

    public function testValidAnalysis(): void
    {
        $f = (new OutputValidator())->analysis(['findings' => [self::finding()]], ['fp1', 'fp2']);
        self::assertCount(1, $f);
        self::assertSame(FindingKind::Bug, $f[0]->kind);
        self::assertSame(Severity::Error, $f[0]->severity);
        self::assertSame('C', $f[0]->suspectedCause);
        self::assertFalse($f[0]->escalated);
    }

    public function testIntegerConfidenceAccepted(): void
    {
        $f = (new OutputValidator())->analysis(['findings' => [self::finding(['confidence' => 1])]], ['fp1']);
        self::assertSame(1.0, $f[0]->confidence);
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function badFindings(): array
    {
        $missing = self::finding();
        unset($missing['evidence']);

        return [
            'unknown fingerprint' => [self::finding(['fingerprints' => ['nope']])],
            'one unknown among known' => [self::finding(['fingerprints' => ['fp1', 'nope']])],
            'empty fingerprints' => [self::finding(['fingerprints' => []])],
            'bad kind' => [self::finding(['kind' => 'weird'])],
            'bad severity' => [self::finding(['severity' => 'fatal'])],
            'missing field' => [$missing],
            'extra field' => [self::finding(['extra' => 'x'])],
            'wrong type title' => [self::finding(['title' => 5])],
            'confidence high' => [self::finding(['confidence' => 1.1])],
            'confidence low' => [self::finding(['confidence' => -0.1])],
            'confidence string' => [self::finding(['confidence' => '0.5'])],
            'fingerprints not list' => [self::finding(['fingerprints' => 'fp1'])],
        ];
    }

    /** @param array<string,mixed> $finding */
    #[DataProvider('badFindings')]
    public function testRejects(array $finding): void
    {
        $this->expectException(InvalidModelOutput::class);
        (new OutputValidator())->analysis(['findings' => [$finding]], ['fp1']);
    }

    public function testRejectsExtraAndMissingTopLevel(): void
    {
        $v = new OutputValidator();
        foreach ([['findings' => [], 'x' => 1], [], ['findings' => 'no']] as $bad) {
            try {
                $v->analysis($bad, ['fp1']);
                self::fail('expected InvalidModelOutput');
            } catch (InvalidModelOutput) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTruncatesWithEllipsis(): void
    {
        $f = (new OutputValidator())->analysis(['findings' => [self::finding([
            'title' => str_repeat('a', 81), 'evidence' => str_repeat('b', 241),
            'suspected_cause' => str_repeat('c', 241), 'suggested_action' => str_repeat('d', 201),
        ])]], ['fp1'])[0];

        self::assertSame(80, mb_strlen($f->title));
        self::assertSame(240, mb_strlen($f->evidence));
        self::assertSame(240, mb_strlen($f->suspectedCause));
        self::assertSame(200, mb_strlen($f->suggestedAction));
        foreach ([$f->title, $f->evidence, $f->suspectedCause, $f->suggestedAction] as $s) {
            self::assertStringEndsWith('…', $s);
        }
    }

    public function testAtLimitIsUntouched(): void
    {
        $f = (new OutputValidator())->analysis(['findings' => [self::finding(['title' => str_repeat('é', 80)])]], ['fp1'])[0];
        self::assertSame(str_repeat('é', 80), $f->title);
    }

    public function testTriage(): void
    {
        $r = (new OutputValidator())->triage(
            ['verdicts' => [['g' => 'fp1', 'v' => 'noise'], ['g' => 'fp2', 'v' => 'interesting']]],
            ['fp1', 'fp2'],
        );
        self::assertSame(['fp1' => 'noise', 'fp2' => 'interesting'], $r['verdicts']);
        self::assertSame(['verdicts'], array_keys($r));
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function badVerdicts(): array
    {
        return [
            'unknown fingerprint' => [['g' => 'zzz', 'v' => 'noise']],
            'bad verdict' => [['g' => 'fp1', 'v' => 'meh']],
            'missing v' => [['g' => 'fp1']],
            'extra' => [['g' => 'fp1', 'v' => 'noise', 'x' => 1]],
            'why is no longer part of the schema' => [['g' => 'fp1', 'v' => 'noise', 'why' => 'x']],
        ];
    }

    /** @param array<string,mixed> $verdict */
    #[DataProvider('badVerdicts')]
    public function testTriageRejects(array $verdict): void
    {
        $this->expectException(InvalidModelOutput::class);
        (new OutputValidator())->triage(['verdicts' => [$verdict]], ['fp1']);
    }

    public function testMessageNeverEchoesLongModelText(): void
    {
        try {
            (new OutputValidator())->analysis(['findings' => [self::finding(['kind' => str_repeat('SECRET', 100)])]], ['fp1']);
            self::fail('expected');
        } catch (InvalidModelOutput $e) {
            self::assertLessThan(200, strlen($e->getMessage()));
        }
    }
}
