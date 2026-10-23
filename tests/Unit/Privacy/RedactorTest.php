<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Privacy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Level;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;

final class RedactorTest extends TestCase
{
    /** @param array<string, string> $extra */
    private function redactor(array $extra = []): Redactor
    {
        return new Redactor(new Normalizer(), $extra);
    }

    /** @param list<string> $frames */
    private function group(?string $template, ?string $type = 'RuntimeException', array $frames = ['/app/Foo.php:12']): ProblemGroup
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new ProblemGroup('fp1', $template, Level::Error, $type, $frames, 9, $t, $t);
    }

    public function testLeftoversGetTypedPlaceholders(): void
    {
        $r = $this->redactor()->redact($this->group(
            'Mail to anna@example.com jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln from 10.1.2.3 via db.internal.example.com failed',
        ));

        self::assertSame('Mail to <EMAIL> jwt <TOKEN> from <IP> via <HOST> failed', $r->template);
        self::assertSame('fp1', $r->fingerprint);
        self::assertSame(9, $r->count);
    }

    public function testAlreadyNormalisedTemplateIsStable(): void
    {
        $tpl = 'Lock wait <N> for <EMAIL> at <HOST:db> code <*>';
        self::assertSame($tpl, $this->redactor()->redact($this->group($tpl))->template);
    }

    public function testNullTemplateStaysNull(): void
    {
        self::assertNull($this->redactor()->redact($this->group(null))->template);
    }

    public function testExtraPatternsBecomeUppercasedPlaceholders(): void
    {
        $r = $this->redactor(['customer' => '/CUST-[A-Z0-9]{6}/'])->redact($this->group('Invoice for CUST-AB12CD failed'));

        self::assertSame('Invoice for <CUSTOMER> failed', $r->template);
    }

    public function testInvalidExtraPatternDropsTextFieldsOnly(): void
    {
        $r = $this->redactor(['bad' => '/(unclosed/'])->redact($this->group('Failed for CUST-QWERTY', 'RuntimeException', ['/app/Foo.php:12']));

        self::assertNull($r->template);
        self::assertNull($r->errorType);
        self::assertSame([], $r->frames);
        self::assertSame('fp1', $r->fingerprint);
        self::assertSame(9, $r->count);
        self::assertStringNotContainsString('CUST-QWERTY', (string) json_encode($r));
    }

    public function testNonUtf8TemplateIsDroppedOthersIntact(): void
    {
        $r = $this->redactor()->redact($this->group("bad \xFF anna@example.com", 'RuntimeException'));

        self::assertNull($r->template);
        self::assertSame('RuntimeException', $r->errorType);
        self::assertSame(['/app/Foo.php:12'], $r->frames);
        self::assertNotFalse(json_encode($r));
    }

    public function testErrorTypeWithPersonalDataIsScanned(): void
    {
        $r = $this->redactor()->redact($this->group('t', 'Failure for anna@example.com'));

        self::assertSame('Failure for <EMAIL>', $r->errorType);
        self::assertSame('App\\Http2Exception', $this->redactor()->redact($this->group('t', 'App\\Http2Exception'))->errorType);
    }

    public function testFramesWithArgumentsOrPersonalDataAreDropped(): void
    {
        $r = $this->redactor()->redact($this->group('t', 'E', [
            '/app/Ok.php:7',
            "/app/Account.php(41): find('anna@example.com')",
            '/home/anna.kowalski/app.php:3',
            '/app/anna@example.com.php:3',
            "/app/Bad\xFF.php:3",
        ]));

        self::assertSame(['/app/Ok.php:7'], $r->frames);
    }

    public function testRedactTextScansAndFailsClosed(): void
    {
        $x = $this->redactor(['acct' => '/ACC-[A-Z]{6}/']);

        self::assertSame('Mail <EMAIL> acct <ACCT>', $x->redactText('Mail a@b.example acct ACC-QWERTY'));
        self::assertNull($x->redactText("bad \xFF"));
        self::assertNull($this->redactor(['bad' => '/(/'])->redactText('anything'));
    }

    public function testRedactFindingScrubsEveryTextFieldAndWithholdsOnFailure(): void
    {
        $f = new Finding(['fp1'], FindingKind::Bug, Severity::Error, 'Mail to anna@example.com', 'Bearer abcdef0123456789abcdef', 'cause 10.0.0.1', "bad \xFF", 0.8, true);
        $out = $this->redactor()->redactFinding($f);

        self::assertSame('Mail to <EMAIL>', $out->title);
        self::assertStringNotContainsString('abcdef0123456789abcdef', $out->evidence);
        self::assertSame('cause <IP>', $out->suspectedCause);
        self::assertSame('[withheld]', $out->suggestedAction);
        self::assertSame(['fp1'], $out->fingerprints);
        self::assertSame(0.8, $out->confidence);
        self::assertTrue($out->escalated);
    }

    public function testModelTextKeepsPlainNumbersButStillMasksPersonalPatterns(): void
    {
        $r = $this->redactor();

        self::assertSame('412 errors for <EMAIL> since 06:00', $r->redactText('412 errors for a@b.com since 06:00'));
        self::assertSame('<TOKEN>', $r->redactText('eyJhbGciOi.eyJzdWIiOi.SflKxwRJSM'));
    }

    public function testDigitsSurviveOnlyInsideIdentifiers(): void
    {
        $r = $this->redactor();
        $mk = fn (?string $type, array $frames): RedactedGroup => $r->redact($this->group('Lock wait', $type, $frames));

        // Digits attached to letters are part of a name: kept.
        $ok = $mk('App\\Http2Exception', ['/var/www/html/app/v2/Http2Handler.php:12']);
        self::assertSame('App\\Http2Exception', $ok->errorType);
        self::assertSame(['/var/www/html/app/v2/Http2Handler.php:12'], $ok->frames);

        // A purely numeric path segment (a tenant or customer id) is personal-data-shaped: the frame is dropped.
        $bad = $mk('X', ['/var/www/tenants/48213/app/X.php:12', '/srv/1234567890/y.php:3', '/var/www/html/app/Z.php:7']);
        self::assertSame(['/var/www/html/app/Z.php:7'], $bad->frames);

        // A purely numeric error type is masked, not kept.
        self::assertSame('<N>', $mk('1234567890', [])->errorType);
    }

    public function testExtraPatternNamesCannotImpersonateBuiltInPlaceholders(): void
    {
        foreach (['N', 'n', 'EMAIL', 'URL', 'HOST', 'IP', 'ID', 'TOKEN', 'PAN', 'STR'] as $name) {
            try {
                $this->redactor([$name => '/x/']);
                self::fail("pattern name '$name' should be rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }
}
