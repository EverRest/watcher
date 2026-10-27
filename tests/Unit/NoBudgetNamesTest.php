<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Repository guard (plan 017): no budget, price, spend or cost name left in the shipped tree (src, docs, config, README). */
final class NoBudgetNamesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const NEEDLES = ['usd', 'Usd', 'USD', 'CostCalculator', 'UnknownModelPrice', 'cost_usd', 'daily_budget', 'run_budget', 'daily_token_limit', 'tokens_today', 'WATCHER_PRICE', 'budget', 'max_runs_per_day', 'budget_alert_percents', 'spend', 'price'];
    /** Files that legitimately name removed things: history, the removed-keys table, and the v1 schema kept as retired. */
    private const SKIP = ['CHANGELOG.md', 'audit-2026-10-23.md', 'review-2026-10-23.md', 'review-fixes-2026-10-25.md', 'brief.md', 'roadmap.md', 'removed-config-keys.php', 'watcher.event.v1.schema.json'];
    /**
     * Legitimate uses of a needle, as [relative file => needles it may appear in]. Every entry is a time, retry, memory, clustering
     * or size limit (or a removed-key check), never money: the removed budget was daily or per run in USD or tokens.
     * `Watcher\Analyze\Budget` (the input-size cut, max_input_tokens) is capitalised, so no needle matches it and it needs no entry.
     */
    private const ALLOW = [
        // time limits: wall-clock deadlines, the run and fleet time_budget, page and retry waits
        'src/Watcher.php' => ['budget'], // time_budget share of a fleet, the page budget note, the run deadline
        'src/Metrics/PrometheusSource.php' => ['budget'], // retryBudget, budgetSeconds: per-call wait limits
        'src/Issues/SentryIssueSource.php' => ['budget'], // retryBudget, budgetSeconds: per-call wait limits
        'src/Notify/DigestRenderer.php' => ['budget'], // budgetExhausted: the clustering comparison cap was hit (a notice, no money)
        'src/Schedule/RunGuard.php' => ['budget'], // the run time budget (deadline reason)
        'src/Config/Factory.php' => ['budget'], // SOURCE_BUDGET_SECONDS: the wall-clock limit of one source call
        'src/Config/WatchConfig.php' => ['budget'], // grouping.memory_budget_mb
        'src/Config/KeyCatalog.php' => ['budget'], // time_budget, service_time_budget, memory_budget_mb keys and their summaries
        'src/Fleet/FleetConfig.php' => ['budget'], // time_budget keys, and the removed-section check that reads the removed-keys table
        'src/Fleet/FleetRunner.php' => ['budget'],
        'src/Reduce/EventCollector.php' => ['budget'], // memory_budget_mb passed to the miner
        'src/Reduce/TemplateMiner.php' => ['budget'], // comparison budget (clustering work cap) and memory budget
        'src/Reduce/MiningStats.php' => ['budget'], // budgetExhausted: clustering cap
        'src/Source/Retry.php' => ['budget'], // the per-call retry wait budget
        'src/Source/GuardedRequest.php' => ['budget'], // the per-call wall-clock budget of one source call
        'src/Source/LokiSource.php' => ['budget'], // page/time budget of one read
        'src/Source/DatadogSource.php' => ['budget'], // page/time budget of one read
        'src/Source/Kubernetes/KubeApi.php' => ['budget'], // two-minute request budget of one ingest
        'src/Source/Kubernetes/EventLedger.php' => ['budget'], // the state store's size and structure limits
        'src/Hygiene/Hygiene.php' => ['budget'], // hygiene time budget and the store's size limits
        'src/Hygiene/DayRecord.php' => ['budget'], // the store's size and structure limits
        'src/State/StateCodec.php' => ['budget'], // the state value size and structure limits
        'src/State/StateValueTooLarge.php' => ['budget'], // the state value size and structure limits
        'src/Observe/HttpSink.php' => ['budget'], // flush wall-clock limit
        'src/Select/CandidateReason.php' => ['budget'], // input-size truncation order
        'src/Select/Tier.php' => ['budget'], // input-size truncation order
        'src/ApiContext/EndpointContext.php' => ['budget'], // input-size truncation of endpoint fields
        'README.md' => ['budget'], // comparison budget of clustering; the time budget
        'docs/configuration.md' => ['budget'], // time_budget, service_time_budget, memory_budget_mb, max_seconds and clustering limits
        'docs/operations.md' => ['budget', 'spend'], // time/page budgets, clustering budget, store size budget; 'spend' only inside 'suspend' (CronJob)
        'docs/privacy.md' => ['budget'], // comparison budget of clustering
        'docs/kubernetes.md' => ['budget'], // two-minute request budget and the store's codec budgets
        'docs/examples/watcher-fleet.php' => ['budget'], // time_budget key
    ];

    public function testNoBudgetOrCostNameInSourceDocsConfigOrReadme(): void
    {
        $hits = [];
        $files = [self::ROOT . '/README.md'];
        foreach (['src', 'docs', 'config'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $path) {
            $name = basename($path);
            if (!is_file($path) || in_array($name, self::SKIP, true)) {
                continue;
            }
            $rel = substr($path, strlen(self::ROOT) + 1);
            $text = (string) file_get_contents($path);
            foreach (self::NEEDLES as $needle) {
                if (str_contains($text, $needle) && !in_array($needle, self::ALLOW[$rel] ?? [], true)) {
                    $hits[] = $rel . ' contains ' . $needle;
                }
            }
        }

        self::assertSame([], $hits);
    }
}
