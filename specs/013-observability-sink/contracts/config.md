# Contract: configuration

Same pattern as spec 012: every key is settable in the config array and through a `WATCHER_*` variable read with
`Watcher\Config\Env` in the shipped config files. **Every sink is off by default** and is switched on by its own
`enabled` flag; with all sinks off the package behaves as before this feature. The master switch `enabled` and
`environment` of spec 012 are unchanged: `enabled` false means no sink is built, touched or written, not even a
heartbeat event. Flags must be real booleans in an array config (`'false'` is rejected naming the key path).

## `notify.digest.driver` (extended)

| Value | Meaning | Env |
|---|---|---|
| `slack` (default), `telegram`, `discord` | as spec 011 | `WATCHER_DIGEST_DRIVER` |
| `none` | Dashboard-only mode: the notifier accepts and drops every message (digest, notices, weekly line). No other key is read or allowed under `notify.digest` (`notify.digest.webhook is not valid for driver none`). `notify.notice` inherits it when absent. Needs at least one enabled sink. | |

`notify.digest.enabled=false` is not dashboard-only mode: it skips the analysis (spec 012). Use driver `none`.

## `observe`

| Key | Default | Env | Notes |
|---|---|---|---|
| `observe.top_groups` | `20` | `WATCHER_OBSERVE_TOP_GROUPS` | integer 0..100; `0` emits no `group` events |
| `observe.log.enabled` | **false** | `WATCHER_OBSERVE_LOG_ENABLED` | |
| `observe.log.stream` | `stderr` | `WATCHER_OBSERVE_LOG_STREAM` | `stderr` or `stdout`; with `--json` stdout is forced to stderr for that call |
| `observe.log.include_text` | **true** | `WATCHER_OBSERVE_LOG_INCLUDE_TEXT` | the host's own log pipeline; see research R4 |
| `observe.file.enabled` | **false** | `WATCHER_OBSERVE_FILE_ENABLED` | |
| `observe.file.path` | required when enabled | `WATCHER_OBSERVE_FILE_PATH` | absolute path of the active file, no `..`, not a symlink |
| `observe.file.rotate` | `size` | `WATCHER_OBSERVE_FILE_ROTATE` | `size` or `daily` |
| `observe.file.max_bytes` | `10485760` | `WATCHER_OBSERVE_FILE_MAX_BYTES` | for `size`; 1 MiB..1 GiB |
| `observe.file.max_files` | `10` | `WATCHER_OBSERVE_FILE_MAX_FILES` | rotated files kept, 1..100 |
| `observe.file.retention_days` | `30` | `WATCHER_OBSERVE_FILE_RETENTION_DAYS` | files older than this are deleted at each flush; 1..365 (Principle I) |
| `observe.file.include_text` | **false** | `WATCHER_OBSERVE_FILE_INCLUDE_TEXT` | |
| `observe.http.enabled` | **false** | `WATCHER_OBSERVE_HTTP_ENABLED` | |
| `observe.http.url` | required when enabled | `WATCHER_OBSERVE_HTTP_URL` | `https`, host, no userinfo (FR-017); a secret: masked everywhere |
| `observe.http.auth_header` | `Authorization` | `WATCHER_OBSERVE_HTTP_AUTH_HEADER` | header name, `[A-Za-z0-9-]{1,64}` |
| `observe.http.auth_scheme` | `Bearer` | `WATCHER_OBSERVE_HTTP_AUTH_SCHEME` | word placed before the token; empty = send the token alone (for `X-API-Key`-style headers) |
| `observe.http.auth_token` | none | `WATCHER_OBSERVE_HTTP_AUTH_TOKEN` | secret; no header is sent when absent; at least 8 characters |
| `observe.http.batch_size` | `100` | `WATCHER_OBSERVE_HTTP_BATCH_SIZE` | events per POST, 1..1000 |
| `observe.http.timeout` | `5` | `WATCHER_OBSERVE_HTTP_TIMEOUT` | seconds per request, 1..30 |
| `observe.http.allowed_hosts` | `[]` | `WATCHER_OBSERVE_HTTP_ALLOWED_HOSTS` (comma list) | when non-empty the URL host must be in it (exact, lowercase) |
| `observe.http.allow_insecure` | false | `WATCHER_OBSERVE_HTTP_ALLOW_INSECURE` | permits `http://`; emits `config_warning http_insecure_allowed` |
| `observe.http.allow_private_network` | false | `WATCHER_OBSERVE_HTTP_ALLOW_PRIVATE_NETWORK` | permits loopback, RFC 1918, ULA and link-local literal addresses and `localhost`; metadata addresses stay forbidden |
| `observe.http.include_text` | **false** | `WATCHER_OBSERVE_HTTP_INCLUDE_TEXT` | true emits `config_warning include_text_external` |
| `observe.prometheus.enabled` | **false** | `WATCHER_OBSERVE_PROMETHEUS_ENABLED` | |
| `observe.prometheus.dir` | required when enabled | `WATCHER_OBSERVE_PROMETHEUS_DIR` | absolute; node_exporter's `--collector.textfile.directory`; must exist and be writable (checked by `check` and at flush) |
| `observe.prometheus.name` | `watcher_<service>[_<environment>]` | `WATCHER_OBSERVE_PROMETHEUS_NAME` | file stem, `[a-z0-9_]{1,64}`; fleet: omit so each service gets its own |
| `observe.prometheus.file_mode` | `0644` | `WATCHER_OBSERVE_PROMETHEUS_FILE_MODE` | octal string; `0600`..`0664` |
| `observe.otlp` | reserved | | rejected: `observe.otlp is not available yet` |

There is no `include_text` for `prometheus` (no text) and no flag that disables the sanitizer (FR-045).

Config array shape:

```php
'notify' => ['digest' => ['driver' => Env::string('WATCHER_DIGEST_DRIVER', 'slack')]],
'observe' => [
    'top_groups' => Env::int('WATCHER_OBSERVE_TOP_GROUPS', 20),
    'log'        => ['enabled' => Env::bool('WATCHER_OBSERVE_LOG_ENABLED', false), 'stream' => 'stderr', 'include_text' => true],
    'file'       => ['enabled' => Env::bool('WATCHER_OBSERVE_FILE_ENABLED', false), 'path' => Env::string('WATCHER_OBSERVE_FILE_PATH')],
    'http'       => ['enabled' => Env::bool('WATCHER_OBSERVE_HTTP_ENABLED', false), 'url' => Env::string('WATCHER_OBSERVE_HTTP_URL'), 'auth_token' => Env::string('WATCHER_OBSERVE_HTTP_AUTH_TOKEN')],
    'prometheus' => ['enabled' => Env::bool('WATCHER_OBSERVE_PROMETHEUS_ENABLED', false), 'dir' => Env::string('WATCHER_OBSERVE_PROMETHEUS_DIR')],
],
```

A sub-key of a sink that is not enabled is still validated when present (shape errors surface early) but required keys
(`path`, `url`, `dir`) are required only when the sink is enabled.

## Validation messages (config load, before any call)

Every message names the key path, never a value.

| Condition | Message |
|---|---|
| `observe` not a map | `observe must be a map` |
| unknown sink or key | existing `only()` message |
| flag not boolean | `observe.<sink>.enabled must be true or false` |
| missing required key | `observe.<sink>.<key> is required` |
| number out of range | `observe.<sink>.<key> must be an integer between A and B` |
| `file.path` relative, with `..` or a symlink | `observe.file.path must be an absolute path without ..` / `observe.file.path must not be a symlink` |
| `http.url` shape | `observe.http.url must be an https URL with a host` ; `observe.http.url must not contain credentials` |
| `http.url` host not allowed | `observe.http.url host is not in observe.http.allowed_hosts` ; `observe.http.url host is not allowed` (metadata, or private without the flag) |
| `http.url` host equals the log source host | `observe.http.url must not point at the log source` |
| `auth_header` shape | `observe.http.auth_header must be a header name` |
| `prometheus.dir` / `name` / `file_mode` shape | `observe.prometheus.<key> is invalid` |
| driver `none`, no sink enabled | `notify.digest.driver none needs at least one enabled observe sink` |
| `otlp` present | `observe.otlp is not available yet` |

Fleet: `observe` under `defaults`/fleet level is shared; a service's own `observe` **replaces** it as a whole (as
`notify`, spec 011). Errors keep the entry prefix (`services[1] (bff-a): observe.http.url is required`). A fleet
`prometheus.name` is rejected (`services[i]: observe.prometheus.name must be unset in a fleet`) to avoid two services
writing one file.

## `watcher check`

New check name `observe` (after `notifier`), syntactic plus local only, no network:

- per enabled sink: `log ok`, `file ok` (directory exists/creatable, path not a symlink, writable), `prometheus ok`
  (directory exists and writable), `http ok` (config valid; **no request is made**).
- driver `none`: the `notifier` check reads `none (dashboard-only)`.
- `--post-test`: one `config_warning` event with `code: check_test` is flushed to every enabled sink; each sink reports
  `ok` or `failed`; the HTTP check shows the status class only. Nothing is stored (no counters).
- Fail detail lists `<sink> <key> invalid` or `<sink> failed`, never a path with credentials, the URL, the token or an
  exception message. `--json` output has the same fields.

## Secrets

`observe.http.url` (it may carry a path token) and `observe.http.auth_token` join the list of values masked by
`notifySecrets()`/`Heartbeat`'s reason scrub, the `check` output and exception messages (SC-008).
