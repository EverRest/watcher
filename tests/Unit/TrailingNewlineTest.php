<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Context\TraceIdCollector;
use Watcher\Context\TraceLinkBuilder;
use Watcher\Level;
use Watcher\Metrics\MetricDefinition;
use Watcher\Metrics\QueryTemplate;
use Watcher\Schedule\Window;
use Watcher\Source\LogEvent;

/** Review F2: `$` accepts a trailing "\n"; every strict validator of spec 009 must not. */
final class TrailingNewlineTest extends TestCase
{
    private const ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    public function testQueryTemplateRefusesANewlineInServiceAndVars(): void
    {
        foreach ([fn () => QueryTemplate::compile('q', 'up{job="{service}"}', "abc\n", []), fn () => QueryTemplate::compile('q', 'up{a="{x}"}', 'abc', ['x' => "v\n"])] as $f) {
            try {
                $f();
                self::fail('accepted a trailing newline');
            } catch (InvalidConfig) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMetricNameAndUnitRefuseANewline(): void
    {
        $q = QueryTemplate::compile('q', 'q', 'svc', []);
        foreach ([["m\n", ''], ['m', "%\n"]] as [$name, $unit]) {
            try {
                new MetricDefinition($name, $q, null, null, $unit);
                self::fail('accepted a trailing newline');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTraceIdWithANewlineIsNotCollectedNorLinked(): void
    {
        $c = new TraceIdCollector();
        $c->observe(new LogEvent(new DateTimeImmutable('2026-10-22T10:00:00Z'), Level::Error, 'm', null, [], ['trace_id' => self::ID . "\n"], []), 'fp');
        self::assertNull($c->idFor('fp'));

        $links = ['tempo' => ['base_url' => 'https://g.example/', 'datasource_uid' => 'tempo']];
        $w = Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
        $url = (new TraceLinkBuilder($links, [], 'svc'))->forGroup(self::ID . "\n", $w);
        self::assertStringNotContainsString(self::ID, (string) $url); // falls back to the service search
    }
}
