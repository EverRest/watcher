<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\SiteId;
use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LogEvent;

final class SiteIdTest extends TestCase
{
    /**
     * @param array<string, string> $pathMap the Redactor's repo.path_map
     * @param list<string>|null $roots the roots a site may sit below (default: the roots the fixtures use; [] is "no repo.path_map")
     * @param list<string> $allow hygiene.site_allow_dirs
     */
    private static function ids(array $pathMap = [], ?array $roots = null, array $allow = ['auth', 'audit', 'orders']): SiteId
    {
        return new SiteId(new Redactor(new Normalizer(), [], $pathMap), $roots ?? ['/var/www/html', '/srv', '/a', '/home'], $allow);
    }

    /**
     * @param array<string, scalar|null> $fields
     * @param list<string> $frames
     */
    private static function event(array $fields = [], array $frames = [], Level $level = Level::Info): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), $level, 'msg', null, $frames, $fields, []);
    }

    public function testFileAndLineFromTheContextNameTheSite(): void
    {
        $site = self::ids()->of(self::event(['file' => '/var/www/html/app/Auth/AuthService.php', 'line' => 118]), 'fp1', 'shape');

        self::assertSame('frame', $site->kind);
        self::assertSame('…/app/Auth/AuthService.php:118', $site->id);
    }

    public function testTheFirstFrameIsUsedWithoutContextKeys(): void
    {
        $site = self::ids()->of(self::event([], ['/var/www/html/app/Mail/MailService.php:64']), 'fp1', 'shape');

        self::assertSame(['frame', '…/app/Mail/MailService.php:64'], [$site->kind, $site->id]);
    }

    public function testTheRepoPathMapIsHonoured(): void
    {
        $site = self::ids(['/var/www/html' => ''])->of(self::event(['file' => '/var/www/html/app/A.php', 'line' => '7']), 'fp1', 'shape');

        self::assertSame('app/A.php:7', $site->id);
    }

    public function testWithoutAFrameTheFingerprintStandsInForTheTemplate(): void
    {
        $site = self::ids()->of(self::event(), 'a1b2c3d4e5f6', 'shape');

        self::assertSame(['template', self::tpl('shape')], [$site->kind, $site->id]);
    }

    public function testAPathTheRedactorDropsFallsThroughToTheTemplate(): void
    {
        $site = self::ids()->of(self::event(['file' => '/home/anna.kowalski@example.org/app/x.php', 'line' => 3]), 'a1b2c3d4e5f6', 'shape');

        self::assertSame(['template', self::tpl('shape')], [$site->kind, $site->id]);
        self::assertStringNotContainsString('kowalski', $site->id);
    }

    public function testAFileWithoutALineIsNotASite(): void
    {
        $site = self::ids()->of(self::event(['file' => '/var/www/html/app/A.php']), 'a1b2c3d4e5f6', 'shape');

        self::assertSame('template', $site->kind);
    }

    public function testDigitsInThePathAreMasked(): void
    {
        $site = self::ids()->of(self::event(['file' => '/srv/app/Tenant8812/Job.php', 'line' => 9]), 'fp1', 'shape');

        self::assertStringNotContainsString('8812', $site->id);
    }

    public function testAMovedLineKeepsItsKey(): void
    {
        $a = self::ids()->of(self::event(['file' => '/var/www/html/app/A.php', 'line' => 10]), 'fp-a', 'shape');
        $b = self::ids()->of(self::event(['file' => '/var/www/html/app/A.php', 'line' => 42]), 'fp-b', 'shape');

        self::assertNotSame($a->id, $b->id);
        self::assertSame($a->key, $b->key);
    }

    public function testTwoMessageShapesAtOneLineAreTwoSitesAndTwoFilesAreTwoSites(): void
    {
        $e = self::event(['file' => '/var/www/html/app/A.php', 'line' => 10]);
        $other = self::event(['file' => '/var/www/html/app/B.php', 'line' => 10]);

        self::assertNotSame(self::ids()->of($e, 'f', 'shape-1')->key, self::ids()->of($e, 'f', 'shape-2')->key);
        self::assertNotSame(self::ids()->of($e, 'f', 'shape-1')->key, self::ids()->of($other, 'f', 'shape-1')->key);
    }

    private static function tpl(string $shape): string
    {
        return 'tpl:' . substr(hash('sha256', 'tpl|' . $shape), 0, 12);
    }

    public function testTemplateSitesAreKeyedByTheDeployStableShapeAndKeysAreSixteenHex(): void
    {
        $a = self::ids()->of(self::event(), 'fp-a', 'shape-1');
        $b = self::ids()->of(self::event(), 'fp-b', 'shape-2');
        $same = self::ids()->of(self::event(), 'fp-c', 'shape-1');

        self::assertNotSame($a->key, $b->key);
        self::assertSame($a->key, $same->key, 'the run fingerprint (frame line, token count) is not part of it');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a->key);
    }

    public function testShapeKeyIgnoresTheFrame(): void
    {
        $tokens = ['Receipt', 'sent', 'to', '<EMAIL>'];
        $a = new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), Level::Info, 'm', null, ['/a/app/A.php:1'], [], []);
        $b = new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), Level::Info, 'm', null, ['/a/app/A.php:9'], [], []);
        $c = new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), Level::Error, 'm', null, [], [], []);

        self::assertSame(SiteId::shapeKey($a, $tokens), SiteId::shapeKey($b, $tokens));
        self::assertNotSame(SiteId::shapeKey($a, $tokens), SiteId::shapeKey($c, $tokens));
        self::assertNotSame(SiteId::shapeKey($a, $tokens), SiteId::shapeKey($a, ['Other', 'text']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function notCode(): iterable
    {
        yield 'upload with a name' => ['/var/www/html/storage/uploads/john_smith_passport.pdf', '/var/www/html/'];
        yield 'document under the code tree' => ['/var/www/html/app/Customers/MaryJane.pdf', '/var/www/html/'];
        yield 'csv import' => ['/var/www/html/app/imports/clients_kowalski.csv', '/var/www/html/'];
        yield 'no extension' => ['/var/www/html/app/Customers/Mary', '/var/www/html/'];
        yield 'compiled view in storage' => ['/var/www/html/storage/framework/views/abc.php', '/var/www/html/'];
        yield 'person directory (underscore)' => ['/var/www/html/app/customers/ivan_petrenko/profile.php', '/var/www/html/'];
        yield 'person file (hyphens)' => ['/var/www/html/app/Ivan-Petrenko-passport.php', '/var/www/html/'];
        yield 'person with digits' => ['/var/www/html/app/ivan-petrenko-84721/Foo.php', '/var/www/html/'];
        yield '20-hex directory' => ['/var/www/html/app/9f86d081884c7d659a2f/Foo.php', '/var/www/html/'];
        yield 'unknown directory name' => ['/var/www/html/app/Petrenko/Foo.php', '/var/www/html/'];
        yield 'person stem camel' => ['/var/www/html/src/IvanPetrenko.php', '/var/www/html/'];
        yield 'person stem snake' => ['/var/www/html/src/ivan_petrenko.php', '/var/www/html/'];
        yield 'person stem js' => ['/var/www/html/lib/Ivan_Petrenko.js', '/var/www/html/'];
        yield 'person stem py' => ['/var/www/html/core/helpers/Jane_Doe.py', '/var/www/html/'];
        yield 'customer stem' => ['/var/www/html/src/AcmeCorpClient.php', '/var/www/html/'];
        yield 'name with digits stem' => ['/var/www/html/src/Ivan84721.php', '/var/www/html/'];
        yield 'hex stem' => ['/var/www/html/src/a1b2c3d4e5f6a7b8.php', '/var/www/html/'];
        yield 'var dir' => ['/var/www/html/app/var/jane_doe/Plugin.php', '/var/www/html/'];
        yield 'export dir' => ['/var/www/html/app/export/Jane_Doe.php', '/var/www/html/'];
        yield 'downloads dir' => ['/var/www/html/app/downloads/jane-doe-cv.js', '/var/www/html/'];
        yield 'reports and documents dirs' => ['/var/www/html/app/reports/documents/Ivan-Petrenko.php', '/var/www/html/'];
        yield 'tmp dir' => ['/var/www/html/app/tmp/Job.php', '/var/www/html/'];
        yield 'uploads dir, no map' => ['/srv/src/app/uploads/Mary.php', ''];
        yield 'doc, no map' => ['/srv/src/app/Customers/MaryJane.docx', ''];
    }

    public function testAFileStemMadeOfCodeWordsOrNamedByTheOperatorIsASite(): void
    {
        self::assertSame('frame', self::ids()->of(self::event(['file' => '/var/www/html/app/Http/OrderController.php', 'line' => 3]), 'fp', 'shape')->kind);
        self::assertSame('frame', self::ids()->of(self::event(['file' => '/var/www/html/app/Http/mail_service.php', 'line' => 3]), 'fp', 'shape')->kind);
        self::assertSame('template', self::ids()->of(self::event(['file' => '/var/www/html/src/AcmeCorpClient.php', 'line' => 3]), 'fp', 'shape')->kind);
        self::assertSame('frame', self::ids(allow: ['acme', 'corp'])->of(self::event(['file' => '/var/www/html/src/AcmeCorpClient.php', 'line' => 3]), 'fp', 'shape')->kind, 'the operator vetted the words');
    }

    public function testOnlyDirectoriesOfAConservativeCodeVocabularyOrTheAllowListAreEverSiteIdsAndTheRestFallBackToTheFingerprint(): void
    {
        self::assertSame('frame', self::ids()->of(self::event(['file' => '/var/www/html/app/Http/Controllers/OrderController.php', 'line' => 3]), 'fp', 'shape')->kind);
        self::assertSame('template', self::ids()->of(self::event(['file' => '/var/www/html/src/Domain/Billing/Invoice.php', 'line' => 3]), 'fp', 'shape')->kind, 'Billing is not code vocabulary');
        self::assertSame('frame', self::ids(allow: ['billing'])->of(self::event(['file' => '/var/www/html/src/Domain/Billing/Invoice.php', 'line' => 3]), 'fp', 'shape')->kind, 'the operator named it');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notCode')]
    public function testOnlySourceFilesOfTheCodeTreeCanBeASiteAndNothingElseLeaks(string $file, string $root): void
    {
        $ids = self::ids($root === '' ? [] : [rtrim($root, '/') => 'app/']);

        $site = $ids->of(self::event(['file' => $file, 'line' => 1]), 'a1b2c3d4e5f6', 'shape');

        self::assertSame(['template', self::tpl('shape')], [$site->kind, $site->id]);
    }

    /** @return iterable<string, array{string}> */
    public static function pocPaths(): iterable
    {
        yield 'review B-2: ts in public/files' => ['/var/www/html/app/public/files/john_smith.ts'];
        yield 'review B-2: double extension under data' => ['/var/www/html/app/data/john_smith_passport.pdf.php'];
        yield 'double extension outside a data dir' => ['/var/www/html/app/Customers/john_smith_passport.pdf.php'];
        yield 'dotted stem' => ['/var/www/html/app/Customers/john.smith.js'];
        yield 'dot-dot below the root' => ['/var/www/html/app/../../../etc/Job.php'];
        yield 'dot-dot inside' => ['/var/www/html/app/x/../Job.php'];
        yield 'sibling of the root' => ['/var/www/html2/app/Job.php'];
        yield 'relative path' => ['app/Job.php'];
        yield 'trailing newline' => ["/var/www/html/app/Job.php\n"];
        yield 'files dir' => ['/var/www/html/app/files/Job.php'];
        yield 'dumps dir' => ['/var/www/html/app/dumps/Job.php'];
        yield 'attachments dir' => ['/var/www/html/app/Attachments/Job.php'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pocPaths')]
    public function testPathsOutsideTheCodeAllowlistFallToTheTemplate(string $file): void
    {
        $site = self::ids()->of(self::event(['file' => $file, 'line' => 1]), 'a1b2c3d4e5f6', 'shape');

        self::assertSame(['template', self::tpl('shape')], [$site->kind, $site->id]);
        self::assertStringNotContainsString('john', $site->id);
    }

    public function testAFrameIsHeldToTheSameRules(): void
    {
        $site = self::ids()->of(self::event([], ['/var/www/html/app/data/john_smith_passport.pdf.php:1']), 'fp', 'shape');

        self::assertSame('template', $site->kind);
    }

    public function testWithoutAPathMapNoFileIsASite(): void
    {
        $e = self::event(['file' => '/var/www/html/app/Auth/AuthService.php', 'line' => 3], ['/var/www/html/app/Auth/AuthService.php:3']);

        self::assertSame('template', self::ids(roots: [])->of($e, 'a1b2c3d4e5f6', 'shape')->kind);
    }

    public function testADenyListedDirectoryIsAcceptedWhenTheConfigAllowsIt(): void
    {
        $e = self::event(['file' => '/var/www/html/app/data/Job.php', 'line' => 3]);

        self::assertSame('template', self::ids()->of($e, 'fp', 'shape')->kind);
        self::assertSame('frame', self::ids(allow: ['data'])->of($e, 'fp', 'shape')->kind);
        self::assertSame('template', self::ids(allow: ['data'])->of(self::event(['file' => '/var/www/html/app/uploads/Job.php', 'line' => 3]), 'fp', 'shape')->kind, 'only the named directory');
    }

    public function testTheRootItselfIsNotPartOfTheDenyCheck(): void
    {
        // The root may sit under a directory called storage or data on the host; only the part below it counts.
        $site = self::ids(roots: ['/data/www'])->of(self::event(['file' => '/data/www/app/Job.php', 'line' => 3]), 'fp', 'shape');

        self::assertSame('frame', $site->kind);
    }

    public function testCodeExtensionsAreAcceptedInAnyCase(): void
    {
        // The Redactor's frame rule reads `a.py` or `a.go` as a host name and drops it (known limit, docs/privacy.md).
        foreach (['A.php', 'a.JS', 'a.ts', 'a.rb'] as $name) {
            $site = self::ids()->of(self::event(['file' => "/srv/src/app/Http/$name", 'line' => 3]), 'fp', 'shape');
            self::assertSame('frame', $site->kind, $name);
        }
    }

    public function testAFrameThatIsNotSourceIsDroppedToo(): void
    {
        $site = self::ids()->of(self::event([], ['/srv/src/app/uploads/maria.pdf:3']), 'fp', 'shape');

        self::assertSame('template', $site->kind);
    }

    public function testAFingerprintSiteSurvivesADeployThatMovesLinesOrChangesTheLengthOfTheTail(): void
    {
        $ids = self::ids();
        $a = LogEventFactory::make(['/opt/app/vendor/lib/Thing.php:12'], 'Payment declined for user one');
        $b = LogEventFactory::make(['/opt/app/vendor/lib/Thing.php:13'], 'Payment declined for user one two three four');
        $tokensA = explode(' ', 'Payment declined for user one');
        $tokensB = explode(' ', 'Payment declined for user one two three four');

        $siteA = $ids->of($a, 'fingerprint-a', SiteId::shapeKey($a, $tokensA));
        $siteB = $ids->of($b, 'fingerprint-b', SiteId::shapeKey($b, $tokensB));

        self::assertSame('template', $siteA->kind);
        self::assertSame($siteA->id, $siteB->id, 'no line number and no token count in the id');
        self::assertSame($siteA->key, $siteB->key);
        self::assertMatchesRegularExpression('/\Atpl:[0-9a-f]{12}\z/', $siteA->id);
    }
}

/** @internal */
final class LogEventFactory
{
    /** @param list<string> $frames */
    public static function make(array $frames, string $message): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), Level::Info, $message, null, $frames, [], []);
    }
}
