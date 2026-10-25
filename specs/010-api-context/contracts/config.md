# Contract: configuration (section optional; absent = off)

Validated once at start by `WatchConfig`; errors name the key path only, never a value. Unknown keys rejected. The section
is validated even with `enabled: false`.

```php
'api_context' => [
    'enabled' => true,                       // false = off, still validated
    'source' => getenv('WATCHER_OPENAPI_SOURCE'),   // required: ABSOLUTE file path (or file:/path), or https URL; JSON document
    'auth_token' => getenv('WATCHER_OPENAPI_TOKEN'),// optional, sent as Authorization: Bearer on the one GET
    'ttl' => 'PT24H',                        // ISO-8601 duration, > 0, <= state.retention (`ApiSpecCache` also refuses a TTL of 0 or less)
    'max_endpoints' => 5,                    // 1..20
    'summary_cap' => 80,                     // 20..200 characters
    'max_bytes' => 1048576,                  // default 1 MB; 65,536..2,097,152 (decoded, 2 MiB ceiling). A 2 MB flat JSON decodes to ~144 MB
    'base_path' => null,                     // overrides servers[0].url / basePath; '/api/v1'
    'ignore_prefixes' => [],                 // <= 10, each '/segment...' (e.g. '/api/v2'); stripped from routes
    'allow_insecure' => false,               // with allow_private_network: plain http to a loopback / RFC 1918 / cluster-local host
    'allow_private_network' => false,        // private hosts (https), and with allow_insecure plain http; link-local, CGNAT, metadata always refused
    'allowed_roots' => [],                   // <= 10 absolute dirs; empty = only the configured source file itself (owner decision 2026-10-25)
],
```

| Rule | Error text (key path only) |
|---|---|
| section enabled, `source` missing/empty | `api_context.source is required` |
| `source` is not an absolute path / `file:` path / http(s) URL, or contains `://` in a file path | `api_context.source must be an https URL or an absolute file path` |
| `source` ends in `.yaml`/`.yml` | `api_context.source must be a JSON document (YAML is not supported yet)` |
| `ttl` not an ISO-8601 duration, <= 0, or > `state.retention` | `api_context.ttl must be a duration between 1 second and state.retention` |
| `max_endpoints` / `summary_cap` / `max_bytes` out of range or not int | `api_context.max_endpoints must be an integer between 1 and 20` (etc.) |
| `ignore_prefixes` not a list of `/...` strings, or > 10 | `api_context.ignore_prefixes must be a list of at most 10 paths starting with /` |
| `max_bytes` above 2,097,152 | `api_context.max_bytes must be an integer between 65536 and 2097152` |
| `source` is a URL that is not https (http needs `allow_insecure` + `allow_private_network` + a private host), carries userinfo, or names a link-local, CGNAT or metadata host | `api_context.source must be an https URL; plain http needs ...` (the reader also refuses it with `unsafe_source`); a file source must be an absolute canonical path, not world-writable, below `allowed_roots` when set; redirects are never followed |
| `max_bytes` is a raw decoded-size cap | default **1,048,576**; raising it raises peak memory roughly 70x the document size (measured) |
| unknown key | `Unknown configuration key: api_context.<key>` (the repository-wide wording) |

Secrets: `auth_token` from the host (`getenv`), never in a message, notice, digest, cache, key or model input. The
example config ships the block commented out; `docs/configuration.md` gains the table.

## Failure vocabulary (digest `API context unavailable (<token>)`, `check`, host log)

`unreachable` | `unauthorized` | `timeout` | `too_large` | `bad_format` | `unsupported_format` | `unsupported_version` |
`unreadable` | `unsafe_source` (URL refused before any request). Nothing else is ever printed: no URL, path, header, token or body fragment.
