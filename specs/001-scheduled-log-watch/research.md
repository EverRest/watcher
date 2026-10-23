# Research: Scheduled Log Watch

Sources were checked on 2026-10-23 against official docs. Facts marked UNVERIFIED could not be
confirmed and are handled defensively.

## R1. How to read a 24h window from Loki

- **Decision**: one strategy for both sources, called *server-side totals + filtered lines*:
  1. Totals per level come from an instant metric query:
     `sum by (level) (count_over_time({<stream tags>}[<window>]))` (`GET /loki/api/v1/query`, `time`=window end).
  2. Lines that can become candidates come from a log query over `query_range` with the stream tags,
     a server-side filter, and a page size of ≤ 5000. The filter is level ≥ `read_min_level`
     (default `warning`) **or** a line matching any watch rule (`|~`). Paging walks forward by
     timestamp and stops at `event_cap`.
  3. The package then groups those lines locally (Reducer).
  4. Level-anomaly second pass (FR-038, owner decision 2026-10-23): compare step 1 totals with the
     previous snapshot. For each level below `read_min_level` whose total grew ≥ `growth_factor`,
     run a second log query for that level only, capped at `anomaly_cap` (default 20k), and feed it
     to the same Reducer. On a normal day this adds zero reads.
- **Rationale**:
  - The count over the full window is done server-side, so it is exact and cheap. The
    `max_entries_limit_per_query` default (5000) only applies to lines, not to metric results.
  - The `/loki/api/v1/patterns` endpoint does exist, but it needs `pattern_ingester.enabled`, which
    defaults to false. Whether Grafana Cloud enables it is UNVERIFIED, so it cannot be the default
    path.
- **Consequence for the spec**: FR-036 now says the backend counts **by level**, and grouping by
  pattern happens locally on server-filtered lines. A group below `read_min_level` that matches no
  watch rule is counted in the totals but not fingerprinted. The `read_min_level` setting moves
  that boundary.
- **Auth**:
  - Self-hosted multi-tenant Loki takes `X-Scope-OrgID`.
  - Grafana Cloud uses Basic auth, with the tenant id as the user and a token as the password.
  - Access through Grafana's datasource proxy (`/api/datasources/proxy/uid/{uid}/loki/api/v1/...`)
    uses a service-account bearer token, which is UNVERIFIED for the proxy and covered by a
    contract fixture.
  - Config supports `bearer` and `basic` auth plus an optional `tenant`.
- **Limits to respect**: `query_timeout` defaults to 1m, so the metric query over 24h stays at a
  single step. `max_query_series` defaults to 500, so a grouping by `level` alone stays far below it.
- **Alternatives**:
  - The patterns API as the default was rejected: it is not enabled by default. It can be added
    later as an opt-in path behind the same `LogSource` contract.
  - Pulling every line was rejected because of volume and the 5000-line pages.

## R2. How to read a 24h window from Datadog

- **Decision**: the same strategy as R1.
  1. Totals come from `POST /api/v2/logs/analytics/aggregate` with `compute: [{aggregation: count, type: total}]`,
     `group_by: [{facet: status}]` and `filter: {from, to, query: <stream tags>}`.
  2. Lines come from `POST /api/v2/logs/events/search` with a filter of
     `status:(warn OR error OR critical ...)` OR the watch terms. Paging uses `page.cursor` →
     `meta.page.after` and `page.limit` 1000, and stops at `event_cap`.
- **Rationale**:
  - No public Log Patterns API was found (UNVERIFIED as absent).
  - Whether the aggregate endpoint can group by `@message` is UNVERIFIED, so templates are derived
    locally, as in R1.
- **Auth**:
  - `DD-API-KEY` plus `DD-APPLICATION-KEY`.
  - The application key is scoped to `logs_read_data` only (Principle II).
  - The base URL is `https://api.<site>`. `datadoghq.com` is confirmed. EU and US3 follow the same
    pattern (UNVERIFIED) and come from the `site` config.
- **Rate limits**: they are not published. The adapter honours a 429 response by waiting until the
  time in `X-RateLimit-Reset`, at most 3 times, before raising `SourceUnavailable`.
- **Alternatives**: grouping by message on the server was rejected as unverified. It can be swapped
  in behind the contract if a fixture proves it.

## R3. AI provider calls

- **Structured output — decision**: use `output_config: {format: {type: "json_schema", schema}}`.
  - **Do not** force a tool call. With `tool_choice {type: "tool"|"any"}`, the current 5.5-generation
    models answer with a 400, and `output_format` is deprecated.
  - The provider schema cannot carry `maxLength`, `minimum` or `maximum`. Code strips those keywords
    before sending and enforces them after parsing (Principle IV). Strings that are too long are
    truncated with an ellipsis; numbers out of range make the output invalid, which triggers a retry.
  - The canonical schema is [contracts/analyzer-output.schema.json](contracts/analyzer-output.schema.json),
    and it is the one code uses to validate.
- **SDK — decision** (owner-approved 2026-10-23): use the official `anthropic-ai/sdk ^1.1`
  (PHP 8.1+, PSR-18). It reuses the Guzzle client the package already needs, so there is no
  second HTTP stack.
  - **Rationale**:
    - It supports structured output: `outputConfig: ['format' => ...]` produces typed, parsed
      results.
    - It retries 408, 409, 429, 5xx and timeouts with exponential backoff, 2 attempts by default,
      configurable through `maxRetries`.
    - It provides typed exceptions.
    - Without it, all of this would be our own code (Principle VIII).
  - `AnthropicAnalyzer` is a thin wrapper: it builds the request, calls the SDK, maps the result to
    `Finding`/`Usage`, and enforces the code-side schema rules.
  - Not documented in the SDK README, so to verify with a test during implementation:
    - `cache_control` on system blocks;
    - every `usage` field, including the cache fields.
    - If either is missing, the wrapper sends the raw parameters the SDK allows, without leaving
      the SDK.
  - **Alternative rejected**: plain Guzzle calls. The original argument was that the SDK lags on
    structured-output fields; it turned out false on checking.
- **Prompt caching — decision**: mark the stable prefix with `cache_control: {type: "ephemeral"}`.
  The prefix is the system prompt, the schema, and the rules for the step.
  - **Rationale**: the minimum cacheable prefix is 512 tokens on the 5.5 models, and the TTL is
    5 minutes. Triage, analyze and escalate requests inside one run happen within minutes.
    The escalate step reuses the analyze prefix, and parallel retries do too.
  - Caching does **not** carry over between daily runs; the 1-hour TTL would not help either.
    Expected saving is small, so the cost model does not depend on it.
  - For pricing, cache writes cost 1.25× and reads cost 0.1× (0.05× on Opus and Sonnet 5.5).
- **Token estimation — decision**: estimate locally (UTF-8 bytes / 3, a conservative figure) and
  rely on per-step `max_tokens`.
  - The `count_tokens` endpoint is not used, because it would cost one request per step
    (Principle V).
  - Actual figures come from `usage`: `input_tokens`, `output_tokens`,
    `cache_creation_input_tokens` and `cache_read_input_tokens`. These feed the footer and the
    `Spend` counter.
- **Default models** (documented in README and the example config, **never in code**, FR-017):

  | Step | Model | Price in / out per MTok |
  |---|---|---|
  | triage | `claude-haiku-5-5` | from $0.10 / $0.50 |
  | analyze | `claude-sonnet-5-5` | $2 / $10 |
  | escalate | `claude-opus-5-5` | $4 / $20 |

  - The Batch API (−50%) is out of scope for v1. The `Analyzer` contract leaves room for it.
- **Cost check against SC-004**: a typical run sends triage about 15k tokens in and 1k out, and
  analysis about 10k in and 3k out. That is ≈ $0.002 for triage and ≈ $0.05 for analysis, so a run
  is ≈ $0.05, under the $0.10 target.

## R4. Slack

- **Decision**: an incoming webhook with Block Kit `blocks`, plus a plain `text` fallback.
- **Limits**:
  - 50 blocks per message.
  - Fallback text should stay at or below 4,000 characters.
  - About 1 message per second per channel.
  - Per-block text limits are UNVERIFIED (3000 characters per section is commonly cited). The
    renderer caps each section at 2,800 characters to stay safe.
- **Overflow rule**: the digest is never split. It renders at most 40 blocks, and any finding beyond
  that moves into the "minor" line ([contracts/slack-messages.md](contracts/slack-messages.md)).
- **Alternatives**: a bot token with `chat.postMessage` was rejected for v1. It is needed only for
  reactions or threads, both out of scope (Clarifications Q1).

## R5. State storage

- **Redis**: use `ext-redis` `\Redis`.
  - `SET key val NX EX ttl` for `acquire`.
  - `INCRBYFLOAT` plus `EXPIRE` for `increment`.
  - `release` uses a compare-and-delete Lua script, so a runner cannot drop another runner's lock.
  - Predis is rejected: it is one more dependency, and hosts that need it can wrap it.
- **PDO**: one table `watcher_state(key VARCHAR(191) PK, value TEXT, expires_at BIGINT)`.
  - `acquire` uses `INSERT` and treats a duplicate key as "held". An expired row is deleted inside
    the same transaction first.
  - Expired rows are purged opportunistically on each run.
  - Works with MySQL, PostgreSQL and SQLite; contract tests run on SQLite.
- **File**: one JSON file per key under `path`. Writes go through `flock` with write-and-rename,
  and expiry is stored inside the file. Single runner only (FR-028).

## R6. Tooling

- **Decision**:
  - PHPUnit `^10.5 || ^11` with three suites: Unit, Contract and EndToEnd.
  - PHPStan level 8.
  - Laravel Pint for style. It is the same toolchain as the first consumer, and it is a dev-only,
    framework-free binary.
  - `psr/log` for host error output.
  - Runtime dependencies: `guzzlehttp/guzzle ^7` (Loki, Datadog, Slack, and the PSR-18 client for
    the SDK), `anthropic-ai/sdk ^1.1` (R3) and `psr/clock ^1` (PSR-20, interface only). Time is
    injected through `Psr\Clock\ClockInterface` so tests can freeze it, without a contract of our
    own (Principle VIII, owner decision 2026-10-23); tests use a `FrozenClock` in `tests/`.
- **Rationale**: PHPUnit 10 matches orders-api; level 8 is strict without the noise level max adds on
  mixed arrays from decoded JSON.
- **Alternatives**: PHP-CS-Fixer would also work; Pint was chosen for consistency with orders-api.
  Psalm was rejected, because one static analyser is enough.

## R8. Context: dependencies, deploy version, links (FR-039–FR-043)

- **Dependencies**: read with the service's own `LogSource` (or a per-dependency source override)
  using the dependency's stream tags. Every run: one `totals()` call per dependency (a single
  aggregate query — cheap, and the only way to know whether the dependency erred). Only when a
  candidate references the host or the dependency's error total grew ≥ `growth_factor`: one
  `lines()` call filtered to level ≥ error, capped at 5,000, reduced locally, top 5 groups by count.
  A quiet day therefore adds one totals query per dependency and zero dependency line reads. The dependency's previous totals are kept in its own `snap:{service}:dep:{name}:…` key.
  For orders-api the initial declaration comes from `orders-api/EXTERNAL_SERVICE_CALLS.md` (users-api,
  partners-api, core-api, auth-api).
- **Deploy version**: `deploy.release` / `deploy.commit` from env. Loki: if `deploy.version_label`
  is set (e.g. an image tag label), a metric query `count by (<label>)` over the window gives the
  versions present and the switch time is found with a coarse `step` (1h). Datadog: same via
  `group_by facet version`. Otherwise the change is detected between snapshots only.
- **Log links**:
  - Grafana: Explore URL with `left`/`panes` JSON (datasource uid, LogQL with stream tags +
    `|= "<literal>"` + level filter, time range). The exact URL shape is version-dependent
    (UNVERIFIED for the target Grafana) → built by one small class with a fixture test.
  - Datadog: `https://app.<site>/logs?query=…&from_ts=…&to_ts=…`.
  - Literal = the longest placeholder-free token run of the template, ≤ 60 chars, after redaction.
- **Code links**: GitLab blob URL `{repo.web_url}/-/blob/{commit}/{mapped path}#L{line}`; GitHub
  `…/blob/{commit}/{path}#L{line}`; chosen by `repo.provider`. Unmapped paths (vendor, framework)
  get no link.

## R7. Fingerprint and normalisation

- **Decision**: the template comes from the message after these ordered replacements:
  1. emails → `<EMAIL>`
  2. URLs → `<URL:host>` (FR-039, owner decision 2026-10-23, option B) — host kept only if it is a
     single label `[a-z0-9-]+`, a declared dependency, or ends with a `dependency_domains` entry;
     otherwise `<URL>`; IP hosts → `<IP>`; path, query, credentials and port always removed
  3. UUIDs → `<ID>`
  4. hex of 8+ characters → `<ID>`
  5. IPv4/IPv6 → `<IP>`
  6. JWT-like strings → `<TOKEN>`
  7. card-like numbers → `<PAN>`
  8. other digits → `<N>`
  9. quoted strings longer than 32 characters → `<STR>`

  Whitespace is collapsed. The fingerprint is `sha256(level|errorType|template|frame0)`, truncated
  to 12 hex characters.
- **Template mining (FR-044, owner decision 2026-10-23, option A)**: events are clustered by
  (level, errorType, token count, first frame); within a cluster, a token position whose values
  differ between events becomes `<*>`. A cluster with fewer than `min_group_events_for_text` events
  (default 3) sends no message text — only level, error type, top frames and counters. Names in free
  text cannot be found by patterns (verified on the corpus: 9 of 44 seeded values, all names, passed
  the pattern table), so protection comes from variation plus a minimum cluster size, not guessing.
  Bare FQDN host names (≥ 2 labels, alphabetic last label, not a known file extension) are treated
  like URL hosts.
- **Text-line formats**: both `[dt] channel.LEVEL [APP]: msg {ctx} {extra}` (orders-api `StdFormatter`)
  and Laravel's default `[dt] channel.LEVEL: msg {ctx} {extra}` are accepted.
- **Text-line format** (orders-api `StdFormatter`): parsed with
  `^\[(?<dt>[^\]]+)\] (?<ch>[\w.-]+)\.(?<lvl>[A-Z]+) \[(?<app>[^\]]*)\]: (?<msg>.*?)(?<ctx> \{.*\})?(?<extra> \[.*\]|\{.*\})?$`.
  Context JSON is decoded into `fields` (never sent). An unparseable line becomes level `info`,
  with the raw line kept as the message only for normalisation.
- **JSON lines**: decoded. Known keys are mapped (`level|severity|status`, `message|msg`,
  `exception.class|error.kind`, `exception.trace|error.stack`); everything else goes to `fields`.
- **Rationale**: the redaction patterns (FR-010) and the normalisation patterns are the same table,
  so whatever is normalised away is also never sent.
