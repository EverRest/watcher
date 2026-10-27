<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Watcher\OutcomeRegistry;

/** FR-070/073: the ordered setup plan and the first-day procedure, from {@see SetupCatalog}. A function of its enumerated inputs: the same input gives the same bytes. */
final class SetupPlanner
{
    public const PLAN_VERSION = 1;

    private const SMOKE = 'docs/smoke-test.md';
    private const OPS = 'docs/operations.md';

    /** @var array<string, string> */
    private const SOURCE_HELP = [
        'loki' => 'Loki: open Grafana > Explore > the label browser and pick labels that exist for this service. Use the Grafana data-source proxy URL (https://<grafana>/api/datasources/proxy/uid/<uid>) or the Loki base URL. The token needs the Viewer role only.',
        'datadog' => 'Datadog: create an API key and an application key; the application key needs only the logs_read_data scope. Note your site (for example datadoghq.eu).',
    ];
    /** @var array<string, string> */
    private const NOTIFIER_HELP = [
        'slack' => 'Slack: create an incoming webhook for a private test channel first; keep the URL in the secret store.',
        'telegram' => 'Telegram: create a bot with BotFather and add it to the chat; you need the bot token (secret) and the chat id (negative for a supergroup) or @channel name.',
        'discord' => 'Discord: create a webhook in the channel settings; the URL is a secret.',
    ];
    /** @var array<string, string> */
    private const STATE_HELP = [
        'file' => 'File store: a private directory (mode 0700) on a disk the runner keeps. Single runner only: two schedulers must not share it.',
        'pdo' => 'PDO store: create the table once with PdoStateStore::createTableSql(<driver>, <table>) in a migration; the package never creates it.',
        'redis' => 'Redis: choose a database index and a key prefix that no other environment uses; ext-redis or predis/predis must be installed.',
    ];

    /**
     * @param list<string> $features
     * @return array{plan_version: int, steps: list<array<string, mixed>>, env_vars_all: list<array{name: string, purpose: string, secret: bool, required: bool}>}
     */
    public function plan(string $host, string $source, string $notifier, string $state, array $features = []): array
    {
        $logical = [...SetupCatalog::COMMON_VARS, ...SetupCatalog::SOURCE_VARS[$source], ...SetupCatalog::NOTIFIER_VARS[$notifier], ...SetupCatalog::STATE_VARS[$state]];
        $vars = SetupCatalog::vars($logical);
        $names = array_column($vars, 'name');
        $cmd = static fn (string $sub): string => SetupCatalog::command($host, $sub);
        $steps = [
            ['id' => 'collect', 'title' => 'Collect the non-secret inputs', 'collect' => ['service name', 'run time (HH:MM) and time zone', 'log labels that select this service', self::SOURCE_HELP[$source], self::NOTIFIER_HELP[$notifier], self::STATE_HELP[$state]], 'where_to_find' => ['Your log UI label browser'], 'env_vars' => [], 'verify' => ['command' => $cmd('check'), 'expect' => OutcomeRegistry::DISABLED], 'rollback' => 'Nothing was changed yet.', 'docs' => [self::doc(self::SMOKE, '1-what-you-need')]],
            ['id' => 'env', 'title' => 'Define the environment variables in your secret store', 'collect' => ['one entry per variable of this step'], 'where_to_find' => ['Your secret store or the deployment environment; never a file in the repository'], 'env_vars' => $names, 'verify' => ['command' => $cmd('check'), 'expect' => OutcomeRegistry::DISABLED], 'rollback' => 'Remove the variables again.', 'docs' => [self::doc(self::SMOKE, '2-prepare-the-shell')]],
            ['id' => 'config', 'title' => 'Create the configuration file', 'collect' => ['the answers of step 1'], 'where_to_find' => ['The watcher_generate_config tool drafts it; save the snippet yourself'], 'env_vars' => [], 'verify' => ['command' => $cmd('check'), 'expect' => OutcomeRegistry::DISABLED], 'rollback' => 'Delete the file.', 'docs' => [self::doc(self::SMOKE, '3-config'), self::doc('docs/configuration.md', 'required-keys')]],
            ['id' => 'check', 'title' => 'Check: the configuration loads and every dependency answers', 'collect' => [], 'where_to_find' => [], 'env_vars' => [], 'verify' => ['command' => $cmd('check'), 'expect' => OutcomeRegistry::DISABLED], 'rollback' => 'Fix the key the failing line names.', 'docs' => [self::doc(self::OPS, 'before-the-first-run-check-and-dry-run')]],
            ['id' => 'estimate', 'title' => 'Estimate the model calls without a model call', 'collect' => [], 'where_to_find' => [], 'env_vars' => [], 'verify' => ['command' => $cmd('run --dry-run --estimate'), 'expect' => OutcomeRegistry::QUIET], 'rollback' => 'Nothing is stored by a dry run.', 'docs' => [self::doc(self::OPS, 'before-the-first-run-check-and-dry-run')]],
            ['id' => 'baseline', 'title' => 'First real run, into a private channel', 'collect' => ['confirm the digest channel is private'], 'where_to_find' => [], 'env_vars' => [], 'verify' => ['command' => $cmd('run'), 'expect' => OutcomeRegistry::BASELINED], 'rollback' => 'Unset the master switch.', 'docs' => [self::doc(self::SMOKE, '5-check-2-the-baseline')]],
            ['id' => 'second_run', 'title' => 'Second run: a real analysis', 'collect' => [], 'where_to_find' => [], 'env_vars' => [], 'verify' => ['command' => $cmd('run'), 'expect' => OutcomeRegistry::REPORTED], 'rollback' => 'Unset the master switch.', 'docs' => [self::doc(self::SMOKE, '6-check-3-the-analysis')]],
            ['id' => 'idempotency', 'title' => 'Run again: nothing repeats', 'collect' => [], 'where_to_find' => [], 'env_vars' => [], 'verify' => ['command' => $cmd('run'), 'expect' => OutcomeRegistry::SKIPPED_DONE], 'rollback' => 'None.', 'docs' => [self::doc(self::SMOKE, '7-check-4-and-5-privacy-and-idempotency')]],
            ['id' => 'status', 'title' => 'Status shows the last run as healthy', 'collect' => [], 'where_to_find' => [], 'env_vars' => [], 'verify' => ['command' => $cmd('status'), 'expect' => OutcomeRegistry::REPORTED], 'rollback' => 'None.', 'docs' => [self::doc(self::OPS, 'heartbeat-staleness-and-alerting')]],
        ];
        foreach ($features as $f) {
            $steps[] = ['id' => 'features.' . $f, 'title' => 'Optional feature: ' . str_replace('_', ' ', $f), 'collect' => ['see the configuration reference for the keys of this feature'], 'where_to_find' => [], 'env_vars' => [], 'verify' => ['command' => $cmd('check'), 'expect' => OutcomeRegistry::REPORTED], 'rollback' => 'Turn the feature flag off.', 'docs' => [self::doc('docs/configuration.md', 'feature-flags-spec-012')]];
        }
        $steps[] = ['id' => 'enable', 'title' => 'Enable on the schedule, per environment', 'collect' => ['the scheduler entry (daily at the run time)'], 'where_to_find' => [], 'env_vars' => ['WATCHER_ENABLED'], 'verify' => ['command' => $cmd('status'), 'expect' => OutcomeRegistry::REPORTED], 'rollback' => 'Unset WATCHER_ENABLED: the next trigger does nothing.', 'docs' => [self::doc(self::OPS, 'enabling-and-disabling-per-environment')]];
        $steps[] = ['id' => 'rollback', 'title' => 'Know how to switch it off', 'collect' => [], 'where_to_find' => [], 'env_vars' => ['WATCHER_ENABLED'], 'verify' => ['command' => $cmd('run'), 'expect' => OutcomeRegistry::DISABLED], 'rollback' => 'Set WATCHER_ENABLED to 0 and remove the scheduler entry.', 'docs' => [self::doc(self::OPS, 'rollback--disabling')]];
        foreach ($steps as $i => &$s) {
            $s = ['n' => $i + 1] + $s;
        }
        unset($s);

        return ['plan_version' => self::PLAN_VERSION, 'steps' => $steps, 'env_vars_all' => $vars];
    }

    /** @return array{steps: list<array{n: int, id: string, title: string, command: string, expect: string, if_different: string}>} */
    public function firstDay(string $host): array
    {
        $rows = [
            ['check', 'Check the configuration and dependencies', 'check', OutcomeRegistry::DISABLED, OutcomeRegistry::INVALID_CONFIG],
            ['estimate', 'Estimate the model calls (no model call)', 'run --dry-run --estimate', OutcomeRegistry::QUIET, OutcomeRegistry::FAILED],
            ['baseline', 'Baseline run', 'run', OutcomeRegistry::BASELINED, OutcomeRegistry::FAILED],
            ['second_run', 'Second run (reported or quiet)', 'run', OutcomeRegistry::REPORTED, OutcomeRegistry::QUIET],
            ['idempotency', 'Run again: nothing repeats', 'run', OutcomeRegistry::SKIPPED_DONE, OutcomeRegistry::SKIPPED_LOCKED],
            ['status', 'Status', 'status', OutcomeRegistry::REPORTED, OutcomeRegistry::FAILED],
        ];
        $steps = [];
        foreach ($rows as $i => [$id, $title, $sub, $expect, $else]) {
            $steps[] = ['n' => $i + 1, 'id' => $id, 'title' => $title, 'command' => SetupCatalog::command($host, $sub), 'expect' => $expect, 'if_different' => $else];
        }

        return ['steps' => $steps];
    }

    /** @return array{file: string, anchor: string} */
    private static function doc(string $file, string $anchor): array
    {
        return ['file' => $file, 'anchor' => $anchor];
    }
}
