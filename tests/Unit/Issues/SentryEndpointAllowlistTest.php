<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Issues;

use PHPUnit\Framework\TestCase;

/** Read-only pin for SentryIssueSource (spec 009 T027); kept apart from ArchitectureTest on purpose. */
final class SentryEndpointAllowlistTest extends TestCase
{
    public function testSentryIssueSourceOnlyGetsTheIssueList(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Issues/SentryIssueSource.php');

        preg_match_all("/'(GET|POST|PUT|PATCH|DELETE|HEAD)'/", $code, $m);
        self::assertSame(['GET'], array_values(array_unique($m[1])));
        self::assertSame(0, preg_match('/\b(POST|PUT|PATCH|DELETE|HEAD)\b/', $code));
        self::assertSame(1, substr_count($code, "/api/0/projects/"));
        self::assertSame(0, preg_match('~/(events|users|tags|attachments|hashes)/|breadcrumbs~i', $code));
    }
}
