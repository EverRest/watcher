# Contract: configuration

All keys optional; every default equals today's behaviour. Flags follow spec 012 (real booleans; `WATCHER_*` variables read
with `Watcher\Config\Env`). Errors name the key path, never the value.

## `known_issues` (list) and flag

| Key | Type | Default | Rule |
|---|---|---|---|
| `known_issues.enabled` | bool | true (inert with no entries) | `WATCHER_KNOWN_ISSUES_ENABLED`; false = no read, no state write, no line |
| `known_issues.entries` | list | `[]` | each: |
| `.fingerprint` | string | required | `^[0-9a-f]{12,64}$`; a group matches when its fingerprint starts with it (the digest prints 12 characters; `mute[].fingerprint` is an exact full match and is not reused) |
| `.ticket` | string | required | `^[A-Za-z0-9][A-Za-z0-9._#-]{0,39}$`; no URL, no whitespace |
| `.status` | `open`\|`resolved` | `open` | |
| `.until` | `YYYY-MM-DD` | none | UTC, inclusive (as `mute`) |

Entries: at most 200; the same `(fingerprint, ticket)` twice is rejected. Entries are list-form in a plain config
(`known_issues => ['entries' => [...]]`); a list of entries only is also accepted and means enabled.
Environment: `WATCHER_KNOWN_ISSUES` is **not** provided for the entries (fingerprints belong in versioned config).

## `drafts`

| Key | Type | Default | Rule |
|---|---|---|---|
| `drafts.enabled` | bool | **false** | `WATCHER_DRAFTS_ENABLED` |
| `drafts.min_severity` | `info\|warn\|error\|critical` | `error` | `WATCHER_DRAFTS_MIN_SEVERITY` |
| `drafts.max_per_run` | int 1..20 | 5 | `WATCHER_DRAFTS_MAX_PER_RUN` |
| `drafts.destination` | destination block | the notice destination | same shape and validation as `notify.digest` |

Enabled with no `drafts.destination` and `notify.notice.enabled` false (spec 012): rejected (`drafts.destination required`). Drafts
are also skipped when `notify.digest.enabled` is false (no analysis, no findings). Drafts and the report never send to a
disabled destination.

## `notify.routes`

```php
'notify' => [
    'digest' => [...],
    'routes' => [
        ['name' => 'oncall', 'severity' => ['critical', 'error'], 'to' => ['driver' => 'telegram', 'bot_token' => ..., 'chat_id' => ...]],
    ],
],
```

| Key | Rule |
|---|---|
| `name` | `^[a-z0-9][a-z0-9_-]{0,31}$`, unique; shown in the digest line |
| `severity` | non-empty list of severities, no value in two routes |
| `to` | destination block as `notify.digest`; must differ from the digest destination and from other routes (`sameAs`) |

At most 5 routes. Empty or absent = unchanged. `WatchConfig::notify()` currently accepts only `digest` and `notice`; `routes`
and `routes_enabled` are added to that list. Flag (spec 012): `notify.routes_enabled` (`WATCHER_ROUTES_ENABLED`, default true,
inert without routes). Routes are inert when `notify.digest.enabled` is false (no digest, nothing to split). Secrets in `to` are masked everywhere (spec 011 FR-011). No env form for
`routes` (secrets come from `WATCHER_*` variables read inside the destination block).

## `report.weekly`

| Key | Type | Default | Rule |
|---|---|---|---|
| `report.weekly.enabled` | bool | **false** | `WATCHER_REPORT_WEEKLY_ENABLED`; true requires `observe.file.enabled` and `observe.file.retention_days >= 8` |
| `report.weekly.destination` | destination block | the notice destination | |
| `report.weekly.top` | int 3..10 | 5 | list length for recurring, stopped, noisiest |

## Validation messages (exact)

`known_issues.entries[2].ticket invalid` · `known_issues.entries[1].fingerprint must be 12 to 64 hex characters` · `known_issues.entries[0].status must be open or resolved` ·
`drafts.destination required` · `notify.routes[1].to must differ from notify.digest` ·
`notify.routes[0].severity overlaps notify.routes[1]` · `report.weekly needs observe.file enabled with retention_days >= 8`.

## CLI

`watcher report [--week=YYYY-Www] [--dry-run] [--json]` (fleet: `fleet:report`). Exit 0 sent / already sent / no runs;
non-zero on invalid config or unreadable event file. `run` gains no new flag; `--dry-run` prints drafts.
