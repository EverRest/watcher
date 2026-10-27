<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * The `docs` group's resources and prompts (FR-090..092). Four static prompts: enumeration arguments are substituted after validation,
 * no data from state, logs or files enters a text. A prompt is listed only when every tool it names (except the optional dry run) is enabled.
 */
final class PromptCatalog implements DocsEndpoint
{
    public const STANDING = 'Never ask the user for a secret value; ask for the name of the environment variable.';

    private const HOSTS = ['laravel', 'plain_php', 'kubernetes_cronjob'];
    private const SOURCES = ['loki', 'datadog'];
    private const NOTIFIERS = ['slack', 'telegram', 'discord'];
    private const STATES = ['file', 'pdo', 'redis'];
    private const WHICH = ['slack', 'telegram', 'discord', 'loki', 'datadog', 'anthropic', 'redis', 'pdo'];

    /** @var array<string, array{description: string, args: array<string, array{values: list<string>, default: string|null}>, tools: list<string>}> */
    private const PROMPTS = [
        'onboard-new-service' => ['description' => 'Set up the watcher for a new service step by step, without ever handling a secret.', 'args' => ['host' => ['values' => self::HOSTS, 'default' => 'laravel'], 'source' => ['values' => self::SOURCES, 'default' => 'loki'], 'notifier' => ['values' => self::NOTIFIERS, 'default' => 'slack'], 'state' => ['values' => self::STATES, 'default' => 'redis']], 'tools' => ['watcher_setup_plan', 'watcher_generate_config', 'watcher_validate_config', 'watcher_rollout_check', 'watcher_first_day', 'watcher_explain_outcome']],
        'why-is-the-digest-quiet' => ['description' => 'Find out why no digest arrived today.', 'args' => [], 'tools' => ['watcher_status', 'watcher_check', 'watcher_latest_report', 'watcher_explain_outcome', 'watcher_explain_config']],
        'rotate-credentials' => ['description' => 'Rotate one credential of the watcher safely.', 'args' => ['which' => ['values' => self::WHICH, 'default' => null]], 'tools' => ['watcher_explain_config', 'watcher_check', 'watcher_status']],
    ];

    /** @param list<string> $enabledTools names of the tools the registry serves */
    public function __construct(private readonly DocsIndex $index, private readonly array $enabledTools, private readonly SecretGuard $guard)
    {
    }

    public function resources(): array
    {
        return $this->index->resources();
    }

    public function read(string $uri): array
    {
        return $this->index->read($uri);
    }

    public function prompts(): array
    {
        $out = [];
        foreach (self::PROMPTS as $name => $p) {
            if ($this->listed($p['tools'])) {
                $args = [];
                foreach ($p['args'] as $a => $spec) {
                    $args[] = ['name' => $a, 'description' => 'One of: ' . implode(', ', $spec['values']), 'required' => $spec['default'] === null];
                }
                $out[] = ['name' => $name, 'description' => $p['description'], 'arguments' => $args];
            }
        }

        return $out;
    }

    public function prompt(string $name, array $arguments): array
    {
        $p = self::PROMPTS[$name] ?? null;
        if ($p === null || !$this->listed($p['tools'])) {
            throw new InvalidParams('invalid params: name');
        }
        try {
            $this->guard->assertClean($arguments);
        } catch (SecretRefused) {
            throw new InvalidParams(SecretRefused::MESSAGE);
        }
        $values = [];
        foreach ($p['args'] as $a => $spec) {
            $v = $arguments[$a] ?? $spec['default'];
            if (!is_string($v) || !in_array($v, $spec['values'], true)) {
                throw new InvalidParams('invalid params: ' . $a);
            }
            $values[$a] = $v;
        }
        foreach (array_keys($arguments) as $k) {
            if (!isset($p['args'][$k])) {
                throw new InvalidParams('invalid params');
            }
        }

        return ['description' => $p['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $this->text($name, $values)]]]];
    }

    /** @param list<string> $tools */
    private function listed(array $tools): bool
    {
        foreach ($tools as $t) {
            if ($t !== 'watcher_dry_run' && !in_array($t, $this->enabledTools, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $v */
    private function text(string $name, array $v): string
    {
        $dry = in_array('watcher_dry_run', $this->enabledTools, true);
        $body = match ($name) {
            'onboard-new-service' => "Help me set up the watcher for a new service ({$v['host']} host, {$v['source']} logs, {$v['notifier']} notifications, {$v['state']} state).\n"
                . "1. Call watcher_setup_plan with these four choices and walk me through the steps in order.\n"
                . "2. Ask me only for non-secret answers (service name, run time, time zone, labels), then call watcher_generate_config.\n"
                . "3. After I save the file, call watcher_validate_config on it and watcher_rollout_check.\n"
                . "4. Call watcher_first_day and guide me through the first-day procedure; use watcher_explain_outcome for any outcome I do not understand.",
            'why-is-the-digest-quiet' => "No digest arrived and I want to know why.\n1. Call watcher_status.\n2. Call watcher_check.\n3. Call watcher_latest_report.\n"
                . "4. Use watcher_explain_outcome for the outcome or reason you find, and watcher_explain_config for any key involved.",
            default => "I need to rotate the {$v['which']} credential.\n1. Call watcher_explain_config for the keys that hold it; they name the environment variable.\n2. Tell me to create the new credential and store it in the secret store under the same variable name; do not ask me to paste it.\n3. After I redeploy, call watcher_check and watcher_status. Treat the old credential as exposed and revoke it.",
        };

        return $body . "\n\n" . self::STANDING;
    }
}
