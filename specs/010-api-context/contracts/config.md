# Contract: configuration (section optional; absent = off)

Validated once at start by `WatchConfig`; errors name the key path only, never a value. Unknown keys rejected. The section
is validated even with `enabled: false`.

```php
'api_context' => [
    'enabled' => true,                       // false = off, still validated
    'source' => getenv('WATCHER_OPENAPI_SOURCE'),   // required: absolute/relative file path, or https URL; JSON document
    'auth_token' => getenv('WATCHER_OPENAPI_TOKEN'),// optional, sent as Authorization: Bearer on the one GET
    'ttl' => 'PT24H',                        // ISO-8601 duration, > 0, <= state.retention
    'max_endpoints' => 5,                    // 1..20
    'summary_cap' => 80,                     // 20..200 characters
    'max_bytes' => 1048576,                  // default 1 MB; 65,536..8,388,608 (decoded). A 2 MB flat JSON decodes to ~144 MB
    'base_path' => null,                     // overrides servers[0].url / basePath; '/api/v1'
    'ignore_prefixes' => [],                 // <= 10, each '/segment...' (e.g. '/api/v2'); stripped from routes
],
```

| Rule | Error text (key path only) |
|---|---|
| section enabled, `source` missing/empty | `api_context.source is required` |
| `source` has a scheme other than `https`, or contains `://` in a file path | `api_context.source must be a file path or an https URL` |
| `source` ends in `.yaml`/`.yml` | `api_context.source must be a JSON document (YAML is not supported yet)` |
| `ttl` not an ISO-8601 duration, <= 0, or > `state.retention` | `api_context.ttl must be a duration between 1 second and state.retention` |
| `max_endpoints` / `summary_cap` / `max_bytes` out of range or not int | `api_context.max_endpoints must be an integer between 1 and 20` (etc.) |
| `ignore_prefixes` not a list of `/...` strings, or > 10 | `api_context.ignore_prefixes must be a list of at most 10 paths starting with /` |
| `max_bytes` is a raw decoded-size cap | default **1,048,576**; raising it raises peak memory roughly 70x the document size (measured) |
| unknown key | `api_context.<key> is not a known setting` |

Secrets: `auth_token` from the host (`getenv`), never in a message, notice, digest, cache, key or model input. The
example config ships the block commented out; `docs/configuration.md` gains the table.

## Failure vocabulary (digest `API context unavailable (<token>)`, `check`, host log)

`unreachable` | `unauthorized` | `timeout` | `too_large` | `bad_format` | `unsupported_format` | `unsupported_version` |
`unreadable`. Nothing else is ever printed: no URL, path, header, token or body fragment.
