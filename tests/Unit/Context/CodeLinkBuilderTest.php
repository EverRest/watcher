<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Context\CodeLinkBuilder;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

final class CodeLinkBuilderTest extends TestCase
{
    private const GITLAB = ['provider' => 'gitlab', 'web_url' => 'https://git.example.com/g/app', 'path_map' => ['/var/www/html/' => 'app/']];

    /** @param array<string, mixed> $repo */
    private function b(array $repo = self::GITLAB, ?string $commit = 'abc123'): CodeLinkBuilder
    {
        return new CodeLinkBuilder($repo, $commit);
    }

    public function testGitlabLink(): void
    {
        self::assertSame(
            'https://git.example.com/g/app/-/blob/abc123/app/Services/Foo.php#L42',
            $this->b()->forFrame('/var/www/html/Services/Foo.php:42'),
        );
    }

    public function testGithubLinkAndTrailingSlashOnWebUrl(): void
    {
        $repo = ['provider' => 'github', 'web_url' => 'https://github.com/o/r/', 'path_map' => ['/srv/' => 'src/']];

        self::assertSame('https://github.com/o/r/blob/c0ffee/src/a/B.php#L7', $this->b($repo, 'c0ffee')->forFrame('/srv/a/B.php:7'));
    }

    public function testLongestPrefixWins(): void
    {
        $repo = self::GITLAB;
        $repo['path_map'] = ['/var/www/' => 'wrong/', '/var/www/html/' => 'app/'];

        self::assertSame(
            'https://git.example.com/g/app/-/blob/abc123/app/X.php#L1',
            $this->b($repo)->forFrame('/var/www/html/X.php:1'),
        );
    }

    public function testUnmappedVendorAndTraversalGiveNoLink(): void
    {
        $b = $this->b();

        self::assertNull($b->forFrame('/other/Foo.php:1'));
        self::assertNull($b->forFrame('/var/www/html/vendor/laravel/x.php:1'));
        self::assertNull($b->forFrame('/var/www/html/Services/../../etc/passwd:1'));
        self::assertNull($b->forFrame('/var/www/html/Foo.php'));
        self::assertNull($b->forFrame('/var/www/html/Foo.php:abc'));

        $repo = self::GITLAB;
        $repo['path_map'] = ['/var/www/html/' => ''];
        self::assertNull($this->b($repo)->forFrame('/var/www/html/vendor/x.php:3'));
    }

    public function testMissingCommitOrConfigGivesNoLink(): void
    {
        self::assertNull($this->b(self::GITLAB, null)->forFrame('/var/www/html/Foo.php:1'));
        self::assertNull($this->b(self::GITLAB, '')->forFrame('/var/www/html/Foo.php:1'));
        self::assertNull($this->b([])->forFrame('/var/www/html/Foo.php:1'));
        self::assertNull($this->b(['provider' => 'bitbucket', 'web_url' => 'https://x', 'path_map' => ['/a/' => 'b/']])->forFrame('/a/c.php:1'));
        self::assertNull($this->b(['provider' => 'gitlab', 'path_map' => ['/a/' => 'b/']])->forFrame('/a/c.php:1'));
    }

    public function testPathSegmentsAreEncodedButSlashesKept(): void
    {
        self::assertSame(
            'https://git.example.com/g/app/-/blob/abc123/app/a%20b/c%23d.php#L9',
            $this->b()->forFrame('/var/www/html/a b/c#d.php:9'),
        );
    }

    public function testForGroupReturnsTopThreeNonNullLinks(): void
    {
        $t = new DateTimeImmutable('2026-03-01T00:00:00Z');
        $g = new RedactedGroup('fp', 't', Level::Error, null, [
            '/var/www/html/vendor/a.php:1',
            '/var/www/html/A.php:1',
            '/nope/B.php:2',
            '/var/www/html/C.php:3',
            '/var/www/html/D.php:4',
            '/var/www/html/E.php:5',
        ], 1, null, GroupStatusLabel::New, [], $t, $t);

        $links = $this->b()->forGroup($g);

        self::assertSame([
            'https://git.example.com/g/app/-/blob/abc123/app/A.php#L1',
            'https://git.example.com/g/app/-/blob/abc123/app/C.php#L3',
            'https://git.example.com/g/app/-/blob/abc123/app/D.php#L4',
        ], $links);
    }
}
