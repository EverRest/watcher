<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\ApiContext\OperationSummary;
use Watcher\ApiContext\ReducedSpec;
use Watcher\ApiContext\RouteExtractor;
use Watcher\ApiContext\RouteMatcher;

final class RouteMatcherTest extends TestCase
{
    /** @return array{ops: array<string, array<mixed>>, rows: list<array<mixed>>, base: string} */
    private static function table(): array
    {
        /** @var array{ops: array<string, array<mixed>>, rows: list<array<mixed>>, base: string} $t */
        $t = require __DIR__ . '/../../Fixtures/api_context/match-cases.php';

        return $t;
    }

    /** @return array<string, array<mixed>> */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::table()['rows'] as $row) {
            $cases['#' . $row[0] . ' ' . var_export($row[1], true)] = $row;
        }

        return $cases;
    }

    public function testTableHoldsAtLeastThirtyCases(): void
    {
        self::assertGreaterThanOrEqual(30, count(self::table()['rows']));
        self::assertCount(41, self::table()['rows']);
    }

    /** The contract table and the fixture are the same list: a row added to one and not the other fails here. */
    public function testContractTableAndFixtureHaveTheSameRows(): void
    {
        $contract = (string) file_get_contents(__DIR__ . '/../../../specs/010-api-context/contracts/matching.md');
        preg_match_all('/^\| (\d+) \| [SRX] \|/m', $contract, $m);
        $fixture = array_map(static fn (array $r): int => $r[0], self::table()['rows']);

        self::assertSame($fixture, array_map('intval', $m[1]));
        self::assertSame(range(1, 41), $fixture);
    }

    /**
     * @param array<string, mixed>                $config
     * @param array<string, array<mixed>>         $overrides
     */
    #[DataProvider('cases')]
    public function testCase(int $n, ?string $template, array $config, ?string $expected, array $overrides = [], string $flag = ''): void
    {
        $table = self::table();
        $built = [];
        foreach (array_merge($table['ops'], $overrides) as $name => [$m, $t, $s, $g, $d, $o]) {
            $built[$name] = new OperationSummary($m, $t, $s, $g, $d, $o);
        }
        $spec = new ReducedSpec(is_string($config['base_path'] ?? null) ? $config['base_path'] : $table['base'], array_values($built), 0);
        $route = (new RouteExtractor())->extract($template);
        $matcher = new RouteMatcher(is_array($config['ignore_prefixes'] ?? null) ? array_values($config['ignore_prefixes']) : []);

        $got = $route === null ? null : $matcher->match($route, $spec);

        if ($expected === null) {
            self::assertNull($got, "row $n must attach nothing");

            return;
        }
        self::assertSame($built[$expected], $got, "row $n");
    }

    public function testTemplateThatAlreadyStartsWithTheBaseIsStripped(): void
    {
        $spec = new ReducedSpec('/api', [new OperationSummary('GET', '/api/orders/{id}', 'Get', '', false, '')], 0);
        $route = (new RouteExtractor())->extract('GET /api/orders/<ID>');
        self::assertNotNull($route);
        self::assertNotNull((new RouteMatcher())->match($route, $spec));
    }

    public function testExactTieAttachesNothingAndASingleCandidateMatches(): void
    {
        // a route Literal never matches a template Param, so specificity cannot separate two candidates: equal shapes tie
        $one = new OperationSummary('GET', '/x/{a}/{b}', 'one', '', false, '');
        $two = new OperationSummary('GET', '/x/{c}/{d}', 'two', '', false, '');
        $route = (new RouteExtractor())->extract('GET /x/<ID>/<ID>');
        self::assertNotNull($route);
        self::assertNull((new RouteMatcher())->match($route, new ReducedSpec('', [$one, $two], 0)), 'exact tie');
        self::assertSame($one, (new RouteMatcher())->match($route, new ReducedSpec('', [$one], 0)));
    }
}
