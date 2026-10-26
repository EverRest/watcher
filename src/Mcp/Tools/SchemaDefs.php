<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

/** The enumerations the onboarding tool schemas share (the `$defs` of contracts/tools.schema.json, resolved). */
final class SchemaDefs
{
    /** @return array{enum: list<string>} */
    public static function host(): array
    {
        return ['enum' => ['laravel', 'plain_php', 'kubernetes_cronjob']];
    }

    /** @return array{enum: list<string>} */
    public static function source(): array
    {
        return ['enum' => ['loki', 'datadog']];
    }

    /** @return array{enum: list<string>} */
    public static function notifier(): array
    {
        return ['enum' => ['slack', 'telegram', 'discord']];
    }

    /** @return array{enum: list<string>} */
    public static function state(): array
    {
        return ['enum' => ['file', 'pdo', 'redis']];
    }
}
