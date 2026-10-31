# Changelog

## 0.13.2 — 2026-10-31 — Run digest and checks from MCP

- **New `actions` MCP group** (off unless named in `mcp.groups` or `WATCHER_MCP_GROUPS`): `watcher_run_digest` (`watcher:fleet` or `watcher:run`, with `dry_run`, `now`, `only`, `fleet`), `watcher_health_check` (`watcher:health`), `watcher_test_message` (`watcher:check --post-test`).
- `mcp.artisan` (or `WATCHER_MCP_ARTISAN`): absolute path to the artisan file. Required when `actions` is enabled; the path is never taken from the working directory.
- `now` is accepted only for a dry run (`dry_run: true`); a real run at a chosen instant is refused (`now_needs_dry_run`).
- Only the exit code comes back from a command; the JSON last line is returned only when the command ran with `--json`.
- Actions that send need `confirm: true`; a digest run is a dry run unless `dry_run` is false. Each action runs at most once per 5 minutes per session, and every run is logged as `watcher [audit] ...`.
- Actions run the artisan commands in a child process with an argument list. `src/Mcp/ArtisanCommand.php` is the only file in `src/Mcp` allowed to start a process (the static guard exempts it).
- `mcp.groups` default is still the read-only three; `actions` must be named.
- Tool annotations: `actions` tools are `readOnlyHint: false`, `destructiveHint: true`.

## 0.13.1 — 2026-10-31 — Health summary on weekdays in its own zone, one schedule

- **Removed:** `health_check.summary_every_hours`. The summary has one schedule: `summary_at`, inside `summary_hours`. A config that still sets it is rejected with an error, instead of quietly falling back to the daily summary.
- **New:** `health_check.timezone` (default `UTC`; set `Europe/Berlin` for CET/CEST) sets the zone for `summary_at`, `summary_hours` and `summary_days`. It is independent of `schedule.timezone`, so the daily run keeps its zone.
- **Changed:** `summary_days` defaults to `mon..fri` again; `null` still means every day. The default summary time is 09:00 in the new zone (UTC unless set).
- **Behaviour change:** in 0.13.0 the summary followed `schedule.timezone`. A watcher whose `schedule.timezone` is not UTC now sends it at a different hour. Set `health_check.timezone` (for example `Europe/Berlin`) to keep its zone.
- Health messages, both the summary and version/up-down alerts, are sent only on `summary_days` (default `mon..fri`). Nothing is sent or stored on a weekend; a change made then is sent on the first allowed day.
- Version-change alerts are not limited to working hours; only the summary uses `summary_hours`.
- New `notify.days` (default `null`, every day): the digest and immediate alerts are sent only on the listed days, in `schedule.timezone`. Outside them a message is dropped, not queued. The run still reads and analyses those days. Failure notices, probes and the weekly heartbeat are not affected and go out on any day. In a fleet each service uses its own `notify.days`.

## 0.13.0 — 2026-10-31 — Health summary in working hours, with a period

- `health_check.summary_every_hours` (`1`..`24`): send the health summary every N hours instead of once a day, counted from the start of the working window. Cannot be set together with `summary_at`.
- `health_check.summary_hours` (default `09-18`) and `health_check.summary_days` (default `mon`..`fri`): the summary is only sent inside these working hours of working days. `null` turns each limit off.
- **Behaviour change:** `summary_at` and the new keys use `schedule.timezone` (UTC when it is not set). In 0.12.0 `summary_at` was always UTC, so a watcher with another timezone sees its 09:00 summary move. The daily summary is no longer sent on Saturday and Sunday; set `summary_days` to `null` to keep it every day.

## 0.12.0 — 2026-10-31 — Daily health summary

- `health_check.summary_at` (`HH:MM` UTC, default `09:00`, `null` = off): once a day the health check sends a summary of every service in the host snapshot (name, version, up/down, `checked_at`), even when no version changed. Only the hour is used, because the check runs hourly. A failed summary send is logged (`health: summary not sent`) and does not change the exit code or the version alerts.

## 0.11.1 — 2026-10-30 — Test fixes, no runtime change

- The MCP process test runs its child with `XDEBUG_MODE=off`, so an Xdebug banner on stderr no longer fails it.
- The driver equivalence test compares model calls only. Which groups reach the model varies per run by design (the `top_m` tie-break is random), and so does the stored state key count.

## 0.11.0 — 2026-10-30 — Certificate and secret expiry probes; errors are reported on the first run and repeated daily

- New `probes` section, off by default: TLS certificate expiry of `probes.hosts` and declared secret expiry of `probes.secrets`, with `probes.warn_days` (30) and `probes.fail_days` (7). Deterministic, no model call, no secret value read.
- `schedule.probe_every` (`WATCHER_PROBE_EVERY`, default `PT1H`, at least `PT5M`): probes run on the first run trigger after it has passed. A host that triggers less often runs them less often. A non-string value is refused.
- Alerts go to the notice destination on a status change, or once a day while a warning or failure stands. An unreachable host is `unknown` and never alerts.
- Behaviour change (FR-035): a first run with no previous snapshot posts a digest when a group is at Error or above, with only those groups. It used to record a baseline only, so a day with errors on the first run looked silent. Warning and lower levels are not in the first digest.
- Behaviour change (FR-008): a group already reported that has not grown and is still in today's read is sent again with its stored finding, with no model call. A stored finding that cannot be read makes the group analysed again.
- A probe that fails no longer stops the other probes in the same interval. `probes` enabled while `notify.notice` is off logs a warning.
- Known ceiling: certificate hosts are connected one after another, up to 5 s each, before the run guard exists.

- New `probes` section, off by default: TLS certificate expiry of `probes.hosts` and declared secret expiry of `probes.secrets`, with `probes.warn_days` (30) and `probes.fail_days` (7). Deterministic, no model call, no secret value read.
- `schedule.probe_every` (`WATCHER_PROBE_EVERY`, default `PT1H`, at least `PT5M`): probes run on the first run trigger after it has passed. A host that triggers less often runs them less often.
- Alerts go to the notice destination on a status change, or once a day while a warning or failure stands. An unreachable host is `unknown` and never alerts.

## 0.10.0 — 2026-10-29 — Hourly host health check

- `watcher:health` (Laravel adapter): reads the host app's `{app}:health` snapshot from its Redis once per hour and alerts at once when a service's version changes. Opt-in (`health_check.enabled`); separate from the daily run. Optional up/down transitions (`health_check.notify_transitions`).

### Fixed
- Immediate alerts (0.9.0 review): a noisy rule can no longer hold back every other rule for a whole read, `http_code` is kept only as three digits, and free text in `error` follows `redaction.min_group_events_for_text`.

## 0.9.0 — 2026-10-29 — Immediate alerts for matching log lines

### Added
- `alerts.immediate`: a notice is sent as soon as a log line matches a rule during the read, not at the next digest. A rule has a `level` (a minimum, default `critical`), a plain `message` substring and an exact `action`. Off by default: `alerts.enabled`, environment `WATCHER_ALERTS_ENABLED`.
- `alerts.window` (default `PT15M`): at most one alert per rule, message and affected service per window. The window is kept in the state store, so a restart does not repeat an alert.
- `alerts.fields`: the context values an alert may show (`service`, `http_code`, `error`, `services`). Empty by default. Numbers are masked except `http_code`.
- At most 5 alert messages per read. The rest are not recorded and go out on a later read, with one line saying how many were held back.

### Notes
- Context values stay off the notice unless listed in `alerts.fields`. Enabling it sends masked values to the notice destination, which `docs/privacy.md` does not yet describe.


## 0.8.0 — 2026-10-28 — Retry transient provider failures (OpenAI, Gemini)

### Added
- OpenAI and Gemini retry transient failures (429, 500, 502, 503, 504, a timeout or a connection error), up to `analysis.providers_options.{openai,gemini}.retries` (0 to 3, default 0). Each attempt gets the time left of the call timeout, and a wait that would end past the deadline is not taken. `Retry-After` is honoured, capped per wait.
- A refusal or an invalid reply is never retried.

### Changed
- `analysis.providers_options.{openai,gemini}.retries` may be 0 to 3 (it was refused above 0 in 0.7.0). Anthropic is not part of this change.

## 0.7.0 — 2026-10-28 — Remove the Kubernetes event source and Anthropic

Breaking: Anthropic is no longer a provider. The providers are OpenAI and Gemini, and the default is Gemini. A configuration
that still names Anthropic **fails at load**; nothing is converted.

### Removed configuration (each fails at load with its message; `watcher check` lists them all)

| Key | Message |
|---|---|
| `analysis.api_key` | analysis.api_key was removed in 0.7.0; use analysis.api_keys.gemini or analysis.api_keys.openai (the provider in use). |
| `analysis.api_keys.anthropic` | analysis.api_keys.anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use analysis.api_keys.gemini or analysis.api_keys.openai. |
| `analysis.providers_options.anthropic` | analysis.providers_options.anthropic was removed in 0.7.0; Anthropic is no longer a provider. Delete this section. |
| `analysis.provider = anthropic` | analysis.provider = anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use gemini or openai. |
| `analysis.providers.<step> = anthropic` | the same, naming the step (`analysis.providers.triage = anthropic was removed in 0.7.0; ...`). |

`WATCHER_ANTHROPIC_KEY` is no longer read by the setup catalog (MCP) and is replaced by `WATCHER_GEMINI_KEY`. The package reads no environment variables itself; the message is not repeated for it.

### Changed
- The default provider is `gemini` (was `anthropic`). A configuration that names no provider now uses Gemini and needs `analysis.api_keys.gemini`.
- `analysis.providers_options.{openai,gemini}.retries` must be 0 (neither adapter retries).
- `watcher check`: `provider_auth` and `models` show SKIP for every provider. The Anthropic models-list probe is gone and no other provider has one.
- Generated configuration (`watcher_generate_config`) writes `provider => gemini` and `api_keys.gemini`, and the setup catalog asks for `WATCHER_GEMINI_KEY`.
- The `rotate-credentials` prompt lists `gemini` and `openai` in place of `anthropic`.

### Removed
- Dependencies: `anthropic-ai/sdk`, and `php-http/discovery` (nothing else used it), with its `allow-plugins` entry. `anthropic` and `claude` are no longer keywords.
- The Anthropic SDK adapter, the Anthropic model catalog and the Anthropic fixtures.
- Internal: `AnthropicAnalyzer` is `ProviderAnalyzer`. Its logic is unchanged.

### What operators do
1. Set `analysis.provider` to `gemini` (or delete the key: gemini is the default), or to `openai`.
2. Replace `analysis.api_key` and `analysis.api_keys.anthropic` with `analysis.api_keys.gemini` (or `openai`), and set that key from the environment.
3. Delete `analysis.providers_options.anthropic`. Change any `analysis.providers.<step>` set to `anthropic` to `gemini` or `openai`.
4. Replace the model ids. A Claude model id is not valid for either provider; set `models.triage`, `models.analyze` and `models.escalate` to ids the chosen provider serves.
5. In Kubernetes or your environment, replace `WATCHER_ANTHROPIC_KEY` with `WATCHER_GEMINI_KEY`.
6. Run `watcher check`: `provider_auth` and `models` are SKIP, so check the key and the model ids on the first run.

### Kubernetes event source removed

Breaking: a configuration that still has a removed key **fails at load**. Nothing is converted and no replacement exists.
The Kubernetes event source (spec 008) is gone. Pod-level problems are not read from the cluster any more; see "What to do instead".

### Removed configuration (each fails at load with its message; `watcher check` lists them all)

| Key | Message |
|---|---|
| `kubernetes` (whole section, including `kubernetes.enabled`) | kubernetes section was removed in 0.7.0; there is no replacement. Delete this section. |
| `events` (whole section) | events section was removed in 0.7.0; there is no replacement. Delete this section. |
| `source.driver: kubernetes` | source.driver kubernetes was removed in 0.7.0; there is no replacement. Use a log source (loki or datadog). Delete the kubernetes settings. |

`source.driver` values are now `loki` or `datadog` only. The feature flag `kubernetes` is gone with the section.
`WATCHER_KUBERNETES_ENABLED` is no longer read; it produces no message.

### Removed behaviour
- No cluster read on any trigger, and no `--dry-run` read of the cluster. The hourly ledger and its `evt:` state records are gone; records left in a state store expire on their own TTL and nothing reads them.
- `KUBERNETES_SERVICE_HOST` is no longer read. The in-cluster flag is gone, so `watcher check` no longer warns that `state.driver=file` is used inside Kubernetes.
- Heartbeat: no `events` field. Nothing sets `degraded` any more (the value stays in the status vocabulary, so exit codes do not change).
- Digest: no pod-event lines and no `k8s_*` read-quality counters.
- Outcome code `k8s_ingest_failed` (reserved, never emitted) is no longer registered.
- `watcher check`: no `events` check.
- JSON run summary: no `events` key.
- Model payload: the `src`, `pods` and `wk` keys of a group are gone. Only a pod-event group ever carried them, and none is produced now, so every payload is unchanged.
- Internal: the event origin, pod id and workload kind fields on log events and groups are removed. Groups from Loki and Datadog are unchanged.

### What to do instead
1. Delete the `kubernetes` and `events` sections, and `source.driver: kubernetes`, from every service configuration (and from the fleet file).
2. Remove the RBAC role and binding that let watcher read `events` and `pods`, if nothing else uses them.
3. To keep crash loops, OOM kills, image pull failures, failing probes and restarts in the digest, export the cluster events to the log backend your service already reads, and read them through `source.driver: loki` or `datadog` with a stream selector for them. Watcher then reads them like any other log line and redacts them the same way.

[`docs/kubernetes.md`](docs/kubernetes.md) is kept as a short pointer. Spec 008 stays in `specs/` as history.

## 0.5.0 — 2026-10-27 — Remove budgets and token and cost accounting

Breaking: a 0.4.x configuration that still has a removed key **fails at load**. Nothing is converted and no replacement exists.
The package no longer caps spend, reserves model calls, counts tokens for cost, or reports cost. Constitution 2.0.0.

### Removed configuration (each fails at load with its message; `watcher check` lists them all)

| Key | Message |
|---|---|
| `analysis.daily_budget_usd` | analysis.daily_budget_usd was removed in 0.5.0; there is no replacement. Delete this key. |
| `analysis.run_budget_usd` | analysis.run_budget_usd was removed in 0.5.0; there is no replacement. Delete this key. |
| `analysis.prices` | analysis.prices was removed in 0.5.0; there is no replacement. Delete this key. |
| `analysis.daily_token_limit` | analysis.daily_token_limit was removed in 0.5.0; there is no replacement. Delete this key. |
| `analysis.budget_alert_percents` | analysis.budget_alert_percents was removed in 0.5.0; there is no replacement. Delete this key. |
| `analysis.max_runs_per_day` | analysis.max_runs_per_day was removed in 0.5.0; there is no replacement. Delete this key. |
| `budget` (whole section, also in the fleet file) | budget was removed in 0.5.0; there is no replacement. Delete this section. |
| `budget_alerts` (top-level flag) | now an unknown key: `Unknown configuration key: budget_alerts` |

Environment variables `WATCHER_PRICE_*` are no longer read. They produce no message.

### Removed output and metrics
- Event stream: `watcher.event.v2` replaces `watcher.event.v1`. Run records lose `tokens_in`, `tokens_out`, `tokens_cached` and `cost_usd`; the `budget` record is gone. Schema: `docs/schema/watcher.event.v2.schema.json`. The v1 schema is kept and marked retired (`docs/schema/watcher.event.v1.schema.json`, `retired: 0.5.0`).
- Metrics removed: `watcher_last_run_cost_usd`, `watcher_last_run_tokens`, `watcher_budget_spent_usd`, `watcher_budget_limit_usd`, `watcher_budget_ratio`. Run, finding and outcome metrics stay.
- Digest: no cost line, no budget line, no token footer, no "budget exhausted" or run-cap notice. Notices name no currency or token figure.
- Weekly heartbeat line: counts only (no cost).
- `watcher status`, `watcher check` and the MCP tools: no `cost_usd`, `spend_today`, `today_usd` or `prices` fields or checks. `watcher_dry_run` takes `confirm_model_calls` (was `confirm_cost`); its estimate lists steps and groups only; it refuses a retry after a failed run until the server restarts.
- `watcher hygiene`: the `cost` report and `--days` are gone. `--report` takes `privacy`, `shape` or `proposals`. Proposal evidence and replay carry no token figures.
- `watcher fleet`: no fleet budget, no budget notices and no cost in its JSON line.
- Run summary (`--json` and the `outcome=` line): no `tokens_*`, `cost_usd` or `today_usd`.
- Outcomes `capped` (run cap), `budget_exhausted` and the cost-related MCP codes are gone. A run that used to stop at a budget now runs to completion.

### Nothing is converted
- A stored spend counter (`spend:...` keys in the state store) is neither read nor deleted. The package stops writing them. Delete them when you no longer need them.
- Findings are the same as 0.4.x for the same window (`tests/EndToEnd/SameFindingsTest.php`). The per-step call count in the run record counts every model request made, including failed ones (as in 0.4).

### Upgrade steps
1. Delete every removed key and the `budget` section from the service configuration and from the fleet file (see the table above). Delete `WATCHER_PRICE_*` and any budget variables from the environment.
2. Run `watcher check` until it passes. It lists every removed key at once.
3. Point any consumer of `watcher.event.v1` at `watcher.event.v2` (select on `schema`). Remove dashboards and alert rules that read the removed metrics; keep the run and finding panels.
4. Optionally clear the stored `spend:` keys in the state store.
5. Upgrade the package. Tag and release are the owner's decision.

## 0.4.0 — 2026-10-27 — Multi-provider model layer (Anthropic, OpenAI, Gemini per step)

Product release label **v0.4.0** (root VERSION). Read the **Changed (breaking for `analysis.client`)** section before upgrading.

### Added (spec 016, multi-provider model layer)
- Each analysis step (triage, analyze, escalate) picks its provider: `analysis.provider` is the default, `analysis.providers.<step>` overrides it. Providers: `anthropic`, `openai`, `gemini`.
- OpenAI and Gemini adapters over the package's HTTP client (no official PHP SDK exists for either; the third-party `aisdk/*` packages were checked and rejected: organisation created 2026-05, 0.x, PHP ^8.3).
- `analysis.api_keys.{anthropic,openai,gemini}`; `analysis.api_key` keeps working as the Anthropic key. `analysis.providers_options.{anthropic,openai,gemini}` sets `base_url` (https only) and `retries` (0 to 3).

### Changed (breaking for `analysis.client`)
- `anthropic-ai/sdk` is back as the Anthropic transport, pinned `~1.3.0` (reverses the 0.3.0 removal; the reasons are in `specs/016-model-providers/research.md` R1). It adds `php-http/discovery` and its Composer plugin prompt, which is allowed in `composer.json`. The base URL is explicit; the SDK's `ANTHROPIC_BASE_URL` fallback never applies.
- `analysis.client`: absent or `http` is accepted and ignored. Any other value, including `sdk`, is refused at config load with a message naming the replacement. Delete the key.
- `AnthropicHttpClient` is removed. `MessagesClient` is now `ModelProvider`.
- Prices stay in the configuration file. No price environment variables are read by the package; the examples now write literal prices.

### Changed (tooling)
- The committed `coverage` badge (`docs/badges/coverage.svg`) is gone too: it said 96.5% while the code had grown by thousands of lines. CI still fails the build below the 92% floor and shows the number in the job summary.
- The committed `version` badge (`docs/badges/version.svg`) is gone from the README and from `scripts/badges.php`: it went stale (0.1.2 after 0.3.1). The version shown is the live "last successful release" badge, which reads the latest GitHub Release.

## 0.3.1 — 2026-10-26 — Hygiene, check and status read the master switch first; release guards; prompt-injection tests

Product release label **v0.3.1** (root VERSION).

### Changed (release process, F9)
- `release.yml` has a first guard, `pinned`: the tag must still point at the commit the run was started for (`github.sha`), the commit the archive is built from. Before, the other guards read the tag at run time, so a tag moved after the push could pass the checks while the old commit was released (found by the automated push review).
- `release.yml` refuses a tag that is not the tip of `origin/main`, is lightweight, has a `VERSION` that differs from the tag, or has no `## X.Y.Z` heading in `CHANGELOG.md`. Each guard fails the run with one line before any GitHub Release is created (`scripts/check-release.sh`, tested by `make release-guards-test`, part of `make ci`). The release steps and the rule for a wrong tag are in `docs/publishing.md`. The existing `v0.2.0` tag (on `36673a5`) is not moved.

### Tests
- Prompt-injection corpus (F8): 42 hostile log lines run through the real Slack, Telegram and Discord notifiers (`tests/EndToEnd/PromptInjectionTest.php`); error-or-higher groups keep their level whatever the model says (`ErrorGroupLevelTest.php`); the daily budget and `source.group_cap` hold under thousands of distinct hostile groups (`HostileVolumeBudgetTest.php`). No production code changed. `docs/privacy.md` records the residual risk and one known exception (a capitalised host on an unlisted TLD, `EVIL.ABOGADO`).

### Changed (master switch, F7 follow-up)
- Fixed (F7, follow-up): `Hygiene::fromConfig`, `Factory::doctor` (`watcher check`) and `Factory::heartbeatStatus` (`watcher status`) read the master switch from the raw config before validation, as `Watcher::fromConfig` does. With `enabled` not `true` a broken config no longer throws or exits 2: each returns `disabled` (exit 0) and builds no source, state, catalog or notifier, and reads no store. **Behaviour change:** `watcher check` and `watcher status` no longer report an invalid config while the switch is off (they did before); set `enabled => true` to validate. `status` shows a stale limit of 0 for a switched-off config, since the config is not read.

## 0.3.0 — 2026-10-26 — Config credentials hidden from dumps, SDK path removed, MCP no longer runs sibling PHP files, notifier reply cap

Product release label **v0.3.0** (root VERSION). Contains everything below, including the notes of the untagged 0.2.1. Do not install `v0.2.0`.

**FIXED**
- Fixed (F7, fleet master switch): with the fleet `enabled` set to `false`, `fleet:run`, `fleet:status` and `fleet:check` validated every entry and opened every service file first, so one broken service file broke a switched-off fleet. The fleet file's raw `enabled` key is now read before anything else: a switched-off fleet validates no entry, opens no service file, builds no source, state or notifier, and exits 0 with each service reported `disabled`. The disabled names are the raw `service` values that look like names; a directory fleet switched off shows none. Only `enabled: false` switches a fleet off, as before (absent stays on).

**BREAKING / UPGRADE**
- Changed (**breaking for code that reads the config arrays**): the credentials of a built config are no longer plain strings. `WatchConfig` keeps every secret leaf (`source.loki.auth.token` and `.password`, `source.datadog.api_key` and `app_key`, `analysis.api_key`, `notify.{digest,notice}.webhook` and `bot_token`, `state.redis.password`, `state.pdo.dsn`, `username` and `password`, `api_context.source` and `auth_token`) as a `SecretValue`, in the public arrays `source`, `analysis`, `notify`, `state` and `apiContext`, and `FleetConfig::` the same way. `var_export`, `print_r`, `serialize` and an object dump of a config, of a Watcher or of the Factory results no longer show a credential (they showed one before, through `var_export`, `print_r` and `serialize`). Upgrade: read a leaf with `WatchConfig::reveal()` (a plain string passes through), call `->reveal()` on a `SecretValue` directly, or use `WatchConfig::plain()` to compare sections. `WatchConfig::secrets()` and `destination()` keep their signatures. `serialize()` of a config now throws, since a credential cannot be serialized.
- Removed: the `analysis.client = sdk` path. `SdkMessagesClient` and the `anthropic-ai/sdk` dev dependency and suggestion are gone, together with the `php-http/discovery` floor and its `allow-plugins` entry (nothing else used them). `analysis.client` is kept for compatibility but accepts only `http` (the default); `sdk` fails config load with `analysis.client: the SDK client was removed; use analysis.client=http (the default)`. Upgrade: delete `analysis.client = sdk` (or set `http`); nothing to install. `Factory::analyzerRequestOptions()` was removed with it.

### Fixed (security: F1, MCP ran a client-chosen PHP file)
- `watcher_validate_config` and `watcher_rollout_check` ran `require` on any `.php` file under the directory of the configured file, so a sibling file's side effects ran with the MCP process's rights. The path root is now the configured file itself: `PathPolicy::forFile()`. A sibling is `path_not_allowed` before any read (test: `ConfigFileValidatorTest::testASiblingPhpFileIsRefusedAndNotRun`).
- `watcher mcp --setup` (no configured file) had the project directory as its root, so the same tools ran any PHP file of the project. It now approves no path: both tools return `path_not_allowed` until a configuration exists. Behaviour change: validation in setup mode is off; re-enabling it needs the no-execute validator (option B, not done).
- The configured file itself still runs as PHP: at each server start (`watcher mcp`) and on `watcher_validate_config` for that file. The validator does not parse without running it; that is option B.

### Fixed (notifier response cap)
- The Slack, Discord and Telegram notifiers read the reply through `ResponseLimit` (the streaming cap every backend uses) at 64 KB (`Notifier::MAX_RESPONSE_BYTES`). A hostile or misconfigured endpoint can no longer make a delivery read an unbounded body. A declared oversize `Content-Length` and a chunked oversize body fail with `<driver> delivery failed: transport error`, with no body text. A slow reply under the cap is still bounded only by the 30 s timeout.
- The streaming cap now cuts a reply at once, for the notifiers and for every backend (`CappedSink` refuses the write over the cap, so curl ends the transfer instead of reading on to the end). Before, a trickling oversize reply from Loki, Datadog, Kubernetes, OpenAPI, Anthropic or the HTTP sink ran to the 30 s timeout first. `Retry` does not repeat a reply the cap cut. A body that a non-curl transport truncates is refused by its reported size.

### Fixed (F6: robust against bad remote and stored data)
- Loki and Datadog replies: a value the reader needs (a timestamp, a count, a point) is used only when its type and range are usable. Before, `(int) null` gave a 1970 log timestamp silently, and a huge or nested count was cast with a warning or read as 0. An unusable entry is skipped and counted as `malformed_entries` in `read_quality` of the run summary. A reply where more than half of its entries are unusable fails the read as `SourceUnavailable('malformed')`. A reply whose `data` is not a list fails the same way.
- A stored baseline snapshot with a group value that is not a count (text, a list, a negative or huge number) is treated as missing, as on a first run, and counted in `state_unreadable`. It used to abort the run with a `TypeError` in the baseline arithmetic. A corrupt previous snapshot is treated the same way before any other reader sees it.
- `Retry-After` and rate-limit hints are capped before they are cast: `1e400`, a 23-digit number or an integer hint beyond int range gave a warning and a zero wait.
- A `Content-Length` header is read as digits only and never cast as is; an oversized one trips the response cap without a warning.
- A stored event-ledger point with a digit run longer than 15 places is skipped instead of cast with a warning.
- A JSON log line with a `severity` beyond int range is read as its text level instead of cast with a warning.
- Config: a model name that is not a string and a Datadog `site` that is not a host name are refused as `InvalidConfig`. Both were already refused; the change pins that with tests.

### Documentation (security: F5, Redis over TLS)
- Checked, no code change: both Redis clients verify the server certificate by default. phpredis (`tls://` host) and Predis (`scheme` `tls`) refuse a self-signed certificate against Redis 8 with the system trust store (`certificate verify failed`); the password is never sent to such a server. A private CA is trusted through PHP's `openssl.cafile`; both clients connect with it. No `state.redis.tls.*` key was added, because nothing was missing.
- `docs/operations.md` ("Redis requirements"): use a `tls://` host outside a trusted network. On a plain TCP connection `AUTH` (the password and the ACL username) travels in clear text.

## 0.2.1 — 2026-10-26 — not tagged, shipped in 0.3.0 — Kubernetes events, schedule periods, incident workflow, log hygiene, MCP, observability sinks, API context, kill switch

This version was prepared but never tagged; its notes are part of 0.3.0. The tag `v0.2.0` was created on an earlier commit and lacks the kill switch, the API context, the hygiene fixes and the notes below.

- Changed (**behaviour change**): the master switch now short-circuits before validation. `run` (`bin/watcher`, `watcher:run`, `Watcher::fromConfig`) reads only the raw `enabled` key first; when it is not the boolean `true` the run returns `disabled` (exit 0) without validating the rest of the config and without building the state store, source, analyzer or notifier, so a broken or half-filled config (e.g. no `source.loki.url`) no longer exits 2 with the switch off. The Disabled summary carries `service` and `environment` when they look like names. Fail-safe parsing: only `true` is on; missing, `null`, `false`, `0`/`1`, strings (`'0'`, `'false'`, `'yes'`, `''`) and anything else is off (before, a non-boolean was an `invalid config` error; `check` still reports it). `--dry-run`/`--estimate` with the switch off are `disabled` too (as before). The same "build nothing" rule applies to `Factory::doctor` / `watcher check`, `Hygiene::fromConfig` / `watcher hygiene`, `FleetRunner::fromConfig` when the fleet switch is off, and MCP inspect tools (no configured store open; `watcher_dry_run` not offered). `status` and `state:get` still validate the whole config; `status` does not read the store when disabled. **Upgrade note:** a config that relied on `Watcher::fromConfig()` throwing for an invalid config while `enabled` was unset/false no longer throws; set `enabled => true` to validate.

The bullets below are grouped by feature.

### Fixed (`watcher hygiene` with a Kubernetes source)
- `watcher hygiene` with `source.driver: kubernetes` failed every pass with `LogicException` (the source needs its state store before it is read). The Kubernetes source is now bound to the pass's state store when the pass is built, and a `--dry-run` binds it to the shadow store and restores the real one afterwards, so the cluster ledger is written by no dry run.

### Documentation
- README, roadmap, spec status lines, `docs/operations.md` (several runs per day) and `docs/development.md` (portability notes) match the code of this release. Spec task lists (`tasks.md`) are not all reconciled: open items are named in each spec's status line.

### Fixed (protected groups keep their level)
- A finding for an error-or-higher group is never shown below that group's level, so a `warn` finding with low confidence no longer goes into the "minor" line. A cached `interesting` verdict whose finding sits below the group's level is not reused. Side effect: a low-confidence finding that is now `error` for an error-level group is escalated to the strong model (capped by `analysis.escalation_cap`), so such a run can make extra strong-model calls.

### Added (spec 008, Kubernetes events)
- `KubernetesEventsSource`: pod-level problems (crash loops, OOM kills, image pull failures, failing probes, restarts, HPA metric errors, evictions) as a third `LogSource`, read-only (`GET` on `events` and `pods` only, enforced by an architecture test), usable beside the main source (`events` => `['driver' => 'kubernetes']`) or alone (`source.driver: kubernetes`). Settings in the new `kubernetes` section (`namespaces` required, no "all namespaces"); flag `kubernetes.enabled` / `WATCHER_KUBERNETES_ENABLED` (default on). Setup, RBAC and limits: `docs/kubernetes.md`.
- An hourly ledger (state key `evt:<service>`, expiring) merges the events still in the cluster at every trigger, so the daily digest counts each occurrence once; a missed trigger is stated in the digest ("possible gap"). Trigger watcher at least hourly when using it.
- Pod names, node names, image registries/paths/tags, container ids and quoted resource names are masked before grouping (N pods of one workload are one group). Model payload gains `src`, `pods`, `wk` for event groups only; every other payload is unchanged. `watcher check` gains an `events` result (and a `warn` status, e.g. when an event exporter is also configured); `--json` gains an `events` block when configured.
- one ledger entry per cluster event object (no alternating counts); an entry still listed keeps its baseline; no false gaps from cron jitter; a failed due-hour read replays the hourly ledger with a notice; unquoted Secret/ConfigMap/key/volume/image names masked; node names after "assigned … to" masked; gone-pod suffixes collapse; probe level decided per message over the window; `ledger_ttl` bounded below; dependencies rejected with `source.driver: kubernetes`; pod events get no log links; the events notice names the service and never crashes a run.
- URLs and `user:password@host` are masked first, whole, before any Kubernetes rule (a password used to survive next to a masked image); unquoted images (`nginx:1.2.3`, `registry:5000/app`), container names, braces (taints) and unquoted Secret/ConfigMap/claim/volume names are masked; the client never follows redirects, fails closed in-cluster without `ca.crt`, refuses oversized bodies, pages pods 200 at a time with a byte cap, and gives each ingest a request and time budget; the ledger merge runs under a short lock; an object that predates the ledger (or `restartCount`) is credited once as a baseline instead of its lifetime count; metadata/loopback API server addresses are rejected; positional constructor calls for the new fields are now named.
- `kubernetes.api.token_file` (read at call time, so rotated tokens work; absolute regular file, at most 16 KB, not world-writable; exactly one of `token` / `token_file` / in-cluster) and a strict `kubernetes.api.ca_file` check (absolute, readable, non-empty, holds a PEM certificate) at load and on every call; an unreadable token or CA fails closed with the reason `credential unreadable`, never falling back to the system store and never naming a path or content.
- the Kubernetes response cap is enforced while streaming (`on_headers` refuses a `Content-Length` over 16 MiB, a counting sink cuts a chunked or gzip-decoded body at the cap, JSON depth 64), so a huge list can no longer exhaust `memory_limit`; the `token_file` checks repeat at every read, with the same rule as at load: the standard Secret / projected-volume symlink layout (`token -> ..data/token`) is accepted, a link that leaves the token's directory is refused, the opened handle must be a regular file owned by this user or root, not group/world-writable, at most 16 KB, in a directory nobody else can write (unless sticky); non-blocking open so a FIFO cannot hang a trigger.
- a failed ledger read is no longer silent without a logger: the heartbeat reason becomes `k8s_ingest_failed:<code>` and `watcher status` prints `reason=`.
- `LogEvent`, `ProblemGroup` and `RedactedGroup` gained defaulted fields (`occurrences`, `podId`, `origin`, `workloadKind`, `pods`). With no `events` configured, behaviour and output are unchanged.
### Added (spec 014 schedule periods, phase 1)
- `schedule.every` (`WATCHER_EVERY`: `PT1H`..`PT12H`) or a list of `schedule.run_at` times; `schedule.catch_up`, `schedule.ingestion_delay`; derived `runs_per_day` and multi-run defaults for `analysis.max_runs_per_day` / `analysis.run_budget_usd` and `heartbeat.stale_after` (single daily run unchanged).
- `RunGate::pending()` and `Factory::gate()`; `Watcher` picks the oldest undone pending window, clamps reads to the `hw:<service>` high-water mark after reported/quiet/baselined runs.

### Added (spec 005 incident workflow v1, phase 1–3)
- **Known issues**: `known_issues` maps fingerprint prefixes to ticket keys; compact digest line, skip model analysis below `growth_factor × ref`, `known_grown` candidate reason, labels for resolved/expired entries.
- **Severity routing**: `notify.routes` sends findings to extra destinations (first match + required catch-all); default digest shows a routed notice line.
- **Ticket drafts**: optional `drafts` posts paste-ready text per finding (`draft` message sections); no tracker writes.
- Config/env: `WATCHER_KNOWN_ISSUES_ENABLED`, `WATCHER_DRAFTS_*`, `WATCHER_ROUTES_ENABLED`. Weekly report (`report.weekly`) deferred (see `specs/005-incident-workflow/tasks.md`).

### Added (spec 014 schedule periods, DST grid)
- `every` boundaries follow local wall clock on DST transition days (nonexistent hours dropped, ambiguous hours use the first occurrence); `RunGateDstTest` covers Kyiv and New York spring/fall matrices (quickstart #24–#28).

- Fixed (review batch 2): `BufferedStateStore::flush()` no longer drops pending keys when an inner `put` fails mid-flush; Slack webhook delivery retries HTTP 429 like Telegram/Discord and names omitted Block Kit sections instead of truncating silently.

### Fixed (spec 006 second review)
- "gone" retractions are one line per site; only the retractions in the notice are marked as sent (a joined line used to be cut at 600 characters with all of it marked sent).
- A call-site id from a context `file` or frame needs a `repo.path_map` root (no root, no file site ids: the fingerprint stands in), no `..`, one stem and one known code extension (`.pdf.php` rejected), and no deny-listed directory below the root (`public`, `files`, `data`, ... ; `hygiene.site_allow_dirs` opts one back in).
- A site is "gone" after `max(repeat_days, 2)` complete quiet days, so weekly jobs no longer flip between gone and new.
- The pass lock lives `max_seconds + schedule.lock_ttl + 60` s; a failed release is noted and never replaces a successful result; `state unavailable (<Class>)` keeps the failing class (never the message) in the reason and `notes`.
- Watch-rule fragments carry no invisible format characters; the time-budget test pins the check cadence.

### Fixed (spec 006 review)
- Notices: every log-derived string is escaped like the digest's (no ping or link from log text in Slack, Telegram or Discord), sent as sections, capped at 25 sites and 10 templates; only sites shown are marked as sent; shape, proposals and cost are sent at most once per `repeat_days`; `notify.notice.enabled=false` is honoured.
- A site is a source file below no data directory; uploads, imports and documents fall back to the fingerprint. Integer-typed card/phone values are scanned; undecodable texts are counted (`unscannable`); unique file names cannot crowd real sites out of the table.
- Proposals ignore sampled days, keep every severe family in the day record, refuse watch rules with fewer than 8 literal characters; "gone" needs two complete passes on different days; `variable_distinct` has a floor of 5.
- A billed `run --dry-run` writes the cost ledger into a shadow; each fleet service books under its own key. One hygiene pass at a time (lock), a time budget (`hygiene.max_seconds`), memoised scanning; `hygiene` in `config/watcher.php`; `state unavailable` and `failure`/`notes` in `--json`.

### Added (spec 006, log hygiene: command and configuration)
- `watcher hygiene --config=<file> [--report=privacy,shape,proposals,cost] [--days=1-30] [--now=] [--notify] [--decline=<id>] [--json]` and `php artisan watcher:hygiene`. Reads one window through the existing source and measures it in code; **no model call, raw values never leave the process**: reports hold call-site identifiers, categories, levels, counts, shares, times and templates that were already allowed to leave. Details and limits: `docs/privacy.md` (hygiene) and `docs/operations.md`.
- New `hygiene` config section (all defaults in `config/watcher.example.php`); `hygiene.enabled` defaults to **false** and is not in `WatchConfig::FLAGS`. With it off nothing is added to the run path.

### Added (spec 006, log hygiene: cost report and rule proposals)
- **Rule proposals:** mute fragments (stable, high-volume, never at or above `selection.min_level`) and watch fragments (a family that repeatedly starts shortly before a severe one), with evidence and a replay through the real `MuteList`; declined proposals are quiet for `hygiene.decline_days`. Needs `hygiene.min_history_days` (14) days of daily passes.
- **Cost report:** tokens and cost per service and day from the run records (equals the sum of recorded runs), share of the daily caps, spike days with likely causes; calls per step and top spenders from a ledger written only when `hygiene.enabled`. Always available.
- State: `hyg:*` and `ledger:*` keys, all with the state retention.

### Added (spec 006, log hygiene: measurement and privacy/shape reports)
- **Privacy-hygiene report:** which log call sites (file:line from context `file`/`line` or the first frame, else the fingerprint) write e-mails, tokens, IP addresses, phone-like and card-like numbers, host names or other masked patterns, per level, with counts, since when and a trend (new / growing / stable / gone); templates that hide many different texts behind one sentence (possible names).
- **Log-shape report:** sites producing a large share of all lines, lines over a limit, traces pasted into messages, mixed formats, access-log lines at warning or above, each with a fixed-text proposal.
- `TemplateMiner::variability()` / `Reducer::variability()`: distinct masked messages per varying template (one integer per cluster); no change to clustering or templates.

- Changed (internal, no behaviour change): `Watcher::run()` is split into `RunLedger` (run bookkeeping, summary, liveness record), `Reduce\EventCollector` (the streaming read), `analyze()`, `buildDigest()` and `WindowKeys`; pinned by the golden scenarios in `tests/EndToEnd/RefactorGoldenTest.php`. `state_unreadable` is now filled in `RunLedger::finish()`, and `DryRunStateStore` forwards the real store's unreadable count.
- Added: a capped read keeps a newest share (A4-03). One fifth of `source.event_cap` (`ReadFilter::tailCap()`, 0 below a cap of 5) is reserved: the oldest-first read stops at 80%, then Loki (`direction=backward`) and Datadog (`sort=-timestamp`) read the newest events after the last one kept and yield them oldest to newest. Also after a spent `pages`/`time` budget (two more pages at most). If the backward read reaches back to where the forward read stopped, nothing was lost and the read is not reported as truncated.
- Changed: the daily budget is reserved atomically on Redis (M2). `BudgetReservation::allows()` now goes through `Spend::reserve()`, which on a store implementing the new optional `State\ReservableCounters` (Redis; the lazy store wrapper forwards it) runs one Lua script that checks the USD and token caps and adds the step's worst case with `INCRBYFLOAT` and `PEXPIRE` in the same script. `Spend::add()` settles it to the real cost (only the difference is added), `Spend::release()` gives it back when a call failed with no billed usage or the router ends; parallel runs of one service cannot both pass the check. File, PDO, dry-run and in-memory stores keep the plain check-then-book path. The fleet cap is still check-only. A reservation remembers the day it was made on: settled or released after midnight (configured timezone), the old day's counters get it back and the new day gets only the actual cost.
- Added: the `--json` summary carries the health counters with stable names, always present: `state_unreadable` (**behaviour change** for scripts: before it was present only when above 0, now it is always there with an explicit 0, so a `has("state_unreadable")` or grep test no longer means "entries were unreadable"; test the value), `unscannable_events`, `oversized_lines`, `overflowed_events`, `pattern_failures` and `read_quality` (`unknown_levels`, `orphan_trace_lines`, `message_tails_dropped`, `truncated_lines`, `truncated_structured`, `regex_failures`, `pages_shrunk`). `schema` stays 1 (additive); `JsonSummaryTest` pins the key list. Documented in `specs/002-operability-tools/data-model.md` and `docs/operations.md`.
- Added: a digest that sent events withheld as `<UNSCANNABLE>` says so in the read-quality line: `ℹ️ read quality: N events withheld (could not be scanned)`. Count only, once per digest. A sent digest carries this line instead of the separate `incomplete read: N events could not be scanned` notice; that notice is still sent for baseline and quiet runs, which have no digest.
- Fixed (review of lane J): the Datadog newest-share read shrinks its page and degrades to a truncated read when a reply is still over the response cap (it used to fail the run after the head was read); a Datadog event in the same millisecond as the seam is reported as a cut; the newest-share read no longer recounts oversized lines and frame lines the first page already counted; `Spend` takes a reservation before touching the store so a settle that fails between its two increments is never returned a second time.
- Added (spec 010, API context; **off by default**, **MINOR, upgrade note: none required**): an optional `api_context` config section reads one OpenAPI/Swagger JSON document (an absolute file path or an `https` URL), reduces it to six masked, capped fields per operation (method, template, summary or operationId, first tag, deprecated; mask first, then cut), caches the reduced spec in the state store with a TTL (key = source hash + reducer-settings fingerprint, a settings change is a miss), and attaches one `📖 GET /orders/{id} — Get an order [orders] (deprecated)` line under a finding whose masked template names a route of at most 2 segments, plus a short `ep` field in the analysis payload (analysis and escalation, never triage; the first context dropped when the input would not fit, with a prompt sentence only when `ep` is present). Source rules: `https` only; plain `http` needs `allow_insecure` and `allow_private_network` and a loopback, RFC 1918 or cluster-local host (same rule as `observe.http`, one shared classifier, link-local/CGNAT/metadata refused whatever is allowed); redirects off; a file source must be a canonical path (no symlink in any component, no world-writable ancestor without the sticky bit), judged on the opened handle, below `allowed_roots` when set; every refusal before the read is the single token `unreadable`. The reduced entry is budgeted against the real state codec (bytes, elements, containers) and degrades to "N operations not kept (cache limit)". A source failure is one `API context unavailable (<token>)` note (`unreachable|unauthorized|timeout|too_large|bad_format|unsupported_format|unsupported_version|unreadable|unsafe_source`), never a failed run. `watcher check` gains an `api_context` check (cache bypassed, no post, no tokens); the token and a URL source are masked in every notice (`WatchConfig::secrets()`) and held in closures (no dump shows them). Wired for single services and fleets. Not in this release: the `--json` counters of the feature (`api_context` object in the run summary) and an operator verification on a real framework document. See `specs/010-api-context/`, `docs/configuration.md#api_context`.

Lane G. **MINOR, upgrade note: none required**: every sink is off by default and with none enabled a run is byte-identical to before (the JSON summary gains `run_id` and `sink_failures` only when a sink was flushed).

### Observability sink (spec 013)
- Event stream `watcher.event.v1` (`docs/schema/watcher.event.v1.schema.json`, checked against a code allowlist by a drift test): `run`, `finding`, `group`, `budget`, `heartbeat`, `delivery_failure`, `config_warning`. Counts, outcome codes, hashed ids and timestamps only; finding text only with a per-sink `include_text`, after the digest's own scrub. A planted secret and e-mail never reach any sink, summary, host log or failure message (privacy test over all four sinks).
- Sinks `observe.log` (JSON lines to stderr or stdout), `observe.file` (0600, size or daily rotation, retention, no symlink, hard link, FIFO or foreign-owned file, private directory), `observe.http` (NDJSON POST; https, http only for loopback, private or cluster-local hosts with `allow_insecure`; shared `SecureUrl` rule; no redirects; response limit; token and URL never in a message; bounded by the run deadline) and `observe.prometheus` (textfile 0.0.4, closed label set, at most 48 series per service, atomic replace). All fail open: a sink failure is counted and shown (summary, next heartbeat event, `watcher_sink_failures_total`, `watcher status`) and never changes the outcome or exit code.
- `notify.digest.driver: none` for dashboard-only mode (needs at least one sink).
- Fleet: `observe` at fleet level or in `defaults`, a service's own replaces it as a whole, one Prometheus file per service.
- `watcher check` gains an `observe` check; `--post-test` sends one `check_test` event; `--dry-run` prints the events; `--json` keeps stdout to one line.
- `docs/observability.md` and a generic Grafana dashboard `docs/dashboards/grafana-watcher.json` (Loki and Prometheus datasources and the stream selector are template variables, no uid, schemaVersion 39), with the Loki `| json` queries for the zero-dependency path.
- Security: the sink credentials are taken out of the config array before validation and are `#[SensitiveParameter]` end to end (`Watcher::fromConfig`, `ObserveSettings`, `HttpTarget`, `HttpSink`, `SecureUrl::problem`); `WatchConfig::secrets()` covers the sink URL and token and lists the longest secret first.
- Dev dependencies `opis/json-schema` and `symfony/yaml` (schema and rule-file contract tests only).
- Known limit until the shared `ResponseLimit` streaming cap is merged: the `http` sink refuses a response whose declared `Content-Length` is over the limit and is otherwise bounded by its timeout (at most what the run deadline leaves); a chunked or compressed body without a length is only time-bounded.
- Owner decisions after the review: `include_text` is off by default for every sink including `log`; with a sink enabled `service` and `environment` must match the event schema patterns (refused at load, not silently nulled).

Lane E-009, planned for the next MINOR (the integrator assigns the number). **Upgrade note: none required**: `metrics` and `sentry` are absent by default and with neither configured a run is byte-identical to before.

### Metrics, Sentry issues and trace-link builders (spec 009)
- Added: optional `metrics` section (Prometheus, directly or through the Grafana datasource proxy): up to 6 declared queries, each reduced to one number for the window and one for the previous window of equal length, shown as one digest line (`Metrics: p95 latency 1.2 s (was 0.4 s, +200%)`). A metric move over its `threshold` that no log or Sentry candidate at Error or above explains becomes a candidate of its own (`metric_anomaly`, at most `max_anomalies` per run, a stable `metric:` fingerprint, deduplicated like any group). Labels are dropped at the boundary; only `label_allowlist` names survive, at most 5 values, masked twice. Not read on the baseline run.
- Added: optional `sentry` section: the unresolved issue list of one project (one GET per page, at most `max_issues`), allowlisted fields only (nine named keys, nothing else is read into an object), as ordinary groups with fingerprint `sentry:{id}`; the title and culprit are masked, then cut (300/120), and withheld for a rare issue. A Sentry issue and a log group for the same problem stay two entries; the digest links the issue (`<base_url>/organizations/<org>/issues/<id>/`, built from config, never fetched).
- Added: failures never cost the log findings. An unreadable backend becomes one digest note with a fixed token (`Metrics unavailable (timeout)`, `Sentry unavailable (window_too_old)`; tokens: `unreachable`, `unauthorized`, `timeout`, `bad_response`, `rate_limited`, `too_large`, `window_too_old`), issues that could not be read honestly are counted (`N Sentry issues dropped`), and `N metric anomalies not shown` states a cut. `Notify\DigestExtras` (trailing `DigestData::$extras`) carries this; empty extras leave the digest byte-identical.
- Added: both sources share `Source\GuardedRequest`: no redirects, TLS verified, `ResponseLimit` (16 MB, JSON depth 64), a 60 s wall-clock budget per call (`Factory::SOURCE_BUDGET_SECONDS`) and the run's stop hook (`Watcher::stopReason()` = `RunGuard::reason()`), checked before every attempt and page. Failures carry a fixed token, never a URL, token, query or body. A response without `Content-Length` is only bounded by that budget until the streaming cap of `ResponseLimit` lands (lane C, see `GuardedRequestStreamingCapTest`).
- Added: URLs go through the one shared classifier, `SecureUrl::problem($url, $allowInsecure, $refuseInternal, $allowPrivate)` and `SecureUrl::internalHost`. An internal Prometheus or Sentry in RFC 1918 space or a cluster-local `*.svc` name needs `allow_private_network: true` on that section (default `false`); loopback needs `allow_insecure: true`; link-local, CGNAT, metadata and reserved ranges stay refused whatever is allowed. Datasource uid, organisation and project are slugs (`Source\Slug`, no `..`). Every strict validator uses `\z` semantics.
- Security: `metrics.source.auth.token` and `sentry.auth_token` are taken out of the config array before validation, are `#[\SensitiveParameter]`, live in closures (`MetricsSettings`, `SentrySettings`, `PrometheusSource`, `SentryIssueSource`: no secret in `var_dump`, `print_r`, `var_export`, `serialize`, `json_encode`) and are covered by `WatchConfig::secrets()` (one longest-first list). Neither source feeds the observability sinks: counts and the allowlisted fields only (privacy test over all sinks, the model input, the summary and the host log with a planted e-mail, secret and personal id in an issue title, a culprit and a label value).
- Fixed (review of lane E-009): a ratio that overflows (tiny baseline) is `null`, never INF: the run no longer fails, the digest says `change not computable`; labels in the digest line must be `[A-Za-z0-9_.:-]{1,64}`; log groups at Error or above are never displaced by anomaly or Sentry groups in `top_n`; `--dry-run`/`--estimate` name the external reads they made (`docs/operations.md`).
- Known limit: a Sentry or metric-anomaly group reaches the observability sinks with a `null` fingerprint (the sanitizer only passes 12-hex fingerprints); counts and level are kept.
- Added: the model receives the metric numbers: an `m` section (`n` names, `v` `[current, baseline]`, `u` units, `r` relative change; at most 3 metrics, anomaly metrics first) added to the payload after redaction from the measured values (so digit masking cannot touch them, and no label value, issue title or query can reach it), `mi` on a metric-anomaly group, `Budget::fitMetrics` against the analysis input budget, and a system-prompt paragraph (`prompts/metrics.md`) that is sent only with `m` (without metrics the prompt and payload are byte-identical). Triage sees `m` only when its batch holds an anomaly. New optional trailing arguments on `TriageRequest`, `AnalysisRequest`, `RouterSettings`, `CompactPayload::encode`, `Budget::systemPrompt/fixedTokens`.
- Added: `--json` summary objects `metrics` (`read`, `failed`, `anomalies`) and `sentry` (`read`, `dropped`, `merged`, `failed`), present only when the section is configured (additive; `schema` stays 1).
- Added: `watcher check` rows `metrics` (one `vector(1)` query) and `sentry` (one issue read) when configured; the failure reason is a fixed word. Fleet: each service builds, runs and is checked with its own metrics and Sentry sections.
- Added: trace links (`links.tempo`, `links.datadog_apm`): the earliest valid trace id of a log group (optional `Reducer` per-event observer) goes only inside the digest's link URL; a group without one gets a service-search link. Nothing is fetched.
- Added: `docs/examples/metrics-queries.md`.
- Known limits: a Sentry or metric-anomaly group reaches the observability sinks with a `null` fingerprint (the sanitizer only passes 12-hex fingerprints); the anomaly's name reaches the model as the Redactor masks it (`p95` shows as `p<N>`) while the `m` section carries the exact name; the Tempo/Datadog URL shapes and the Grafana proxy path are UNVERIFIED against live systems (T052).
## 0.2.0 — 2026-10-26 — superseded by 0.3.0

The tag `v0.2.0` points at an earlier commit of `main` (before the kill switch and the last lane merges). Use `0.3.0`.

## 0.1.3 — 2026-10-24 — Redis state and own Anthropic client

Product release label **v0.1.3** (`VERSION`). Redis becomes the primary state store, the Anthropic client is built in, the SSRF host classifier is shared, PHPStan moves to 2.x and Guzzle 8 is accepted.

**BREAKING / UPGRADE**
- Anthropic SDK is now optional. `anthropic-ai/sdk` (and `php-http/discovery`, with its Composer plugin prompt) left `require`; the default `analysis.client = http` needs neither. Consumers who relied on the SDK being installed must `composer require anthropic-ai/sdk` and set `analysis.client = sdk`, or switch to `analysis.client = http` (the default).
- New `state.redis.prefix` (`WATCHER_REDIS_PREFIX`, default `watcher:`, unchanged): set it to keep environments that share one Redis database apart. Existing keys keep working with the default.
- A decoded Redis state value is capped at 4 MB (also refused at write with `StateValueTooLarge`); large values are stored compressed (`z1:` + zlib). A version before 0.1.3 reads `z1:` values as missing: set `compress_min_bytes` to `0` before rolling back (`docs/operations.md`).
- A Redis TTL of 0 or less now throws instead of being clamped to 1 second.
- `guzzlehttp/guzzle` constraint widened to `^7.15.2 || ^8.3` (additive for consumers on 7).
- `phpstan/phpstan` is `^2.3` (dev only).

### Redis state (Redis becomes the primary store)
- Added: Redis is the default and recommended state driver (shipped `config/watcher.php` and the example use it). New `state.redis.client` (`WATCHER_REDIS_CLIENT`: `auto`, `phpredis`, `predis`): `ext-redis` when loaded, else `predis/predis` (`^2.0 || ^3.0`, a `suggest`); an injected `\Redis`, Predis client or `Watcher\State\Redis\RedisClient` still wins as `state.redis.instance`. `username` (ACL) and `tls://` hosts work with both clients. Kubernetes guidance in `docs/operations.md` ("Where to keep state").
- Added: compression of large values written with `put`: above `state.redis.compress_min_bytes` (default 512, `0` = off) a value is stored as `z1:` + zlib. Counters and locks are never compressed; a decoded value is capped at 4 MB (`StateCodec::MAX_BYTES`; inflated in 1 KB slices with a running cap, JSON depth 64, so a decompression bomb costs at most the cap) and an unreadable entry (corrupt, truncated, over the cap) reads as missing and is counted as `state_unreadable` in the JSON summary. Old uncompressed values keep reading. Rollback warning: a version without this change reads `z1:` values as missing (run records and baselines are lost for that window, no crash); before rolling back set `compress_min_bytes` to `0`, wait one retention period or delete the package's keys by prefix, then roll back (`docs/operations.md`, "Rollback / disabling"). Needs `ext-zlib`; without it nothing is compressed and `watcher check` warns.
- Added: `watcher state:get <key>` (Laravel: `watcher:state-get`), a read-only view of the package's own state keys with strings redacted and long values cut; any other key is refused (exit 2), nothing stored is exit 1.
- Added: `watcher check` warns (still `pass`) when the driver is `file` and `KUBERNETES_SERVICE_HOST` is set, because a pod's local disk loses history with the pod.
- Changed: every Redis write carries a TTL by construction, in milliseconds (`SET ... PX`, `PEXPIRE`), through one guarded writer; a zero or negative TTL throws. The spend counter is a single Lua script (`INCRBYFLOAT` + `PEXPIRE`) so a key can no longer exist without an expiry. `RedisStateStore` still implements `RenewableLock`.
- Changed: an injected phpredis client that uses a serializer is refused (`state.redis.instance must not use a serializer`; it broke lock renew and release); the Redis password no longer appears in exception trace arguments (`#[\SensitiveParameter]`); `watcher state:get` exits 1 with `state unavailable (<class>)` when the store is down; configured secrets are masked longest first, so overlapping secrets leave no suffix.
- Changed (review of the Redis branch): the decoded structure is budgeted before `json_decode` (4 MB, 100,000 elements, 16,000 arrays/objects; a `[0],` bomb is now reported unreadable instead of an uncatchable memory fatal), a value the reader would refuse is refused at write (`StateValueTooLarge`), trailing bytes after a zlib stream are always refused, a failed `GET` fails closed (phpredis `false` with an error, Predis error object), an injected phpredis with compression is refused, `state.redis.prefix` (`WATCHER_REDIS_PREFIX`, default `watcher:`) separates environments on one database, and a lost run lock stops the run (`lock_lost`) instead of only logging. Documented: `maxmemory-policy noeviction`, and that the daily budget check is check-then-add, so parallel runs of one service can overshoot it by up to one step each.
- Fixed: `state.redis.read_timeout` is now accepted by config validation next to the new keys.

### Own Anthropic client (lane H)
- Own Anthropic client (lane H), review fixes: the `check` models probe no longer follows redirects (the `x-api-key` header would travel with them), verifies TLS and bounds the reply; non-integer or negative cache token counts in a model reply are an invalid reply instead of silently counting as 0.
- Own Anthropic client (lane H), step 3: `analysis.client` (`http` default, `sdk` rollback) picks the client in `Factory::analyzer`; `sdk` without `anthropic-ai/sdk` installed is an `InvalidConfig` naming `analysis.client` at config load.
- Own Anthropic client (lane H), step 2: `AnthropicHttpClient` (Guzzle, fixed `https://api.anthropic.com/v1/messages`, `x-api-key`, no redirects, TLS verified, bounded reply, no client retry, errors name only the class or HTTP code) with recorded fixtures for 401, 429, 500, 529, refusal, max_tokens, pause_turn and a non-JSON body. Not wired yet.
- Own Anthropic client (lane H), step 1: the analyzer now talks to a `MessagesClient`; the SDK call moved unchanged into `SdkMessagesClient`. No behaviour change.

### Security and dependencies
- `SecureUrl::internalHost()` is the one SSRF host classifier, opt-in through `SecureUrl::problem($url, $allowInsecure, refuseInternal: true, allowPrivate: false)`; Loki and Slack validation is unchanged. It refuses loopback, 0/8, RFC 1918, link-local, 100.64/10, multicast and reserved IPv4 in every numeric spelling, and IPv6 ::/96, ULA, link-local, site-local, multicast, NAT64 (both prefixes), Teredo, with the IPv4 inside v4-mapped and 6to4 addresses classified. It also refuses `*.svc`, `*.cluster.local` and `*.internal` names (admitted by `allowPrivate`), `instance-data`, 192.0.0.0/24, 192.0.2.0/24, 198.18/15, 198.51.100/24, 203.0.113/24, SIIT `::ffff:0:0:0/96` (embedded IPv4 decoded) and any non-ASCII host. One trailing dot is stripped; an empty label, a `%zone` or an unparsable ':' host is refused.
- Guzzle 8 is accepted next to 7 (`^7.15.2 || ^8.3`). `Retry` no longer calls `RequestException::hasResponse()` (removed in 8) and the Anthropic model catalog reports any PSR network failure as `timeout` (8 splits a read timeout out of `ConnectException`). CI's lowest leg covers Guzzle 7.15.2, the locked leg 7.16, the highest leg 8.3.

## 0.1.2 — 2026-10-24 — Security release

Product release label **v0.1.2** (`VERSION`). Security release: supply chain, fail-closed model use, availability guards, input hardening and the regression fixes found by the review of this very release.

**BREAKING**
- PHP 8.1 is no longer supported: `composer.json` requires `^8.2` (8.1 left security support in December 2025). CI tests PHP 8.2 to 8.5.
- `http://` Loki and Slack URLs are refused unless `allow_insecure: true` is set and the host is loopback, an RFC 1918 address or a cluster-local name (`*.svc`, `*.cluster.local`). Plain `http` to a public host is never accepted. In-cluster options: `docs/operations.md`.
- Redis transport is NOT included in this hardening: use a `tls://` host and an ACL `username`; a plaintext Redis stays plaintext.

### Regression fixes (review of the hardening)
- A page over the response cap no longer fails the source: the same cursor is re-read with half the page size (down to 100 lines), the digest read-quality line counts it (`pages_shrunk`), and the cap is `source.max_response_mb` (default 16, max 64). A declared `Content-Length` over the cap still aborts at the headers.
- The file state directory may be group-writable (`0775` Laravel `storage`); only a world-writable directory without the sticky bit, or one the running user does not own, is refused, with a message naming `state.file.path` in the run notice and in `watcher check`.
- A window cut by the run deadline or the memory guard is no longer recorded as done: the next trigger reads it once more with a full budget; a second cut is recorded final (`truncated:deadline`) with a loud notice. A fleet's equal time share is floored at half of the smallest `lock_ttl`.
- The advice denylist (`[withheld]`) runs on the canonical text (zero-width, fullwidth, percent- and entity-encoded forms) and covers kubectl, systemctl, service, iptables, ufw, curl/wget uploads, nc -e, dd, shutdown/reboot, killall/pkill/kill -9, `> /dev/...`, TRUNCATE/DELETE FROM, git push --force, docker rm/stop/kill, base64 -d, python/perl -c/-e.
- Structured lines over 64 KB become a placeholder event that keeps their level instead of vanishing; dropped plain over-long lines are counted in the digest read-quality line.
- A triage verdict the model omitted is treated as `interesting` (fail closed, counted in the existing digest line) instead of failing the run; duplicates, unknown fingerprints and malformed JSON are still invalid output.
- `allow_insecure` also admits private-network and cluster-local hosts (explicit opt-in, a documented trust decision).
- Tests: the Redis renew cases use real TTLs; CI fails when `WATCHER_TEST_REDIS` is missing; five surviving mutants are pinned (headers abort, fatal guard wiring, anomaly-pass deadline, Datadog redirects, Loki labelled flag).

- Notices and digests (Lane D review leftovers): model text can no longer format (`*`, `_`, `~`) or spoof `:emoji:` on any channel; IDN, IDNA-dot and `xn--` hosts are defanged while code identifiers such as `Mailer.send` stay copyable; failure notices mask every configured credential (`WatchConfig::secrets()`) and Authorization-like values; the digest gets a "read quality" line (unknown levels, orphan trace lines, cut multi-line messages) from the optional `ReadDiagnostics` interface (Loki, Datadog); `Retry` no longer chains Guzzle exceptions (their message carries the URL). 

### Security
- File state store (TOCTOU): records and the purge marker are written to a random-named, exclusively created temp file (umask 0077) and renamed into place, never opened by their final path; lock sidecars are created by hard-linking a private temp file and opened only after `lstat` shows a regular file we own, then verified with `fstat` (device, inode, link count 1) against a fresh `lstat`. A symlink or hard link planted by another user of a group-writable state directory fails closed and no target is truncated or created.
- Raised dependency floors to versions that are advisory-free and that CI actually passes on: `guzzlehttp/guzzle` ^7.15.2, `psr/log` ^2.0 || ^3.0 (the 1.x line was incompatible with `StderrLogger`), and `php-http/discovery` ^1.12 is now a direct requirement (the SDK alone allowed 1.9, which breaks it).
- CI also tests the lowest (PHP 8.2, `--prefer-lowest`) and highest (PHP 8.5) dependency sets, because consumers ignore `composer.lock`, and audits the resolved runtime set.
- GitHub Actions are pinned to commit SHAs, checkouts no longer persist credentials, the Composer cache is exact-key only, Dependabot is enabled for Actions and Composer.
- The release workflow refuses a tag that is not on `main`, marks `-rc`/`-beta` tags as pre-releases, verifies the tag and attaches a build-provenance attestation for the source archive.
- README: decline the unneeded `php-http/discovery` Composer plugin (`allow-plugins.php-http/discovery false`).
- Added `SECURITY.md`, CODEOWNERS, secret-scanning path exclusions and a repository-settings checklist; documented the supported PHP/Laravel matrix.
- The pre-commit hook handles file names with spaces and globs (they were skipped by the secret scan) and refuses an empty or blocklisted author e-mail.
- `bin/watcher` exits with a clear error when no Composer autoloader is found.
- Input hardening (security reviews 1 and 2): credentials are masked by key name (`password=`, `secret`, `token`, DSN, JSON, query, `X-…-Token` headers) regardless of length or repetition; text is canonicalised before the rules (bounded fixed point: format characters removed, NFKC when ext-intl is present, ideographic dots, nested percent and HTML-entity decoding); IDN hosts, `user@host`, quoted local parts and relative-URL query values are masked; a line that cannot be scanned becomes `<UNSCANNABLE>` per event (`Normalizer::normalizeOrUnscannable`) and its group shows no text; lines are cut at 8 KB and records bounded (200 lines / 64 KB), split tokens and unparseable structured tails are withheld, only continuation-shaped lines continue a record, regex failures are visible and counted (`truncated_lines`, `truncated_structured`, `regex_failures` in the digest); host logs carry the exception class plus a masked message; Slack webhook URLs are validated at run time; `SlackText::plain` no longer restores control sequences; the stderr logger strips terminal control characters; Slack, Loki and Datadog requests never follow redirects and verify TLS; Redis accepts an ACL `username`; config objects dump as `[redacted]` and secret parameters are `#[\SensitiveParameter]`; validators use the `D` flag; privacy docs corrected for retention, fail-closed, disk, host and email masking and fleet output. **Breaking:** `http://` Loki and Slack URLs are refused unless the host is loopback and `allow_insecure` is set.
- Model use hardened (security review 5): an error-or-higher or watch-rule group can no longer be dropped or settled by a model; triage verdicts for such groups are overruled and an analysis that reports nothing for them is not remembered; triage must return exactly one verdict per group; the model input is wrapped in nonce-tagged untrusted-data delimiters and templates are capped at 300 characters; `related_checks` accepts only exact fingerprints or declared dependency names and command-shaped or URL text in advice becomes `[withheld]`; finding severity and kind are bounded by the logged level; the budget reservation covers both attempts of a step; a model refusal is not retried and the window is recorded as failed (`analysis_refused`), the second failure of a window also stops retries; `top_m` ranks by watch rule, level, growth, then novelty (capped) with a salted tie-break. New digest lines say when triage was overruled, the analysis reported nothing, new groups were capped or earlier verdicts were reused.
- Availability and state (security review 4): `schedule.run_deadline` (`WATCHER_RUN_DEADLINE`, default `lock_ttl` minus a margin) stops reading and skips the model at expiry and says so; the run lock is renewed between steps and before each model call; reading stops at 80% of `memory_limit`; template storage is capped (64 tokens per message, `grouping.memory_budget_mb`); a shutdown handler releases the lock after a PHP fatal; quiet runs that were truncated, overflowed, dropped oversized lines, hit unscannable events or read nothing for three windows send one counts-only notice; Loki paging has a page cap, a wall-time budget and a cursor guard; lines over 64 KB are dropped; backend replies over 16 MB or deeper than 64 JSON levels are refused; the file state store refuses symlinks and group- or other-writable directories and writes 0600; PDO retries a first-write duplicate-key race once and refuses keys over 191 characters; `state.redis.read_timeout` added; failure notices are capped at 3 per window and a delivered digest is not re-sent after a failed state write; fleet services get `fleet.service_time_budget`; dry runs show cumulative in-memory spend and refuse to start when the daily cap is spent; `@` is no longer allowed in `service`.

### Docs
- Installation: note about the `php-http/discovery` Composer plugin prompt (a dependency of the Anthropic SDK).

### Changed (spec 008 rebased on the Lane A split and the 0.1.2 hardening; temporary block, the integrator assigns the release number)
- The Kubernetes masker runs after `Normalizer::canonical` and masks credentialed tokens whole on their canonical form (fullwidth, zero-width, `%40`, quoted, bracketed, `docker://`/`containerd://` userinfo, passwords holding `%3E`, `&quot;`, `&#32;`); its skip rules end at a real word end.
- `WatchConfig::secrets()` includes `kubernetes.api.token` and the current content of `token_file`; `KubeSettings` no longer dumps or serialises its token.
- The trigger-time cluster read runs under the `RunGuard` (run deadline, memory stop); an unread cluster is named in the summary reason and the heartbeat.
- `kubernetes.api.server` follows the `SecureUrl` rule: https, or http for a trusted network with `kubernetes.api.allow_insecure: true`.
- The streaming response cap (decoded bytes) now lives in `ResponseLimit::options()` and protects Loki, Datadog and Kubernetes alike.
- Coverage and a failed latest read reach the digest as `ReadDiagnostics` when Kubernetes is the main source.

### Changed (spec 008, second review)
- The pod-event ledger is bounded to half of the state codec's budgets (flat per-entry points, overflow counted and shown); a 200 answer that is not a list is an unreadable cluster; the masker matches pod names in one pass per read and the mapping runs under the RunGuard; credentials are held in objects no dump or export prints; the in-cluster service-account token is masked; exotic quotes and invisible characters next to a keyword no longer defeat the masker.
### Changed (spec 006 rebased on develop; review fixes; temporary block, the integrator assigns the release number)
- `watcher hygiene` scans through `Normalizer::normalizeOrUnscannable`, sends notices with develop's `SlackText` (one section per line, no private cap or split), runs under `RunGuard` (max_seconds and the memory stop) and renews its lock when the store can; the source's `ReadDiagnostics` become a note.
- Control, format and invisible characters are removed from every string the scan reads before any mask runs; templates are shown, stored and proposed as watch rules only when they generalise `hygiene.variable_distinct` messages; an empty read is not a complete day; failed cost-ledger writes are counted and shown as `unbooked`; site-id deny list extended; `--decline` takes the pass lock; anonymous exception classes no longer leak a file name.

### Added (spec 012, feature flags and environments)
- **Breaking (opt-in):** the master switch `enabled` (`WATCHER_ENABLED`) defaults to **false**. A config without it makes `run` return the new outcome `disabled` (exit 0, `watcher: disabled (set enabled=true / WATCHER_ENABLED=1)` on stderr; nothing external or stored is touched, not even a heartbeat). Add `'enabled' => true` (or `WATCHER_ENABLED=1`) to every existing config, fleet entry or fleet `defaults`.
- One boolean flag per feature, in the config array and as a `WATCHER_*` variable, all defaulting to on (today's behaviour): `heartbeat.enabled`, `purge.enabled`, `dependencies.enabled`, `deploy.enabled`, `links.logs.enabled`, `links.code.enabled`, `service_context.enabled`, `watch_rules.enabled`, `mute.enabled`, `level_anomaly.enabled`, `selection.escalation.enabled`, `analysis.triage.enabled`, `baseline.enabled`, `grouping.enabled`, `budget_alerts.enabled`, `notify.notice.enabled`, `notify.digest.enabled`, fleet `enabled`. Table: `docs/configuration.md`. A switched-off feature does nothing. Flags are strict booleans (`'false'` is rejected with the key path).
- `environment` (`WATCHER_ENVIRONMENT`): state keys become `<service>@<environment>`; shown in the digest header and the JSON summary. Fleet validation rejects a duplicate `service@environment`.
- `Watcher\Config\Env` (`bool`, `int`, `string`, `float`, `list`) for plain-PHP configs; an invalid value throws `InvalidConfig` naming the variable, never its value. Used by `config/watcher.php`, `config/watcher.example.php` and `docs/examples/*.php`.
- `status` reports `disabled` (exit 0, no store read) for a disabled watcher or heartbeat; `check` validates the config of a disabled watcher and skips the rest; in a fleet a disabled service is `disabled`, not an error.
- `service_context` also accepts `['enabled' =>, 'text' =>]`; `notify.notice` may be `['enabled' => false]` alone. New config sections: `purge`, `level_anomaly`, `budget_alerts`.
- No flag for redaction, the normalizer or the allowlist (architecture test) and none for read-only behaviour; `analysis.enabled` is not provided.
- With `enabled` true and no other new key, output and state are unchanged.

## 0.1.0 — 2026-10-24

First public release (pre-1.0: the API may change; not yet validated against live systems).

everrest/watcher reads one service's logs on a schedule (Loki or Datadog), fingerprints errors into groups, decides in plain code which deserve attention, asks Anthropic models (triage / analyze / escalate) to judge only those, and posts one digest to Slack, Telegram or Discord. Read-only: it never acts on a service and never stores raw log lines.

- Scheduled digest, privacy allowlist and redaction, cost control with daily caps (spec 001).
- Operability: `check`, `status`, `run --dry-run [--estimate] [--json]` (spec 002).
- Fleet mode and cross-service correlation (spec 003).
- Advice on findings: `how_to_check` (what to look at to confirm or refute the hypothesis, never reproduction steps) and `related_checks` (other groups or declared dependencies, entries citing anything not in the model input are dropped), shown as `Check:` and `Related:` in Slack, Telegram and Discord through the existing escapers; flag `analysis.advice.enabled` (`WATCHER_ADVICE_ENABLED`, default on). About +100 output tokens per finding.
- Rolling and weekday baselines, better message templates (spec 004).
- Telegram and Discord notifiers (spec 011).
- Feature flags and environment toggles (spec 012).
- Laravel/Lumen adapter with auto-discovery; framework-free CLI `vendor/bin/watcher`.

Pre-1.0: the API may change between minor versions.

### Added (tooling)
- `composer test` runs ParaTest in parallel (`composer test:serial` keeps plain PHPUnit); `composer coverage` / `make coverage` write Cobertura, Clover and HTML reports under `build/coverage/` (pcov or Xdebug).
- README badges (CI, last successful release, version, PHP, coverage, license) generated by `scripts/badges.php`, refreshed by the pre-commit hook; `make badges`, `make test-serial`, `docs/development.md`.
- CI: Composer cache, `composer validate --strict`, coverage job with artifact; `release.yml` creates a GitHub Release for a `v*` tag only after the full gate passes.

### Added (spec 011, more notification channels)
- `notify.digest` / `notify.notice` accept `driver: slack` (default, unchanged), `telegram` (`bot_token`, `chat_id`, optional `thread_id`) or `discord` (`webhook`). Digest and notices may go to different channels; a digest failure is announced on the notice channel when it is another destination.
- Long digests are split at section boundaries (Telegram 4000, Discord 2000 characters, at most 10 posts, numbered `(i/n)`); a 429 waits the stated `retry_after` within `min(60, lock_ttl / 10)` seconds. Model text is inert on both channels (no live markup, links or mentions; Discord `allowed_mentions.parse` is `[]`).
- Fleet: a service's `notify.digest` / `notify.notice` replace the fleet default wholesale; `digest: combined` needs a `notify.digest` of any driver.
- `watcher check` validates each destination's shape per driver (no network, no value printed); `--post-test` posts one labelled message per distinct destination.
- Bot token, chat id and webhooks are secrets: masked in failure reasons and the stored heartbeat, absent from logs, summaries and exceptions. New egress hosts: `api.telegram.org`, `discord.com` (`discordapp.com`).
- MINOR, additive; Slack configs need no change and there is no upgrade step. Internal: `Factory::notifier($http, $lockTtl = 900)` returns a `RoutingNotifier`; `WatchConfig::destination()`, `notifySecrets()`, `destinationFrom()`.

### Added (spec 004, baselines and templates; backend patterns deferred)
- `baseline.*` config: `previous` (default), `rolling` and `weekday` comparison modes, `windows`, `max_age`, `exclude`, `since`; digest lines name the basis and any fallback; new group status `expected`.
- `grouping.*` config: similarity-based template clustering (`similarity`, `message_cap`); digest notices `Grouping limit reached` and `Grouping time budget reached` (counts only). Fingerprints are unchanged; templates may read differently.
- DST-safe candidate windows (`RunGate::endBack`), `start` stored in snapshots, missed-day tolerance.
- Not included: `grouping.backend_patterns` (T023) stays rejected until the owner decides.

### Added (spec 003, fleet mode and correlation)
- `watcher fleet:run`, `fleet:check`, `fleet:status` (Laravel/Lumen: `watcher:fleet`): several services from one job over one state store and one analyzer, each isolated, exit code = worst (`skipped_time` and failed fleet posts are exit 1). Config: `fleet`, `services`, `defaults`, `budget`, `digest`, `notify`, `time_budget`, `correlation`, `timezone`; example in `docs/examples/watcher-fleet.php`.
- Shared fleet budget (fleet cap checked before every model call, notices that name the cap, fleet thresholds 25/50/75/90) and `digest: combined` (one message for the whole fleet).
- Cross-service correlation: a consumer finding whose template references a service that has a new or grown finding in the same window is annotated `likely caused by the <origin> finding <fingerprint> (hypothesis)`, and one additive message names the origin and its consumers. Decided in code, no model call; nothing is withheld from any digest; a failed correlated post is loud (host log, exit 1).
- `fleet:run --dry-run` now also prints the combined digest and the correlated finding.
- Internal: `Watcher` takes an optional `FleetSeam` (budget, annotations, dry-run digest hold); `DigestData::$annotations`. Single-service behaviour is unchanged.

### Changed
- Privacy hardening (review P1-P4, owner decision): frame paths are rewritten before they are sent (below a `repo.path_map` root, mapped to the repository path; otherwise from the first `vendor`/`app`/`src` directory, last 3 segments); digit runs inside identifiers are masked unless well known (`Http2`, `utf8`, ...); `extra_patterns` run on the raw message before digits become `<N>`; a JSON `message` keeps only its first line; absolute paths inside messages become `<USER>` / `<PATH>/…/<last two>`. `PATH` and `USER` are now reserved `extra_patterns` names. **Fingerprints and templates of messages containing paths or matching `extra_patterns` change once; no live state exists, so nothing needs migrating.** `CodeLinkBuilder` accepts the repository-relative frames the Redactor now emits.

### Added
- Spec 002, operability: `watcher check [--post-test] [--json]` (doctor over source, provider auth, models, prices, state, notifier), `watcher status [--json]` (heartbeat age and state; exit 0 ok, 1 stale/failed, 3 unknown), `watcher run --dry-run [--estimate]` (nothing posted or recorded; `--estimate` makes no model call) and `--json` (one summary line on stdout, human text on stderr). Laravel/Lumen: `watcher:check`, `watcher:status`, and `--dry-run --estimate --json` on `watcher:run`.
- Breaking for embedders of `Cli\Application`: the run factory is now `Closure(array, RunOptions): callable(?DateTimeImmutable): RunResult`; `check` and `status` factories are optional constructor arguments.
- US1, scheduled digest: `Watcher::fromConfig()` pipeline LogSource -> Reducer -> Redactor -> Selector -> Analyzer -> Slack; fingerprinting of log lines into groups with counts, first-run baseline, comparison with the previous window, dedupe of already-reported groups.
- US2, privacy: allowlist-only payload, normalised templates, text withheld for clusters below `redaction.min_group_events_for_text`, pattern scan with `redaction.extra_patterns`, model output scrubbed before caching and display, privacy corpus tests.
- US3, cost control: triage / analyze / escalate routing with per-tier model ids, token limits and prices, verdict cache, bounded escalation, input budget truncation, daily USD and token caps, alerts at 25/50/75/90 percent, cost footer in every digest.
- US4, failures and flexibility: Loki and Datadog sources, Redis / PDO / file state stores (shared contract suite), `InvalidConfig` with exit code 2, failure notices with exit code 1, `watcher run` CLI and Lumen/Laravel `watcher:run` command with a publishable `config/watcher.php`.
- US5, noise control: `mute` list (fingerprint or matcher, optional end date) and named `watch_rules`; level-anomaly second pass when a level's total grew.
- US6, context: dependency context, deploy info and log/code link builders (`src/Context`), configured through `dependencies`, `deploy`, `links`, `repo`.
- Scheduling: `schedule.run_at` gate (host triggers hourly), per-window run record and lock for idempotent runs.
- `config/watcher.example.php`, `README.md` and architecture tests (`tests/Unit/ArchitectureTest.php`).

### Changed
- Hardening: strict validation of configuration (unknown keys rejected, regexes compiled up front, prices required per model), state tables never created implicitly, every stored item and lock carries an expiry.

### Security
- Read-only by construction: sources issue only Loki `query` / `query_range` GETs and Datadog `aggregate` / `events/search` POSTs, enforced by an architecture test.
- Secrets never appear in error messages, notices or model input; Slack text is escaped so log content cannot ping, link or format.
- No model ids, no consumer-specific names in `src/` or `config/`, no framework imports in the core, all checked by tests.

### Documentation

- `docs/`: step-by-step first live smoke run, operations guide, configuration reference and a data-flow / privacy description; a minimal smoke config under `docs/examples/`.

### Changed (CLI)

- `bin/watcher` and `watcher:run` print a one-line result (`outcome`, `reason`, tokens, cost) and failure messages go to stderr; previously only the exit code was visible.
