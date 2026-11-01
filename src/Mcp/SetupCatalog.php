<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * Code catalog behind the setup tools (research R13): the environment variables the shipped configs read, per driver, and the
 * commands per host. Static text only: no value, no model id, no currency figure, no URL with a credential. Derived from docs/smoke-test.md,
 * docs/configuration.md and docs/operations.md; the anchors it cites are checked against those files by a test.
 */
final class SetupCatalog
{
    /** @var array<string, array{name: string, purpose: string, secret: bool, required: bool}> logical name => variable */
    public const ENV = [
        'enabled' => ['name' => 'WATCHER_ENABLED', 'purpose' => 'Master switch; off unless set to 1/true in the environment that should run the watcher.', 'secret' => false, 'required' => false],
        'gemini_key' => ['name' => 'WATCHER_GEMINI_KEY', 'purpose' => 'Gemini API key (the model provider).', 'secret' => true, 'required' => true],
        'model_triage' => ['name' => 'WATCHER_MODEL_TRIAGE', 'purpose' => 'Model id of the triage tier (the cheapest).', 'secret' => false, 'required' => true],
        'model_analyze' => ['name' => 'WATCHER_MODEL_ANALYZE', 'purpose' => 'Model id of the analysis tier.', 'secret' => false, 'required' => true],
        'model_escalate' => ['name' => 'WATCHER_MODEL_ESCALATE', 'purpose' => 'Model id of the escalation tier (the strongest).', 'secret' => false, 'required' => true],
        'loki_url' => ['name' => 'WATCHER_LOKI_URL', 'purpose' => 'Loki base URL or the Grafana data-source proxy URL.', 'secret' => false, 'required' => true],
        'loki_token' => ['name' => 'WATCHER_LOKI_TOKEN', 'purpose' => 'Read-only Loki or Grafana token (Viewer role).', 'secret' => true, 'required' => true],
        'dd_site' => ['name' => 'WATCHER_DD_SITE', 'purpose' => 'Datadog site, for example datadoghq.eu.', 'secret' => false, 'required' => false],
        'dd_api_key' => ['name' => 'WATCHER_DD_API_KEY', 'purpose' => 'Datadog API key.', 'secret' => true, 'required' => true],
        'dd_app_key' => ['name' => 'WATCHER_DD_APP_KEY', 'purpose' => 'Datadog application key with only the logs_read_data scope.', 'secret' => true, 'required' => true],
        'slack_webhook' => ['name' => 'WATCHER_SLACK_WEBHOOK', 'purpose' => 'Slack incoming webhook of the digest channel.', 'secret' => true, 'required' => true],
        'tg_token' => ['name' => 'WATCHER_TG_TOKEN', 'purpose' => 'Telegram bot token.', 'secret' => true, 'required' => true],
        'tg_chat' => ['name' => 'WATCHER_TG_CHAT', 'purpose' => 'Telegram chat id (negative for a supergroup) or @channel name.', 'secret' => false, 'required' => true],
        'discord_webhook' => ['name' => 'WATCHER_DISCORD_WEBHOOK', 'purpose' => 'Discord webhook of the digest channel.', 'secret' => true, 'required' => true],
        'redis_host' => ['name' => 'WATCHER_REDIS_HOST', 'purpose' => 'Redis host.', 'secret' => false, 'required' => false],
        'redis_port' => ['name' => 'WATCHER_REDIS_PORT', 'purpose' => 'Redis port.', 'secret' => false, 'required' => false],
        'redis_password' => ['name' => 'WATCHER_REDIS_PASSWORD', 'purpose' => 'Redis password, if the server needs one.', 'secret' => true, 'required' => false],
        'redis_database' => ['name' => 'WATCHER_REDIS_DATABASE', 'purpose' => 'Redis database index (give each environment its own).', 'secret' => false, 'required' => false],
        'db_dsn' => ['name' => 'WATCHER_DB_DSN', 'purpose' => 'PDO DSN of the state database.', 'secret' => true, 'required' => true],
        'db_username' => ['name' => 'WATCHER_DB_USERNAME', 'purpose' => 'State database user.', 'secret' => false, 'required' => false],
        'db_password' => ['name' => 'WATCHER_DB_PASSWORD', 'purpose' => 'State database password.', 'secret' => true, 'required' => false],
        'state_path' => ['name' => 'WATCHER_STATE_PATH', 'purpose' => 'Private directory for the file state store (single runner only).', 'secret' => false, 'required' => true],
    ];

    /** @var array<string, list<string>> */
    public const SOURCE_VARS = ['loki' => ['loki_url', 'loki_token'], 'datadog' => ['dd_site', 'dd_api_key', 'dd_app_key']];
    /** @var array<string, list<string>> */
    public const NOTIFIER_VARS = ['slack' => ['slack_webhook'], 'telegram' => ['tg_token', 'tg_chat'], 'discord' => ['discord_webhook']];
    /** @var array<string, list<string>> */
    public const STATE_VARS = ['file' => ['state_path'], 'pdo' => ['db_dsn', 'db_username', 'db_password'], 'redis' => ['redis_host', 'redis_port', 'redis_password', 'redis_database']];
    public const COMMON_VARS = ['enabled', 'gemini_key', 'model_triage', 'model_analyze', 'model_escalate'];

    /** The command to run `watcher <sub>` on a host; `<config>` is a placeholder for the path of the configuration file. */
    public static function command(string $host, string $sub): string
    {
        if ($host === 'laravel') {
            return 'php artisan watcher:' . $sub;
        }
        [$name, $flags] = array_pad(explode(' ', $sub, 2), 2, '');

        return trim('vendor/bin/watcher ' . $name . ' --config=<config> ' . $flags);
    }

    /**
     * @param list<string> $logical logical variable names
     * @param array<string, string> $names overrides (logical => WATCHER_* name)
     * @return list<array{name: string, purpose: string, secret: bool, required: bool}>
     */
    public static function vars(array $logical, array $names = []): array
    {
        $out = [];
        foreach ($logical as $l) {
            $v = self::ENV[$l];
            $out[] = ['name' => $names[$l] ?? $v['name']] + $v;
        }

        return $out;
    }
}
