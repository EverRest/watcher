<?php

declare(strict_types=1);

namespace Watcher\Config;

/**
 * Builds the destination, price and stream-tag sections of the publishable config from environment variables, for hosts that
 * keep no config file of their own (docs/configuration.md, "Environment only").
 *
 * It never throws: a config file runs on every request of the host application, so a missing or broken variable must not take
 * the whole application down. Each problem is recorded instead (kind `missing` or `invalid`, the variable, the config key, a
 * message that names the variable and never its value) and `WatchConfig::fromArray()` raises it when `watcher:run` or
 * `watcher:check` starts.
 */
final class EnvSections
{
    private const TIERS = ['triage', 'analyze', 'escalate'];

    /** @var list<array{kind: string, variable: string, key: string, message: string, unless: list<list<string>>}> */
    private array $problems = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $resolved = [];

    /**
     * `unless` lists config paths (as lists of segments: a model id may contain a dot). A host that keeps part of its own config and has
     * set one of them makes the problem stale, and it is dropped. An empty list means the host can never settle it.
     *
     * @return list<array{kind: string, variable: string, key: string, message: string, unless: list<list<string>>}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * `DIGEST` always yields an entry (slack unless a bot token is set). `NOTICE` yields null while no WATCHER_NOTICE_* variable
     * is set (the notice then uses the digest destination); otherwise it is the digest destination with the notice variables laid over it.
     *
     * @return array<string, mixed>|null
     */
    public function destination(string $name): ?array
    {
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name];
        }

        return $this->resolved[$name] = $name === 'NOTICE' ? $this->notice() : $this->digest();
    }

    /**
     * @param array<string, string|null> $models tier => model id
     * @return array<string, array{float, float, float, float}> model id => [input, output, cache read, cache write], USD per million tokens
     */
    public function prices(array $models): array
    {
        $out = [];
        $owner = [];
        foreach (self::TIERS as $tier) {
            $model = $models[$tier] ?? null;
            if (!is_string($model) || $model === '') {
                continue;
            }
            $t = strtoupper($tier);
            $in = $this->price("WATCHER_PRICE_{$t}_IN", true, $model);
            $outPrice = $this->price("WATCHER_PRICE_{$t}_OUT", true, $model);
            if ($in === null || $outPrice === null) {
                continue;
            }
            $entry = [
                $in, $outPrice,
                $this->price("WATCHER_PRICE_{$t}_CACHE_READ", false, $model) ?? 0.0,
                $this->price("WATCHER_PRICE_{$t}_CACHE_WRITE", false, $model) ?? 0.0,
            ];
            if (isset($out[$model]) && $out[$model] !== $entry) {
                $this->invalid(
                    "WATCHER_PRICE_{$t}_IN",
                    "analysis.prices.$model",
                    "WATCHER_PRICE_{$t}_IN conflicts with {$owner[$model]}: one model id cannot have two prices",
                );
                continue;
            }
            $out[$model] = $entry;
            $owner[$model] ??= "WATCHER_PRICE_{$t}_IN";
        }

        return $out;
    }

    /** @return array<string, string> `WATCHER_STREAM_TAGS="app=m-seq,env=test11"` as label => value */
    public function streamTags(): array
    {
        $raw = Env::string('WATCHER_STREAM_TAGS');
        if ($raw === null) {
            return [];
        }
        $tags = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.+)$/D', $item, $m) !== 1) {
                $this->invalid('WATCHER_STREAM_TAGS', 'source.stream_tags', 'WATCHER_STREAM_TAGS must be a comma separated list of label=value pairs', [['source', 'stream_tags']]);

                return [];
            }
            $tags[$m[1]] = trim($m[2]);
        }

        return $tags;
    }

    /** @return array<string, mixed> */
    private function digest(): array
    {
        $driver = Env::string('WATCHER_DIGEST_DRIVER');
        $webhook = Env::string('WATCHER_DIGEST_WEBHOOK') ?? Env::string('WATCHER_SLACK_WEBHOOK');
        if ($driver === null) {
            if (Env::string('WATCHER_DIGEST_BOT_TOKEN') !== null) {
                $driver = 'telegram';
            } elseif ($webhook !== null) {
                $driver = 'slack';
            } else {
                $this->missing('WATCHER_DIGEST_BOT_TOKEN', 'notify.digest', 'WATCHER_DIGEST_BOT_TOKEN (Telegram) or WATCHER_DIGEST_WEBHOOK (Slack, Discord) is not set', [['notify', 'digest', 'webhook'], ['notify', 'digest', 'bot_token']]);

                return ['driver' => 'slack'];
            }
        }

        return $this->build('DIGEST', $driver, $webhook, null, true);
    }

    /** @return array<string, mixed>|null */
    private function notice(): ?array
    {
        $names = ['DRIVER', 'WEBHOOK', 'BOT_TOKEN', 'CHAT_ID', 'THREAD_ID'];
        $any = false;
        foreach ($names as $n) {
            $any = $any || Env::string("WATCHER_NOTICE_$n") !== null;
        }
        if (!$any) {
            return null;
        }
        $digest = $this->destination('DIGEST') ?? ['driver' => 'slack'];
        $driver = Env::string('WATCHER_NOTICE_DRIVER')
            ?? (Env::string('WATCHER_NOTICE_BOT_TOKEN') !== null ? 'telegram' : (string) $digest['driver']);
        $same = $driver === $digest['driver'];

        return $this->build('NOTICE', $driver, Env::string('WATCHER_NOTICE_WEBHOOK'), $same ? $digest : null, !$same);
    }

    /**
     * @param array<string, mixed>|null $inherit the digest entry of the same driver (notice only)
     * @return array<string, mixed>
     */
    private function build(string $name, string $driver, ?string $webhook, ?array $inherit, bool $checkMissing): array
    {
        $key = 'notify.' . strtolower($name);
        if ($driver === 'none') {
            return ['driver' => 'none'];
        }
        if ($driver === 'telegram') {
            $token = Env::string("WATCHER_{$name}_BOT_TOKEN") ?? (is_string($inherit['bot_token'] ?? null) ? $inherit['bot_token'] : null);
            $chat = Env::string("WATCHER_{$name}_CHAT_ID") ?? (is_string($inherit['chat_id'] ?? null) ? $inherit['chat_id'] : null);
            if ($token === null && $checkMissing) {
                $this->missing("WATCHER_{$name}_BOT_TOKEN", "$key.bot_token", "WATCHER_{$name}_BOT_TOKEN is not set ($key.bot_token)", [['notify', strtolower($name), 'bot_token']]);
            }
            if ($chat === null && $checkMissing) {
                $this->missing("WATCHER_{$name}_CHAT_ID", "$key.chat_id", "WATCHER_{$name}_CHAT_ID is not set ($key.chat_id)", [['notify', strtolower($name), 'chat_id']]);
            }
            $out = ['driver' => 'telegram'];
            if ($token !== null) {
                $out['bot_token'] = $token;
            }
            if ($chat !== null) {
                $out['chat_id'] = $chat;
            }
            $thread = Env::string("WATCHER_{$name}_THREAD_ID");
            if ($thread !== null) {
                if (preg_match('/^[1-9][0-9]{0,9}$/D', $thread) !== 1) {
                    $this->invalid("WATCHER_{$name}_THREAD_ID", "$key.thread_id", "WATCHER_{$name}_THREAD_ID must be a positive integer (the id of a Telegram topic)", [['notify', strtolower($name), 'thread_id']]);
                } else {
                    $out['thread_id'] = (int) $thread;
                }
            }

            return $out;
        }
        if ($driver !== 'slack' && $driver !== 'discord') {
            $this->invalid("WATCHER_{$name}_DRIVER", "$key.driver", "WATCHER_{$name}_DRIVER must be one of slack, telegram, discord, none");

            return ['driver' => 'slack'];
        }
        if (Env::string("WATCHER_{$name}_THREAD_ID") !== null) {
            $this->invalid("WATCHER_{$name}_THREAD_ID", "$key.thread_id", "WATCHER_{$name}_THREAD_ID needs a telegram destination (set WATCHER_{$name}_BOT_TOKEN and WATCHER_{$name}_CHAT_ID, or send the digest to telegram)");
        }
        $webhook ??= is_string($inherit['webhook'] ?? null) ? $inherit['webhook'] : null;
        if ($webhook === null && $checkMissing) {
            $this->missing("WATCHER_{$name}_WEBHOOK", "$key.webhook", "WATCHER_{$name}_WEBHOOK is not set ($key.webhook)", [['notify', strtolower($name), 'webhook']]);
        }

        return $webhook === null ? ['driver' => $driver] : ['driver' => $driver, 'webhook' => $webhook];
    }

    private function price(string $variable, bool $required, string $model): ?float
    {
        $key = "analysis.prices.$model";
        $raw = Env::string($variable);
        if ($raw === null) {
            if ($required) {
                $this->missing($variable, $key, "$variable is not set ($key)", [['analysis', 'prices', $model]]);
            }

            return null;
        }
        if (!is_numeric(trim($raw)) || (float) $raw < 0) {
            $this->invalid($variable, $key, "$variable must be a number of at least 0 (USD per million tokens)", [['analysis', 'prices', $model]]);

            return null;
        }

        return (float) $raw;
    }

    /** @param list<list<string>> $unless */
    private function missing(string $variable, string $key, string $message, array $unless = []): void
    {
        $this->problems[] = ['kind' => 'missing', 'variable' => $variable, 'key' => $key, 'message' => $message, 'unless' => $unless];
    }

    /** @param list<list<string>> $unless */
    private function invalid(string $variable, string $key, string $message, array $unless = []): void
    {
        $this->problems[] = ['kind' => 'invalid', 'variable' => $variable, 'key' => $key, 'message' => $message, 'unless' => $unless];
    }
}
