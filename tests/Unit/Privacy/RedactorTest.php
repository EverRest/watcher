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
    /**
     * @param array<string, string> $extra
     * @param array<string, string> $roots container root => repository path
     */
    private function redactor(array $extra = [], array $roots = []): Redactor
    {
        return new Redactor(new Normalizer(), $extra, $roots);
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
        self::assertSame(['app/Foo.php:12'], $r->frames);
        self::assertNotFalse(json_encode($r));
    }

    /** @return array<string, array{string}> */
    public static function notClassNames(): array
    {
        return [
            'free text' => ['declined for Olena Shevchenko'],
            'colon' => ['Error: boom'],
            'quote' => ["Foo'Exception"],
            'newline' => ["Foo\nBar"],
            'leading digit' => ['1Foo'],
            'trailing backslash' => ['App\\'],
            'too long' => [str_repeat('A', 121)],
        ];
    }

    /** @dataProvider notClassNames */
    public function testErrorTypeThatIsNotAClassNameIsDropped(string $type): void
    {
        self::assertNull($this->redactor()->redact($this->group('t', $type))->errorType);
    }

    public function testClassNameShapedTypesStay(): void
    {
        $max = str_repeat('Ab\\', 39) . 'Ab';
        foreach (['App\\Billing\\CardDeclinedException', 'Http2Exception', '_Foo', $max] as $ok) {
            self::assertSame($ok, $this->redactor()->redact($this->group('t', $ok))->errorType);
        }
    }

    public function testErrorTypeWithPersonalDataIsScanned(): void
    {
        $r = $this->redactor()->redact($this->group('t', 'Failure for anna@example.com'));

        self::assertNull($r->errorType);
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

        self::assertSame(['app/Ok.php:7'], $r->frames);
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

    public function testRedactFindingScrubsTheAdviceFields(): void
    {
        $f = new Finding(['fp1'], FindingKind::Bug, Severity::Error, 't', 'e', 'c', 'a', 0.8, false, 'ask anna@example.com via 10.0.0.1', ["mail bob@example.com\xFF", 'fp1 at 10.0.0.2']);
        $out = $this->redactor()->redactFinding($f);

        self::assertSame('ask <EMAIL> via <IP>', $out->howToCheck);
        self::assertSame(['[withheld]', 'fp1 at <IP>'], $out->relatedChecks);
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
        self::assertSame(['…/app/v<N>/Http2Handler.php:12'], $ok->frames);

        // A numeric path segment (a tenant or customer id) is cut away or masked, never sent.
        $bad = $mk('X', ['/var/www/tenants/48213/app/X.php:12', '/srv/1234567890/y.php:3', '/var/www/html/app/Z.php:7']);
        self::assertSame(['…/app/X.php:12', '…/app/Z.php:7'], $bad->frames);

        // A purely numeric error type is not a class name: dropped.
        self::assertNull($mk('1234567890', [])->errorType);
    }

    /** @return array<string, array{string, ?string}> */
    public static function rootlessFrames(): array
    {
        return [
            'username under /home' => ['/home/jsmith/app/X.php:12', '…/app/X.php:12'],
            'username under /Users' => ['/Users/jsmith/Sites/acme/src/Foo/Bar.php:7', '…/src/Foo/Bar.php:7'],
            'customer dir under /var/www' => ['/var/www/acme-corp/app/Http/Kernel.php:5', '…/app/Http/Kernel.php:5'],
            'vendor path keeps last three segments' => ['/var/www/acme-corp/vendor/laravel/framework/src/Illuminate/Foo.php:30', '…/src/Illuminate/Foo.php:30'],
            'relative vendor path' => ['vendor/guzzlehttp/Client.php:9', 'vendor/guzzlehttp/Client.php:9'],
            'windows path' => ['C:\\Users\\jsmith\\app\\Foo.php:12', '…/app/Foo.php:12'],
            'no known directory' => ['/home/jsmith/proj/Foo.php:3', null],
            'file straight under a user dir' => ['/Users/x/Foo.php:1', null],
            'windows without known directory' => ['C:\\Users\\jsmith\\Foo.php:3', null],
            'random segment' => ['/var/www/app/Qx7Zk9Lm2Pw4Rt8Vb3Nc6Hd/Foo.php:3', null],
            'dot-dot segment' => ['/var/www/app/../../etc/Foo.php:3', null],
            'digits inside a directory name' => ['/var/www/app/customer_48213/Foo.php:3', '…/app/customer_<N>/Foo.php:3'],
        ];
    }

    /** @dataProvider rootlessFrames */
    public function testFramePathsWithoutPathMapKeepOnlyAKnownDirectoryAndTheTail(string $in, ?string $out): void
    {
        $r = $this->redactor()->redact($this->group('t', 'E', [$in]));

        self::assertSame($out === null ? [] : [$out], $r->frames);
        foreach (['jsmith', 'acme', 'Qx7Zk9'] as $leak) {
            self::assertStringNotContainsString($leak, implode('', $r->frames));
        }
    }

    public function testFramePathsWithPathMapKeepOnlyThePartAfterAConfiguredRoot(): void
    {
        $r = $this->redactor([], ['/var/www/html/' => '', '/srv/app' => 'lib/'])->redact($this->group('t', 'E', [
            '/var/www/html/app/Foo.php:12',
            '/srv/app/src/A.php:3',
            '/var/www/html/tenants/48213/X.php:4',
            '/home/jsmith/app/X.php:12',
            '/var/www/htmlx/app/Foo.php:12',
            '/var/www/html/../../etc/x.php:1',
            '/var/www/html/app/Qx7Zk9Lm2Pw4Rt8Vb3Nc6Hd/y.php:2',
            'app/Foo.php:9',
        ]));

        self::assertSame(['app/Foo.php:12', 'lib/src/A.php:3', 'tenants/<N>/X.php:4'], $r->frames);
    }

    public function testLongestConfiguredRootWins(): void
    {
        $r = $this->redactor([], ['/var/' => 'wrong/', '/var/www/acme/' => 'repo/'])->redact($this->group('t', 'E', ['/var/www/acme/app/Foo.php:12']));

        self::assertSame(['repo/app/Foo.php:12'], $r->frames);
    }

    /** @return array<string, array{string, string}> */
    public static function identifierDigits(): array
    {
        return [
            'well known token kept' => ['Http2ConnectionTimeoutException', 'Http2ConnectionTimeoutException'],
            'namespaced well known tokens' => ['App\\S3\\Utf8Md5Sha256Exception', 'App\\S3\\Utf8Md5Sha256Exception'],
            'tenant id after underscore' => ['Tenant_8812Exception', 'Tenant_<N>Exception'],
            'customer id glued to letters' => ['Customer48213Exception', 'Customer<N>Exception'],
            'lower snake' => ['customer_48213', 'customer_<N>'],
            'token must be a whole word' => ['Ipv48213Exception', 'Ipv<N>Exception'],
            'x86_64' => ['X86_64Exception', 'X86_64Exception'],
        ];
    }

    /** @dataProvider identifierDigits */
    public function testDigitsInsideIdentifiersAreMaskedUnlessWellKnown(string $in, string $out): void
    {
        self::assertSame($out, $this->redactor()->redact($this->group('t', $in))->errorType);
    }

    public function testExtraPatternsMatchDigitsInGroupFieldsAndModelOutput(): void
    {
        $r = $this->redactor(['cust' => '/CUST-\d{6}/']);

        self::assertSame('Invoice <CUST> failed', $r->redact($this->group('Invoice CUST-123456 failed'))->template);
        self::assertSame('Invoice <CUST> failed 42', $r->redactText('Invoice CUST-123456 failed 42'));
        self::assertSame('Invoice <CUST> failed <N>', $r->redactText('Invoice CUST-123456 failed 42', false));
        $f = new Finding(['fp1'], FindingKind::Bug, Severity::Error, 'CUST-123456 down', 'seen CUST-654321', 'cause CUST-111111', 'ask CUST-222222', 0.5, false);
        $out = $r->redactFinding($f);
        self::assertSame(['<CUST> down', 'seen <CUST>', 'cause <CUST>', 'ask <CUST>'], [$out->title, $out->evidence, $out->suspectedCause, $out->suggestedAction]);
    }

    public function testAdversarialFramePathsStayLinear(): void
    {
        $r = $this->redactor(['cust' => '/CUST-\d{6}/'], ['/var/www/html/' => '']);
        $start = microtime(true);
        $r->redact($this->group('t', 'E', [
            '/var/www/html/' . str_repeat('a/', 2000) . 'X.php:1',
            str_repeat('app/', 1000) . 'X.php:1',
            '/' . str_repeat('a', 4000) . '.php:1',
            str_repeat('0123456789', 400) . ':1',
        ]));
        $r->redactText(str_repeat('CUST-12345', 400));

        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function testExtraPlaceholderWithADigitInItsNameSurvivesTheSecondScan(): void
    {
        $r = $this->redactor(['cust2' => '/CUST-\d{6}/']);

        self::assertSame('Invoice <CUST2> failed', $r->redact($this->group('Invoice <CUST2> failed'))->template);
    }

    public function testExtraPatternNamesCannotImpersonateBuiltInPlaceholders(): void
    {
        foreach (['N', 'n', 'EMAIL', 'URL', 'HOST', 'IP', 'ID', 'TOKEN', 'PAN', 'STR', 'PATH', 'USER', 'path'] as $name) {
            try {
                $this->redactor([$name => '/x/']);
                self::fail("pattern name '$name' should be rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }
}
