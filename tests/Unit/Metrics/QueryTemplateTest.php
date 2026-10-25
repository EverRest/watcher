<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Metrics\QueryTemplate;
use Watcher\Schedule\Window;

final class QueryTemplateTest extends TestCase
{
    private function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
    }

    private function baseline(): Window
    {
        return new Window(new DateTimeImmutable('2026-10-21T00:00:00Z'), new DateTimeImmutable('2026-10-22T00:00:00Z'));
    }

    public function testBuiltInsAreReplaced(): void
    {
        $t = QueryTemplate::compile('q', 'x{service}[{window}] {start} {end} {baseline_start} {baseline_end}', 'orders-api', []);

        self::assertSame(
            'xorders-api[86400s] 1792627200 1792713600 1792540800 1792627200',
            $t->render($this->window(), $this->baseline()),
        );
    }

    public function testVarsAreReplaced(): void
    {
        $t = QueryTemplate::compile('q', 'up{ns="{namespace}"}', 'svc', ['namespace' => 'prod']);

        self::assertSame('up{ns="prod"}', $t->render($this->window(), $this->baseline()));
    }

    public function testPromqlSelectorsAreUntouched(): void
    {
        $q = 'a{job="x"} + b{} + c{__name__=~"a"} + d{code=~"5.."}';
        $t = QueryTemplate::compile('q', $q, 'svc', []);

        self::assertSame($q, $t->render($this->window(), $this->baseline()));
    }

    public function testUnknownPlaceholderIsRejectedByName(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('metrics.queries.err.query uses unknown placeholder {nope}');

        QueryTemplate::compile('err', 'sum(x{a="{nope}"})', 'svc', []);
    }

    /** @return array<string, array{string}> */
    public static function badValues(): array
    {
        return ['brace' => ['{start}'], 'quote' => ['a"b'], 'space' => ['a b'], 'newline' => ["a\nb"], 'backslash' => ['a\\b'], 'empty' => [''], 'long' => [str_repeat('a', 65)]];
    }

    #[DataProvider('badValues')]
    public function testVarValueOutsideTheAllowedSetIsRejectedWithoutEchoingIt(string $value): void
    {
        try {
            QueryTemplate::compile('q', 'up', 'svc', ['namespace' => $value]);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame('metrics.vars.namespace has characters not allowed in a query value', $e->getMessage());
        }
    }

    public function testServiceNameOutsideTheAllowedSetIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('service.name');

        QueryTemplate::compile('q', 'up', 'bad name"', []);
    }

    public function testVarShadowingABuiltInIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('metrics.vars.window shadows a built-in placeholder');

        QueryTemplate::compile('q', 'up', 'svc', ['window' => '1h']);
    }

    public function testQueryOver2000CharsIsRejectedWithoutTheText(): void
    {
        $query = 'SECRETQUERY' . str_repeat('a', 2000);
        try {
            QueryTemplate::compile('q', $query, 'svc', []);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame('metrics.queries.q.query is longer than 2000 characters', $e->getMessage());
        }
    }

    public function testSubstitutedValuesAreLiteral(): void
    {
        $t = QueryTemplate::compile('q', '{service}{namespace}', 'a.b', ['namespace' => 'c:d']);

        self::assertSame('a.bc:d', $t->render($this->window(), $this->baseline()));
    }
}
