<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use PHPUnit\Framework\TestCase;

/** Read-only pin for PrometheusSource (spec 009 T008); kept apart from ArchitectureTest on purpose. */
final class MetricsEndpointAllowlistTest extends TestCase
{
    public function testPrometheusSourceOnlyNamesTheTwoQueryPathsAndReadVerbs(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Metrics/PrometheusSource.php');

        preg_match_all("/'(GET|POST|PUT|PATCH|DELETE|HEAD)'/", $code, $m);
        $verbs = array_values(array_unique($m[1]));
        sort($verbs);
        self::assertSame(['GET', 'POST'], $verbs);
        self::assertSame(0, preg_match('/\b(PUT|PATCH|DELETE|HEAD)\b/', $code));

        preg_match_all("~'(/api/[^']*)'~", $code, $p);
        $paths = array_values(array_unique($p[1]));
        sort($paths);
        self::assertSame(['/api/datasources/proxy/uid/', '/api/v1/query', '/api/v1/query_range'], $paths);
        self::assertSame(0, preg_match('~/(delete|admin|rules|alerts|targets|write|snapshot)~i', $code));
    }
}
