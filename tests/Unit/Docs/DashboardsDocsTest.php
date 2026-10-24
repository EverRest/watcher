<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Watcher\Observe\EventSchema;
use Watcher\Observe\MetricCatalog;

/** Spec 013 US6 / SC-010: what the shipped dashboard and the guide query exists in the metric catalog and the event schema. */
final class DashboardsDocsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../docs/dashboards';
    private const FILES = ['grafana-watcher.json', 'grafana-loki.json', 'grafana-prometheus.json'];
    private const GUIDE = __DIR__ . '/../../../docs/observability.md';

    /** @return array<string, mixed> */
    private static function dashboard(string $file = 'grafana-watcher.json'): array
    {
        return json_decode((string) file_get_contents(self::DIR . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> every field a Loki `| json` query may name: envelope, type fields, text fields, nested counts as Loki flattens them */
    private static function eventFields(): array
    {
        $fields = [];
        foreach (EventSchema::TYPES as $type) {
            $fields = [...$fields, ...EventSchema::fields($type, true)];
        }
        foreach (EventSchema::COUNTS as $parent => $keys) {
            foreach ($keys as $k) {
                $fields[] = $parent . '_' . $k;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<mixed> $node
     * @return list<string> every target expression of every panel, rows included
     */
    private static function exprs(array $node): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if ($k === 'expr' && is_string($v)) {
                $out[] = $v;
            } elseif (is_array($v)) {
                $out = [...$out, ...self::exprs($v)];
            }
        }

        return $out;
    }

    /** @return list<string> problems found in one LogQL or PromQL expression */
    private static function problems(string $expr): array
    {
        $bad = [];
        preg_match_all('/\bwatcher_\w+/', $expr, $m);
        foreach ($m[0] as $metric) {
            isset(MetricCatalog::METRICS[$metric]) || $bad[] = "metric $metric";
        }
        preg_match_all('/event_type\s*(?:=|=~)\s*"([^"]+)"/', $expr, $m);
        foreach ($m[1] as $types) {
            foreach (explode('|', $types) as $type) {
                in_array($type, EventSchema::TYPES, true) || $bad[] = "event_type $type";
            }
        }
        if (str_contains($expr, '| json')) {
            preg_match_all('/unwrap\s+(\w+)|\|\s*(\w+)\s*(?:=~|!=|=|<=|>=|<|>)|\bby\s*\((\w+)\)/', $expr, $f, PREG_SET_ORDER);
            foreach ($f as $hit) {
                $field = $hit[1] ?? '';
                $field = $field !== '' ? $field : ($hit[2] ?? '');
                $field = $field !== '' ? $field : ($hit[3] ?? '');
                if ($field !== '' && $field !== 'json' && $field !== 'event_type' && !in_array($field, self::eventFields(), true)) {
                    $bad[] = "field $field";
                }
            }
        }

        return $bad;
    }

    public function testTheDashboardParsesWithAPinnedSchemaVersionAndNoHardCodedUid(): void
    {
        $d = self::dashboard();

        self::assertSame(39, $d['schemaVersion']);
        self::assertNull($d['uid'] ?? null, 'an import must not collide with an existing dashboard');
        self::assertNull($d['id'] ?? null);
        $json = (string) file_get_contents(self::DIR . '/grafana-watcher.json');
        preg_match_all('/"datasource"\s*:\s*\{[^}]*"uid"\s*:\s*"([^"]*)"/', $json, $m);
        self::assertGreaterThan(10, count($m[1]));
        foreach ($m[1] as $uid) {
            self::assertMatchesRegularExpression('/^\$\{(DS_LOKI|DS_PROMETHEUS)\}$/', $uid, 'datasources come from the template variables');
        }
    }

    public function testBothDatasourcesAndTheStreamSelectorAreTemplateVariables(): void
    {
        $vars = [];
        foreach (self::dashboard()['templating']['list'] as $v) {
            $vars[$v['name']] = $v;
        }

        self::assertSame(['datasource', 'loki'], [$vars['DS_LOKI']['type'], $vars['DS_LOKI']['query']]);
        self::assertSame(['datasource', 'prometheus'], [$vars['DS_PROMETHEUS']['type'], $vars['DS_PROMETHEUS']['query']]);
        self::assertSame('textbox', $vars['stream']['type'], 'the Loki stream selector is the operator\'s, not ours');
        self::assertArrayHasKey('service', $vars);
        self::assertArrayHasKey('environment', $vars);
    }

    public function testEveryQueryOfTheDashboardUsesOnlyTheCatalogAndTheSchema(): void
    {
        $exprs = self::exprs(self::dashboard());

        self::assertGreaterThan(15, count($exprs));
        foreach ($exprs as $expr) {
            self::assertSame([], self::problems($expr), $expr);
        }
    }

    public function testTheDashboardCoversTheTopRowAndBothPaths(): void
    {
        $text = implode("\n", self::exprs(self::dashboard()));

        foreach (['watcher_last_success_timestamp_seconds', 'watcher_budget_ratio', 'watcher_sink_failures_total', 'event_type="run"', 'event_type="finding"', 'event_type="group"', 'unwrap cost_usd'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
    }

    public function testTheGuideQueriesExistInTheCatalogAndTheSchema(): void
    {
        $guide = (string) file_get_contents(self::GUIDE);

        preg_match_all('/`([^`\n]*(?:\| json|watcher_[a-z_]+)[^`\n]*)`/', $guide, $m);
        self::assertGreaterThan(8, count($m[1]));
        foreach ($m[1] as $expr) {
            // The "run summary line" section queries the older `--json` line, whose fields are JsonSummary's.
            if (str_contains($expr, 'app="watcher"') && !str_contains($expr, 'event_type')) {
                continue;
            }
            self::assertSame([], self::problems($expr), $expr);
        }
        foreach (['log', 'file', 'http', 'prometheus'] as $sink) {
            self::assertStringContainsString('`observe.' . $sink . '`', $guide);
        }
        self::assertStringContainsString('driver: none', $guide);
        self::assertStringContainsString('watcher.event.v1', $guide);
    }

    public function testTheGuideKeepsTheZeroDependencySummaryLineQueries(): void
    {
        $guide = (string) file_get_contents(self::GUIDE);

        foreach (['sum by (outcome) (count_over_time({app="watcher"} | json | __error__="" [1d]))', 'unwrap cost_usd', 'unwrap tokens_in', 'line_format "{{.service}} {{.outcome}} findings={{.findings}} cost={{.cost_usd}}"'] as $query) {
            self::assertStringContainsString($query, $guide);
        }
        // Every field of those queries is a key of the summary line.
        $summary = array_keys((array) json_decode((new \Watcher\Operate\JsonSummary())->render(new \Watcher\Operate\RunSummaryData(\Watcher\RunOutcome::Quiet, 'svc')), true));
        preg_match_all('/unwrap\s+(\w+)|\{\{\.(\w+)\}\}/', substr($guide, (int) strpos($guide, '## 8. The run summary line (zero dependencies)')), $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $field = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
            self::assertContains($field, $summary, "summary line has no field $field");
        }
    }

    public function testEveryShippedDashboardParsesIsGenericAndUsesOnlyTheCatalogAndTheSchema(): void
    {
        foreach (self::FILES as $file) {
            $d = self::dashboard($file);
            self::assertSame(39, $d['schemaVersion'], $file);
            self::assertNull($d['uid'] ?? null, $file);
            $json = (string) file_get_contents(self::DIR . '/' . $file);
            preg_match_all('/"datasource"\s*:\s*\{[^}]*"uid"\s*:\s*"([^"]*)"/', $json, $m);
            self::assertNotSame([], $m[1], $file);
            foreach ($m[1] as $uid) {
                self::assertMatchesRegularExpression('/^\$\{(DS_LOKI|DS_PROMETHEUS)\}$/', $uid, $file);
            }
            $exprs = self::exprs($d);
            self::assertGreaterThan(8, count($exprs), $file);
            foreach ($exprs as $expr) {
                self::assertSame([], self::problems($expr), "$file: $expr");
            }
            $panelIds = array_column($d['panels'], 'id');
            self::assertSame($panelIds, array_values(array_unique($panelIds)), "$file: panel ids are unique");
        }
        self::assertStringNotContainsString('watcher_', implode("\n", self::exprs(self::dashboard('grafana-loki.json'))));
        self::assertStringNotContainsString('event_type', implode("\n", self::exprs(self::dashboard('grafana-prometheus.json'))));
    }

    public function testThePrometheusRulesParseAndUseOnlyCatalogMetrics(): void
    {
        $rules = Yaml::parseFile(self::DIR . '/prometheus-rules.yaml');
        $alerts = [];
        foreach ($rules['groups'][0]['rules'] as $rule) {
            $alerts[] = $rule['alert'];
            self::assertSame([], self::problems($rule['expr']), $rule['expr']);
            self::assertArrayHasKey('summary', $rule['annotations']);
            self::assertArrayHasKey('runbook_url', $rule['annotations']);
            self::assertStringNotContainsString('title', json_encode($rule['annotations'], JSON_THROW_ON_ERROR), 'no event text in an alert');
        }
        foreach (['WatcherNoSuccessfulRun36h', 'WatcherRunFailed', 'WatcherBudgetHigh', 'WatcherNewCriticalFinding', 'WatcherDeliveryOrSinkFailures'] as $name) {
            self::assertContains($name, $alerts);
        }
    }

    public function testTheGrafanaAlertsParseAndQueryOnlyTheEventSchema(): void
    {
        $file = Yaml::parseFile(self::DIR . '/grafana-alerts.yaml');
        self::assertSame(1, $file['apiVersion']);
        $titles = [];
        foreach ($file['groups'][0]['rules'] as $rule) {
            $titles[] = $rule['title'];
            $queries = array_filter(array_column($rule['data'], 'model'), static fn (array $m): bool => isset($m['expr']));
            self::assertNotSame([], $queries, $rule['title']);
            foreach ($queries as $q) {
                self::assertSame([], self::problems($q['expr']), $q['expr']);
            }
        }
        self::assertContains('WatcherNoSuccessfulRun36h', $titles);
        self::assertSame('Alerting', $file['groups'][0]['rules'][0]['noDataState'], 'silence is the failure');
    }

    public function testTheMappingNamesEveryEventField(): void
    {
        $map = (string) file_get_contents(self::DIR . '/mapping.md');

        foreach (self::eventFieldsWithoutFlattening() as $field) {
            self::assertStringContainsString('`' . $field . '`', $map, "mapping.md does not list $field");
        }
        foreach (['Elasticsearch', 'Datadog', 'Metabase', 'Filebeat', 'Vector', 'Alloy'] as $word) {
            self::assertStringContainsString($word, $map);
        }
    }

    /** @return list<string> */
    private static function eventFieldsWithoutFlattening(): array
    {
        $fields = [];
        foreach (EventSchema::TYPES as $type) {
            $fields = [...$fields, ...EventSchema::fields($type, true)];
        }

        return array_values(array_unique($fields));
    }
}
