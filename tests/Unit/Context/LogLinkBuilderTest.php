<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Context\LogLinkBuilder;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Schedule\Window;
use Watcher\Source\LineParser;

final class LogLinkBuilderTest extends TestCase
{
    private const GRAFANA = ['grafana' => ['base_url' => 'https://grafana.example.com', 'datasource_uid' => 'loki-1'], 'datadog' => ['app_url' => null]];
    private const DATADOG = ['grafana' => ['base_url' => null, 'datasource_uid' => null], 'datadog' => ['app_url' => 'https://app.datadoghq.eu']];

    private function window(): Window
    {
        return new Window(new DateTimeImmutable('2026-03-01T00:00:00Z'), new DateTimeImmutable('2026-03-02T00:00:00Z'));
    }

    private function group(?string $template, Level $level = Level::Error): RedactedGroup
    {
        $t = new DateTimeImmutable('2026-03-01T00:00:00Z');

        return new RedactedGroup('fp', $template, $level, null, [], 1, null, GroupStatusLabel::New, [], $t, $t);
    }

    /** @param array<string, mixed> $links */
    private function link(array $links, ?string $template, Level $level = Level::Error): ?string
    {
        return (new LogLinkBuilder($links, ['app' => 'shop', 'env' => 'prod']))->forGroup($this->group($template, $level), $this->window());
    }

    public function testNeitherConfiguredGivesNull(): void
    {
        self::assertNull($this->link([], 'x'));
        self::assertNull($this->link(['grafana' => ['base_url' => 'https://g', 'datasource_uid' => null], 'datadog' => ['app_url' => null]], 'x'));
    }

    /** Golden: the Grafana Explore URL shape is version dependent (UNVERIFIED against a live instance). */
    public function testGrafanaGoldenUrl(): void
    {
        $logql = '{app="shop",env="prod"} |= "Lock wait timeout exceeded" |~ "(?i)error|critical|alert|emergency"';
        $panes = '{"a":{"datasource":"loki-1","queries":[{"refId":"A","expr":' . json_encode($logql, JSON_UNESCAPED_SLASHES)
            . ',"datasource":{"type":"loki","uid":"loki-1"}}],"range":{"from":"1772323200000","to":"1772409600000"}}}';

        self::assertSame(
            'https://grafana.example.com/explore?schemaVersion=1&panes=' . rawurlencode($panes) . '&orgId=1',
            $this->link(self::GRAFANA, 'Lock wait timeout exceeded <N> rows'),
        );
    }

    public function testGrafanaWithoutLiteralOrErrorLevel(): void
    {
        $url = (string) $this->link(self::GRAFANA, 'x <N> <URL>', Level::Warning);
        $expr = $this->grafanaExpr($url);

        self::assertSame('{app="shop",env="prod"}', $expr);
        self::assertSame('{app="shop",env="prod"}', $this->grafanaExpr((string) $this->link(self::GRAFANA, null, Level::Warning)));
    }

    public function testLiteralIsLongestPlaceholderFreeRunAndCapped(): void
    {
        $expr = $this->grafanaExpr((string) $this->link(self::GRAFANA, 'ab <N> Connection refused to <HOST:db.internal> now <*> end'));
        self::assertStringContainsString('|= "Connection refused to"', $expr);

        $long = str_repeat('word ', 30);
        $expr = $this->grafanaExpr((string) $this->link(self::GRAFANA, $long . '<N>'));
        self::assertSame(1, preg_match('/\|= "([^"]*)"/', $expr, $m));
        $literal = $m[1] ?? '';
        self::assertLessThanOrEqual(60, strlen($literal));
        self::assertGreaterThan(40, strlen($literal));
    }

    public function testQuotesAndBackslashesAreEscaped(): void
    {
        $url = (string) $this->link(self::GRAFANA, 'say "hi" \\ there <N>', Level::Warning);
        $expr = $this->grafanaExpr($url);

        self::assertStringContainsString('|= "say \\"hi\\" \\\\ there"', $expr);
    }

    public function testDatadogUrl(): void
    {
        self::assertSame(
            'https://app.datadoghq.eu/logs?query=' . rawurlencode('app:shop env:prod "Lock wait timeout"') . '&from_ts=1772323200000&to_ts=1772409600000',
            $this->link(self::DATADOG, 'Lock wait timeout <N>'),
        );
        self::assertSame(
            'https://app.datadoghq.eu/logs?query=' . rawurlencode('app:shop env:prod') . '&from_ts=1772323200000&to_ts=1772409600000',
            $this->link(self::DATADOG, 'ab <N>'),
        );
    }

    public function testNoSeededPrivacyValueReachesAnyLink(): void
    {
        $dir = __DIR__ . '/../../Fixtures/privacy';
        /** @var list<string> $seeded */
        $seeded = require $dir . '/seeded-values.php';
        $parser = new LineParser();
        $redactor = new Redactor(new Normalizer());
        $events = [];
        foreach (file($dir . '/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }
        $urls = [];
        foreach ((new Reducer(new Normalizer(), 3))->reduce($events) as $group) {
            $g = $redactor->redact($group);
            foreach ([self::GRAFANA, self::DATADOG] as $links) {
                $url = (new LogLinkBuilder($links, ['app' => 'shop']))->forGroup($g, $this->window());
                self::assertNotNull($url);
                $urls[] = $url;
            }
        }
        self::assertNotSame([], $urls);

        foreach ($urls as $url) {
            foreach ([$url, rawurldecode($url), rawurldecode(rawurldecode($url))] as $haystack) {
                foreach ($seeded as $value) {
                    self::assertStringNotContainsString($value, $haystack, "seeded value in link: $value");
                }
            }
        }
    }

    private function grafanaExpr(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        /** @var array{a: array{queries: list<array{expr: string}>}} $panes */
        $panes = json_decode(is_string($q['panes'] ?? null) ? $q['panes'] : '', true, 512, JSON_THROW_ON_ERROR);

        return $panes['a']['queries'][0]['expr'];
    }
}
