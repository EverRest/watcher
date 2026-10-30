<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\Credentials;
use Watcher\Config\InvalidConfig;
use Watcher\Notify\Destination;

/** Validates the `notify` section and the destinations it routes to. */
final class NotifySection
{
    /**
     * @param array<string, mixed> $n
     * @return array<string, mixed>
     */
    public static function notify(array $n): array
    {
        SectionFields::only($n, ['digest', 'notice', 'routes', 'routes_enabled'], 'notify');
        $digest = self::notifyEntry(self::withoutEnabled($n['digest'] ?? null), 'notify.digest');
        $notice = $n['notice'] ?? null;
        $notice = self::withoutEnabled($notice);
        $notice = $notice === null || $notice === [] ? $digest : self::notifyEntry($notice, 'notify.notice');
        $routes = $n['routes'] ?? [];
        if (!is_array($routes) || ($routes !== [] && !array_is_list($routes))) {
            throw new InvalidConfig('notify.routes must be a list');
        }
        $routesEnabled = $n['routes_enabled'] ?? true;
        if (!is_bool($routesEnabled)) {
            throw new InvalidConfig('notify.routes_enabled must be true or false');
        }

        return ['digest' => $digest, 'notice' => $notice, 'routes' => $routes, 'routes_enabled' => $routesEnabled];
    }

    public static function withoutEnabled(mixed $entry): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }
        unset($entry['enabled']);

        return $entry;
    }

    /**
     * One validated `notify.<kind>` entry; every message names the key path, never a value. Public for the fleet.
     *
     * @return array<string, mixed>
     */
    public static function notifyEntry(mixed $d, string $path): array
    {
        $keys = ['slack' => ['webhook', 'allow_insecure'], 'telegram' => ['bot_token', 'chat_id', 'thread_id'], 'discord' => ['webhook'], 'none' => []];
        if (!is_array($d)) {
            throw new InvalidConfig($path . '.webhook is required');
        }
        $driver = $d['driver'] ?? 'slack';
        if (!is_string($driver)) {
            throw new InvalidConfig($path . '.driver must be a string');
        }
        if (!isset($keys[$driver])) {
            throw new InvalidConfig($path . '.driver must be one of slack, telegram, discord, none');
        }
        if ($driver === 'none') {
            // A config file lists `webhook => env(...)` for every driver; unset values (null, '', false from getenv) are not a setting.
            $d = array_filter($d, static fn (mixed $v, int|string $k): bool => $k === 'driver' || !in_array($v, [null, '', false], true), ARRAY_FILTER_USE_BOTH);
        }
        foreach (array_keys($d) as $key) {
            if ($key === 'driver' || in_array($key, $keys[$driver], true)) {
                continue;
            }
            if (in_array($key, array_merge(...array_values($keys)), true)) {
                throw new InvalidConfig($path . '.' . $key . ' is not valid for driver ' . $driver);
            }
            throw new InvalidConfig('Unknown configuration key: ' . $path . '.' . $key);
        }
        if ($driver === 'none') {
            return ['driver' => 'none']; // dashboard-only: nothing to deliver, nothing to validate
        }
        if ($driver !== 'telegram') {
            $out = ['driver' => $driver, 'webhook' => SectionFields::str($d, 'webhook', $path . '.webhook')];
            if (isset($d['allow_insecure'])) {
                if (!is_bool($d['allow_insecure'])) {
                    throw new InvalidConfig($path . '.allow_insecure must be true or false');
                }
                $out['allow_insecure'] = $d['allow_insecure'];
            }

            return $out;
        }
        $out = ['driver' => $driver, 'bot_token' => SectionFields::str($d, 'bot_token', $path . '.bot_token')];
        $chat = $d['chat_id'] ?? null;
        if ($chat !== null && !is_int($chat) && !is_string($chat)) {
            throw new InvalidConfig($path . '.chat_id must be an integer or a string');
        }
        if ($chat === null || $chat === '') {
            throw new InvalidConfig($path . '.chat_id is required');
        }
        $out['chat_id'] = (string) $chat;
        if (isset($d['thread_id'])) {
            if (!is_int($d['thread_id']) || $d['thread_id'] < 1) {
                throw new InvalidConfig($path . '.thread_id must be a positive integer');
            }
            $out['thread_id'] = $d['thread_id'];
        }

        return $out;
    }

    /** @param array<string, mixed> $entry a validated entry, see {@see notifyEntry()} */
    public static function destinationFrom(array $entry): Destination
    {
        return match ($entry['driver']) {
            'none' => Destination::none(),
            'telegram' => Destination::telegram(Credentials::reveal($entry['bot_token']), $entry['chat_id'], $entry['thread_id'] ?? null),
            'discord' => Destination::discord(Credentials::reveal($entry['webhook'])),
            default => Destination::slack(Credentials::reveal($entry['webhook']), ($entry['allow_insecure'] ?? false) === true),
        };
    }
}
