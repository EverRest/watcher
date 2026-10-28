<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;
use Watcher\Config\SecureUrl;

/** Validates the `source` section (Loki or Datadog) and returns the normalised source. */
final class SourceSection
{
    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    public static function source(array $s): array
    {
        SectionFields::only($s, ['driver', 'loki', 'datadog', 'stream_tags', 'read_min_level', 'anomaly_cap', 'group_cap', 'event_cap', 'level_mode', 'max_response_mb'], 'source');
        $driver = SectionFields::str($s, 'driver', 'source.driver');
        if ($driver === 'loki') {
            $loki = SectionFields::section($s, 'loki');
            SectionFields::only($loki, ['url', 'auth', 'tenant', 'allow_insecure'], 'source.loki');
            $url = SectionFields::str($loki, 'url', 'source.loki.url');
            if (isset($loki['allow_insecure']) && !is_bool($loki['allow_insecure'])) {
                throw new InvalidConfig('source.loki.allow_insecure must be true or false');
            }
            if (($problem = SecureUrl::problem($url, ($loki['allow_insecure'] ?? false) === true)) !== null) {
                throw new InvalidConfig('source.loki.url ' . $problem);
            }
            $auth = $loki['auth'] ?? null;
            if ($auth !== null) {
                if (!is_array($auth) || !in_array($auth['type'] ?? null, ['bearer', 'basic'], true)) {
                    throw new InvalidConfig('source.loki.auth.type must be bearer or basic');
                }
                SectionFields::only($auth, ['type', 'token', 'user', 'password'], 'source.loki.auth');
                $need = $auth['type'] === 'bearer' ? ['token'] : ['user', 'password'];
                foreach ($need as $k) {
                    SectionFields::str($auth, $k, 'source.loki.auth.' . $k);
                }
            }
        } elseif ($driver === 'datadog') {
            $dd = SectionFields::section($s, 'datadog');
            SectionFields::only($dd, ['site', 'api_key', 'app_key', 'newest_first'], 'source.datadog');
            if (isset($dd['newest_first']) && !is_bool($dd['newest_first'])) {
                throw new InvalidConfig('source.datadog.newest_first must be true or false');
            }
            SectionFields::str($dd, 'api_key', 'source.datadog.api_key');
            SectionFields::str($dd, 'app_key', 'source.datadog.app_key');
            if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/iD', SectionFields::optStr($dd, 'site', 'datadoghq.com', 'source.datadog.site')) !== 1) {
                throw new InvalidConfig('source.datadog.site must be a Datadog site host such as datadoghq.eu');
            }
        } else {
            throw new InvalidConfig('source.driver must be loki or datadog');
        }

        $tags = $s['stream_tags'] ?? [];
        if (!is_array($tags)) {
            throw new InvalidConfig('source.stream_tags must be a map');
        }

        return array_merge($s, [
            'read_min_level' => SectionFields::level($s, 'read_min_level', 'warning', 'source.read_min_level'),
            'anomaly_cap' => SectionFields::posInt($s, 'anomaly_cap', 20000, 'source.anomaly_cap'),
            'group_cap' => SectionFields::posInt($s, 'group_cap', 5000, 'source.group_cap'),
            'event_cap' => SectionFields::posInt($s, 'event_cap', 200000, 'source.event_cap'),
            'max_response_mb' => SectionFields::maxResponseMb($s),
            'level_mode' => self::levelMode($s),
            'stream_tags' => $tags,
        ]);
    }

    /** @param array<string, mixed> $s */
    public static function levelMode(array $s): string
    {
        $v = $s['level_mode'] ?? 'text';
        if (!in_array($v, ['label', 'text', 'all'], true)) {
            throw new InvalidConfig('source.level_mode must be label, text or all');
        }

        return $v;
    }
}
