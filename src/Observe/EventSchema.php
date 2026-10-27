<?php

declare(strict_types=1);

namespace Watcher\Observe;

/**
 * The code side of docs/schema/watcher.event.v2.schema.json: event types, field names in emission order, the closed
 * enumerations and the string patterns the sanitizer enforces. EventSchemaTest compares it with the schema file.
 */
final class EventSchema
{
    public const ID = 'watcher.event.v2';

    public const TYPES = ['run', 'finding', 'group', 'heartbeat', 'delivery_failure', 'config_warning'];

    public const ENVELOPE = ['schema', 'event_type', 'event_id', 'ts', 'service', 'environment', 'run_id', 'version', 'window_start', 'window_end'];

    /** Type-specific fields that are always allowed, in emission order. */
    public const FIELDS = [
        'run' => ['outcome', 'reason', 'dry_run', 'release', 'duration_ms', 'events_read', 'groups_total', 'candidates', 'findings_total', 'findings_by_severity', 'groups_by_trend', 'calls', 'escalations', 'reused', 'not_analysed', 'muted', 'truncated'],
        'finding' => ['finding_index', 'fingerprint', 'fingerprints', 'kind', 'severity', 'confidence', 'escalated', 'group_trend', 'group_count', 'group_previous_count', 'after_release'],
        'group' => ['rank', 'fingerprint', 'level', 'trend', 'count', 'previous_count', 'first_seen', 'last_seen', 'has_finding', 'watch_rules'],
        'heartbeat' => ['state', 'last_outcome', 'last_ok_at', 'age_seconds', 'stale_after_seconds', 'warnings'],
        'delivery_failure' => ['channel', 'kind', 'status_class', 'http_status'],
        'config_warning' => ['code', 'key', 'count'],
    ];

    /** Fields present only when the sink has include_text: field => longest allowed string (list fields: per entry). */
    public const TEXT = [
        'finding' => ['title' => 80, 'evidence' => 240, 'hypothesis' => 240, 'next_step' => 200, 'check' => 200, 'related' => 80],
        'group' => ['error_type' => 120],
    ];

    public const RELATED_MAX = 3;

    /** Fields holding a string that must be a member of a list; a key is `field` or, where types differ, `type.field`. */
    public const ENUMS = [
        'event_type' => self::TYPES,
        'outcome' => ['reported', 'quiet', 'baselined', 'failed', 'skipped_done', 'skipped_locked'],
        'finding.kind' => ['bug', 'regression', 'anomaly', 'noise'],
        'delivery_failure.kind' => ['digest', 'notice', 'weekly', 'other'],
        'severity' => ['info', 'warn', 'error', 'critical'],
        'group_trend' => ['new', 'grown', 'unchanged', 'expected'],
        'trend' => ['new', 'grown', 'unchanged', 'expected'],
        'level' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
        'heartbeat.state' => ['ok', 'degraded', 'stale', 'failed', 'unknown'],
        'channel' => ['slack', 'telegram', 'discord'],
        'status_class' => ['http_4xx', 'http_429', 'http_5xx', 'network', 'timeout', 'other'],
        'code' => ['include_text_external', 'http_insecure_allowed', 'event_cap_hit', 'group_cap_hit', 'comparison_gap', 'analysis_partial', 'check_test'],
    ];

    public const SEVERITIES = ['info', 'warn', 'error', 'critical'];

    public const TRENDS = ['new', 'grown', 'unchanged', 'expected'];

    public const OUTCOMES = ['reported', 'quiet', 'baselined', 'failed', 'skipped_done', 'skipped_locked'];

    /** Nested count objects of `run`: field => their closed keys. */
    public const COUNTS = [
        'findings_by_severity' => self::SEVERITIES,
        'groups_by_trend' => self::TRENDS,
        'calls' => ['triage', 'analyze', 'escalate'],
    ];

    public const SINKS = ['log', 'file', 'http', 'prometheus'];

    /** Fields holding a string that must match a pattern (null passes where the schema allows null). */
    public const PATTERNS = [
        'schema' => '/^watcher\.event\.v2$/D',
        'event_id' => '/^[0-9a-f]{16}-[1-9][0-9]{0,5}$/D',
        'ts' => self::TS,
        'window_start' => self::TS,
        'window_end' => self::TS,
        'first_seen' => self::TS,
        'last_seen' => self::TS,
        'last_ok_at' => self::TS,
        'service' => '/^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}$/D',
        'environment' => '/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,31}|unset)$/D',
        'run_id' => '/^[0-9a-f]{16}$/D',
        'version' => '/^[A-Za-z0-9._+-]{1,32}$/D',
        'reason' => '/^[A-Za-z0-9_.:()\\\\ -]{1,80}$/D',
        'release' => '/^[A-Za-z0-9._+-]{1,64}$/D',
        'fingerprint' => self::FP,
        'last_outcome' => '/^[a-z_]{1,24}$/D',
        'key' => '/^[a-z_]+(?:\.[a-z_]+){0,4}$/D',
    ];

    /** Entries of list fields: field => pattern. */
    public const LIST_PATTERNS = [
        'fingerprints' => self::FP,
        'watch_rules' => '/^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}$/D',
        'warnings' => '/^sink_failed:(?:log|file|http|prometheus)$/D',
    ];

    public const LIST_MAX = ['fingerprints' => 5, 'watch_rules' => 10, 'warnings' => 8];

    private const TS = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

    private const FP = '/^[0-9a-f]{12}$/D';

    /** @return list<string>|null the allowed values of a field, null when it is not an enumeration */
    public static function enum(string $type, string $field): ?array
    {
        return self::ENUMS[$type . '.' . $field] ?? self::ENUMS[$field] ?? null;
    }

    /** @return list<string> field names of an event type in emission order: envelope, fields, then the text fields if asked */
    public static function fields(string $type, bool $withText): array
    {
        return [...self::ENVELOPE, ...self::FIELDS[$type], ...($withText ? array_keys(self::TEXT[$type] ?? []) : [])];
    }
}
