<?php

declare(strict_types=1);

namespace Watcher\Config\Catalog;

use Watcher\Config\KeyCatalog;

/**
 * Catalog entries for the certificate and secret expiry probes: probes.* and schedule.probe_every.
 *
 * Entries are merged, in this order, into {@see KeyCatalog}; see its docblock for the fields.
 *
 * @phpstan-import-type Entry from KeyCatalog
 */
final class ProbeKeys
{
    /** @var array<string, Entry> */
    public const ENTRIES = [
        'schedule.probe_every' => ['path' => 'schedule.probe_every', 'type' => 'string', 'required' => false, 'default' => 'PT1H', 'env' => 'WATCHER_PROBE_EVERY', 'secret' => false, 'summary' => 'ISO-8601 duration, at least PT5M: the interval of the probes. They run on the first run trigger after it has passed, so a host that triggers less often runs them less often.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'probes-certificate-and-secret-expiry']],
        'probes.enabled' => ['path' => 'probes.enabled', 'type' => 'bool', 'required' => false, 'default' => 'false', 'env' => 'WATCHER_PROBES_ENABLED', 'secret' => false, 'summary' => 'Switch for the probes section; off reads, stores and sends nothing.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'probes-certificate-and-secret-expiry']],
        'probes.warn_days' => ['path' => 'probes.warn_days', 'type' => 'int', 'required' => false, 'default' => '30', 'env' => 'WATCHER_PROBES_WARN_DAYS', 'secret' => false, 'summary' => 'Warn at most this many days before a certificate or secret expires.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'probes-certificate-and-secret-expiry']],
        'probes.fail_days' => ['path' => 'probes.fail_days', 'type' => 'int', 'required' => false, 'default' => '7', 'env' => 'WATCHER_PROBES_FAIL_DAYS', 'secret' => false, 'summary' => 'Fail at most this many days before expiry; must be below probes.warn_days.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'probes-certificate-and-secret-expiry']],
        'probes.hosts' => ['path' => 'probes.hosts', 'type' => 'list', 'required' => false, 'default' => '[]', 'env' => 'WATCHER_PROBES_HOSTS', 'secret' => false, 'summary' => 'Host names whose TLS certificate is read on port 443 (verification off, only the date is read).', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'probes-certificate-and-secret-expiry']],
        'probes.secrets' => ['path' => 'probes.secrets', 'type' => 'list', 'required' => false, 'default' => '[]', 'env' => null, 'secret' => false, 'summary' => 'Declared secrets: name, expires (YYYY-MM-DD, UTC, valid through that day) and owner. Only dates are compared; no secret value is read.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'probes-certificate-and-secret-expiry']],
    ];
}
