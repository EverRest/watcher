<?php

declare(strict_types=1);

namespace Watcher\Config\Catalog;

use Watcher\Config\KeyCatalog;

/**
 * Catalog entries for the hourly host health check: health_check.* (the host's Redis snapshot, ttl, transition alerts).
 *
 * Entries are merged, in this order, into {@see KeyCatalog}; see its docblock for the fields.
 *
 * @phpstan-import-type Entry from KeyCatalog
 */
final class HealthKeys
{
    /** @var array<string, Entry> */
    public const ENTRIES = [
        'health_check.enabled' => ['path' => 'health_check.enabled', 'type' => 'bool', 'required' => false, 'default' => 'false', 'env' => null, 'secret' => false, 'summary' => 'Hourly check of the host health snapshot. Also needs the watcher enabled.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.source.host' => ['path' => 'health_check.source.host', 'type' => 'string', 'required' => true, 'default' => null, 'env' => null, 'secret' => false, 'summary' => 'Redis host of the host app (required when enabled).', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.source.port' => ['path' => 'health_check.source.port', 'type' => 'int', 'required' => false, 'default' => '6379', 'env' => null, 'secret' => false, 'summary' => 'Redis port.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.source.database' => ['path' => 'health_check.source.database', 'type' => 'int', 'required' => false, 'default' => '0', 'env' => null, 'secret' => false, 'summary' => 'Redis database index of the host app (read only).', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.source.username' => ['path' => 'health_check.source.username', 'type' => 'string', 'required' => false, 'default' => null, 'env' => null, 'secret' => false, 'summary' => 'Redis ACL user, when the host app uses one.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.source.password' => ['path' => 'health_check.source.password', 'type' => 'string', 'required' => false, 'default' => null, 'env' => null, 'secret' => true, 'summary' => 'Redis password of the host app. Masked everywhere.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.source.key' => ['path' => 'health_check.source.key', 'type' => 'string', 'required' => true, 'default' => null, 'env' => null, 'secret' => false, 'summary' => 'Full raw key of the snapshot, for example m-seq:health (no watcher prefix).', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.ttl_days' => ['path' => 'health_check.ttl_days', 'type' => 'int', 'required' => false, 'default' => '14', 'env' => null, 'secret' => false, 'summary' => '1..365 days a stored copy of a service lives; refreshed on every hourly write.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.notify_transitions' => ['path' => 'health_check.notify_transitions', 'type' => 'bool', 'required' => false, 'default' => 'false', 'env' => null, 'secret' => false, 'summary' => 'Also alert once when a service flips between up and down.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.summary_at' => ['path' => 'health_check.summary_at', 'type' => 'string', 'required' => false, 'default' => '09:00', 'env' => null, 'secret' => false, 'summary' => 'HH:MM in health_check.timezone: the hour of the daily summary of every service; null turns it off.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.timezone' => ['path' => 'health_check.timezone', 'type' => 'string', 'required' => false, 'default' => 'UTC', 'env' => null, 'secret' => false, 'summary' => 'IANA zone for summary_at, summary_hours and summary_days (for CET set Europe/Berlin). Independent of schedule.timezone.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.summary_hours' => ['path' => 'health_check.summary_hours', 'type' => 'string', 'required' => false, 'default' => '09-18', 'env' => null, 'secret' => false, 'summary' => 'HH-HH working hours (start inclusive, end exclusive, health_check.timezone) the summary may be sent in; null = all day.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
        'health_check.summary_days' => ['path' => 'health_check.summary_days', 'type' => 'list', 'required' => false, 'default' => 'mon..fri', 'env' => null, 'secret' => false, 'summary' => 'Days the summary may be sent on (mon..sun); null = every day.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'health-check']],
    ];
}
