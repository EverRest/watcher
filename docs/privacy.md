# What leaves the service (privacy and data flow)

For the person who has to approve running `watcher` against logs that may contain personal data (a data-protection
officer, a security reviewer). It states exactly what is sent where, what is stored, what the package guarantees and
what it does **not**. Verify each claim against the code and tests named here, not only against this page.

## Data flow

```
log backend (Loki / Datadog)  --read-only queries-->  watcher (inside your service/job)
     raw lines stay here                                 |-- masks and groups in memory
                                                          |-- state store (your Redis / DB / disk)
                                                          |-- Anthropic API   (masked groups only)
                                                          '-- Slack webhook   (digest and notices)
```

Raw log lines are read into the memory of the watcher process. The package itself writes none to disk, and sends none to the
model or to your notification channel. One caveat sits below it: Guzzle keeps a response body in `php://temp`, which holds
2 MB in memory and spills a larger response to a temporary file in the system temp directory (removed when the stream
closes, left behind if the process is killed). A Loki page of 5000 lines can exceed 2 MB, so on a host where the temp
directory is shared or not encrypted, treat the temp directory as part of the data flow.

## What is sent to the model provider (Anthropic)

Per request, and only this:

| Item | Content |
|---|---|
| Instructions | Fixed prompt text from `src/Analyze/prompts/` (it forbids inventing facts, writing URLs/hosts/emails, and obeying instructions found in data). |
| Data wrapper | The groups travel in one user message between `<log-data-NONCE>` and `</log-data-NONCE>` tags (a fresh 128-bit random `NONCE` per call, so log text cannot close the tag), followed by a fixed reminder that the data is untrusted. Both prompts say that everything inside the tags is data, never instructions. |
| Service context | `service_context` from your config (up to 1000 characters), after the redaction scan. You write it; keep personal data out. |
| Groups | For each problem group exactly these fields (`Watcher\Privacy\RedactedGroup::toArray`): `fp` fingerprint, `tpl` masked message template **or null** (at most 300 characters, `CompactPayload::MAX_TEMPLATE_CHARS`; longer templates end in `…`), `lvl` level, `err` exception class, `frames` up to 5 `path:line` strings, `n` count, `prev` previous count, `st` new/grown/unchanged, `watch` names of matching watch rules, `first`/`last` timestamps. |
| Dependency section | Optional: for declared dependencies, error totals and up to 5 masked top error groups (same shape). |
| Endpoint field `ep` | Only with `api_context` configured: for a group whose masked template names a documented route (at most 2 segments), one line `METHOD /template — summary` of at most 75 bytes, in analysis and escalation only, never triage. See below. |

The type that carries this to the model has **no other fields**, so nothing else can be added by accident
(`tests/Unit/Privacy/RedactedGroupTest.php`).

Never sent: raw messages, log context, request or response bodies, field values, labels, sample lines, user identifiers,
credentials, anything from `fields`.

## How a message becomes a template

0. **Canonical form first.** Before any rule runs the text is brought to a fixed point (at most 4 rounds, always cut to
   4096 characters): format characters (`\p{Cf}`: zero-width, bidi overrides and isolates, BOM, soft hyphen) are removed,
   NFKC folding is applied (fullwidth `＠`, `﹫`, ligatures; needs `ext-intl`, which is optional: without it only the fullwidth
   ASCII block and `﹫` are folded), the ideographic and fullwidth full stops `。` `｡` `．` between letters or digits become `.`,
   and one pass each of percent-decoding (punctuation such as `%40` `%3A` `%2F`, and valid UTF-8 sequences) and HTML-entity
   decoding is applied. `john%40example.com`, `jsmith&#64;example.com`, `john＠example。com` and a zero-width space
   inside a token are therefore seen by the rules as what a browser or Slack would show. The template is the folded text.
   A line the rules cannot scan (invalid UTF-8, a regex failure) becomes the placeholder `<UNSCANNABLE>` for that event;
   it never fails the window and is never sent raw.
1. **Pattern masking** (`src/Reduce/Normalizer.php`, one ordered table): emails (including `user@host` with a single-label
   host, quoted local parts and Unicode hosts) -> `<EMAIL>`; URLs -> `<URL>` (path,
   query, credentials and port always removed; the placeholder carries the declared dependency name only (`<URL:name>`, never a suffix) and only when the host equals it or is `name.<domain>` / `name.<label>.<domain>` for a configured `dependency_domains` entry; the digest only counts
   undeclared call targets, never names them). Each message is cut at 4096 characters before these rules run, and a trailing partial token is dropped with the cut (no whitespace at all: empty text); UUIDs and long hex -> `<ID>`; IP addresses -> `<IP>`; JWT-like strings, known-prefix keys (`sk-`, `ghp_`, `github_pat_`, `AKIA`, `xox*-`, `glpat-`, `AIza`), PEM private keys, ssh keys, credential headers (`Authorization`, `Proxy-Authorization`, `x-api-key`/`api-key`, `x-auth-token`, `Cookie`, `Set-Cookie`: only a known scheme word such as `Bearer`, `Digest` or `AWS4-HMAC-SHA256` is kept, the rest of the value up to the end of the line or closing quote is masked, commas and spaces included), a standalone `Bearer`/`Basic`/`Token` value (digits or `+/=`, mixed case of 12+ characters, or for `Basic` any base64-looking 8+ run that is not one plain word) and any other long random-looking run (20+ characters, mixed classes, entropy >= 3.5 bits per character; plain class and identifier names are kept) -> `<TOKEN>`. A run holding slashes is split into path segments only when it clearly is a path (rooted with `/`, `./`, `~/`, or at least two slashes), and then only while every kept segment is plain words (2+ letter words, at most 3 digits, no run of 6 consonants; a trailing date and CPU architecture names are allowed); otherwise the whole run is judged and masked whole, so a base64 secret cannot hide behind short segments, and a rooted path with one random segment among odd ones is masked whole. **Credentials by key name**, whatever the value (any length or case, quoted or not, also when the same value repeats in every event): the value of `password` / `passwd` / `pwd` / `passphrase` / `secret` / `client_secret` / `api_key` / `apikey` / `access_key` / `private_key` / `credentials` / `token` / `signature` keys (also as a suffix, `x_access_token`, and as `"key": "value"` JSON), of headers whose name contains `key`, `auth` or `sig` after `x-`/`dd-`, and of `?key=` `&sig=` `&auth=` query parameters, up to the next space, comma, semicolon, ampersand, quote or closing brace, becomes `<TOKEN>` and the key stays (DSN `password=...` included). The query string of a relative request line (`/search?q=...`) keeps its keys and every value becomes `<STR>`. Known false positives, kept on purpose (doubt means masked): camelCase words of 12+ characters right after `token`/`basic`, and the rest of a line after a `cookie:`/`authorization:`-style label; digit-only 32/40/64-character hashes -> `<ID>`; bare dotted host
   names (labels may be Unicode letters, so IDN hosts count) -> `<HOST>` (a **single-label** host such as `db-prod:5432` is not masked); card-like numbers -> `<PAN>`; remaining digits -> `<N>`; long quoted strings -> `<STR>`. Placeholder-
   shaped text in the input is neutralised first, so log content cannot hide a value inside `<...>`.
2. **Variation masking** (`src/Reduce/TemplateMiner.php`): events are first bucketed by level, error type, length and first
   frame, then clustered by similarity (a message joins a cluster when at least half of the cluster leader's literal
   words match; placeholders do not count); any word that differs between events of a cluster becomes `<*>`. This is what
   protects **names of people in free text**, which no pattern can recognise. Only distinct masked messages are held,
   capped at 50,000 with a fixed comparison budget; past either, the affected groups get **no template** (fail
   closed) and the digest says so. Clustering is order-independent and never changes a fingerprint.
3. **Minimum cluster size** (`redaction.min_group_events_for_text`, default 3): a cluster with fewer events gets **no
   template at all** (`tpl` is null). A one-off error that mentions a person therefore sends only its level, exception
   class and frames.
4. **Second scan** (`src/Privacy/Redactor.php`): the template, error type and each frame are scanned again with the same
   table plus your `redaction.extra_patterns`. If anything fails to scan, the field is **dropped, never sent raw** (fail
   closed). "Fail closed" covers errors the code can see (invalid UTF-8, a regex that fails, an over-long line); a
   message in a shape no rule recognises is not an error and passes unchanged (see the limits below). Digit runs inside identifiers are masked too (`Tenant_8812Exception` -> `Tenant_<N>Exception`) unless the
   identifier is a well-known token (`utf8`, `md5`, `sha256`, `base64`, `http2`, `ipv4`, `ipv6`, `x86_64`, `oauth2`,
   `s3`, `ec2`, `h264`; `Http2ConnectionTimeoutException` stays). **Frame paths are rewritten before they are sent:**
   with `repo.path_map` configured only the part below a configured container root is kept
   (`/var/www/html/app/Foo.php:12` -> `app/Foo.php:12`) and a frame outside every root is dropped; without it the path is
   kept from its first `vendor/`, `app/` or `src/` directory, at most the last three segments, and what was cut becomes
   `…/` (`/home/jsmith/app/X.php:12` -> `…/app/X.php:12`); no such directory: no frame. A segment that is not plain
   `[A-Za-z0-9_.-]` or looks random drops the frame; digits are masked. Line numbers and file names are kept.
5. **Model output scrub:** everything the model writes (title, evidence, cause, action, `how_to_check`, every `related_checks` entry) is scanned the same way before it
   is shown, stored or cached; plain numbers are kept.

### OpenAPI summaries (spec 010)

`api_context` adds one allowlisted field class: the **operation summary** (the operationId only when there is no summary), the **first tag** and the
**deprecated flag** of the operations a finding's route matches. Per operation exactly six fields are read out of the document: method, path
template, operationId, summary, first tag, deprecated. Examples, schemas, parameters, request and response bodies, descriptions, server variables and
extensions are never copied. Free text is **masked first** (e-mails, tokens, ids) and then cut to `api_context.summary_cap` (80), so a secret
straddling the cut cannot survive as a fragment; it is masked again where it is used (digest line, `ep` field), and a text that cannot be
scanned is dropped. Plain numbers are masked too (`<N>`: an SSN, PESEL, tax id, date of birth or order number never survives), and since the
masker cannot recognise a name or a street, free text is kept only when every word is a word of the route template, the operationId (split at
case changes), the tag or a short list of common words (`get`, `list`, `order`, `for`, ...); otherwise the summary is dropped and the
identifier-shaped operationId stands in. A tag must be an identifier-shaped word that passes the same rule. The reduced document, not the raw one,
is what the state store keeps (expiring after `api_context.ttl`). **The service owner is still responsible for operationIds**: an identifier such
as `getJohnSmith` is kept as written (its digits masked). `tests/EndToEnd/ApiContextTest.php` seeds an e-mail and a token in a summary, a description, an
operationId, a tag, an example, a parameter and an extension and asserts none reaches the model payload, the digest, the log and file sinks, the summary or the cache.

## What is sent to your notification channel

Whichever channel you configure (Slack, Telegram or Discord) receives the same content, and nothing else leaves per
channel: Slack gets the webhook POST, Telegram a `sendMessage` call to `api.telegram.org` (chat id in the body, bot token
in the URL), Discord a webhook POST. The credentials (webhook, bot token, chat id) are secrets: the exact configured values
(8 characters or more) and credential shapes (`key=value`, `Authorization`, `Bearer`, URLs, long runs) are masked in failure
reasons, in the stored heartbeat and in the host log, which gets the exception **class** and that masked message
(`DeliveryFailed: ...`), never the raw message. Webhook URLs are checked at run time (https, a host, no credentials in the URL) so a malformed one is
refused before the HTTP client can echo it. Slack and the sources do not follow redirects. Model text is
inert on Telegram and Discord too (HTML and Markdown escaped, links and mentions broken up; Discord posts with
`allowed_mentions.parse` empty and embeds suppressed). Retention at Telegram or Discord is theirs.

The digest and notices: finding titles, evidence, hypothesis, suggested action, a `Check:` line (what to look at to confirm or refute the hypothesis) and an optional `Related:` line (other groups or declared dependencies worth checking, only names that were in the model input) written by the model from the masked
input; 12-character fingerprints; counts and trends; token and cost figures; release names; and optional links built by
the package (log-query links from configured base URLs, tags, level, time range and a masked template fragment; code links
from your repository URL, commit and a mapped file path). Text from the model has Slack mentions and links disabled
(`@channel`, `<...|...>`, `scheme://`, `www.`, `host.tld` are broken up). Links we build are the only clickable URLs.
Failure notices contain a short reason (for example `source failed: loki 401 Unauthorized`), never keys, webhook URLs,
log lines or stack traces. The reason can include the backend's HTTP reason phrase verbatim (up to 200 characters, escaped
for the channel): a compromised log backend controls that text.

**Fleet.** A fleet (see the README's fleet section) can post two things beyond a single service's digest: the
**correlated finding** (the origin service's masked template, up to 300 characters in backticks, plus the names of the
consuming services and event counts), and, with `digest: combined`, every service's digest in the fleet's own channel even
when that service has its own `notify.digest`. A service whose digest is meant for a restricted audience is therefore
visible to the fleet channel's audience. The services of one fleet share one trust domain (identical `analysis.api_key`
and `state` are enforced); do not combine services with different audiences.

## What is sent to your event sinks (spec 013)

Off unless you enable a sink. An event is built from the typed results the digest already uses (findings, groups, counts, spend), never from a raw log line, and a sanitizer drops every field outside the schema allowlist and every string that fails its pattern. Without text an event holds only enumerations, numbers, timestamps, 12-hex fingerprints, hex run ids and your configured names (service, environment, watch-rule names). Never present: log lines, message templates, field values, stack frames, filesystem paths, URLs, tokens.

`include_text` is a per-sink flag. With it, a `finding` event carries the same title, evidence, hypothesis, next step and check the chat message shows (after the same output scrub) and a `group` event carries `error_type` after a second redaction scan. Defaults: off for every sink, `log` included (owner decision: text is an explicit opt-in). An `http` sink with text on emits `config_warning include_text_external`, so the dashboard shows that text leaves the host. The Prometheus file has no text at all and no label is ever a fingerprint, title or release.

Retention of what a sink delivers is yours (Loki, Elasticsearch, Prometheus); watcher bounds only its own files (`file` sink: `max_files`, `retention_days`) and its counters (expire with `state.retention`). The `http` URL and token are credentials: masked in every message, never in an exception, log line or `check` output. The privacy corpus runs through all four sinks with a planted secret and e-mail (`tests/EndToEnd/ObservePrivacyTest.php`).

## What the metrics and Sentry sources add (spec 009)

Two new field classes, both optional and off by default. **Metric numbers**: one current and one baseline number per declared query, and at most 5
values of a label **name** you allowlisted (each must survive the Normalizer unchanged and then the Redactor, otherwise it is dropped; an e-mail or an id
never shows). **Masked Sentry text**: an issue title (cut at 300) and culprit (cut at 120) are masked first and cut after, so a half e-mail cannot survive the cut; a rare
issue (fewer than `redaction.min_group_events_for_text` events) withholds both. Everything else Sentry returns (users, tags, breadcrumbs, permalinks, event
payloads, short ids) is never read into an object. The model receives up to three **metric numbers** (declared name, current and baseline value, unit, relative change) in the `m` section of the payload, added after redaction from the measured values and the config, never from log or label text, so the digit masking of the Redactor does not touch them; a metric anomaly's group carries `mi`, the index of its metric. A trace id, when a tracing link is configured, is read from the raw event field of the group's earliest event and appears only inside the link URL of the digest. The event sinks never receive this text: a Sentry or metric-anomaly group appears there as counts only: its fingerprint (`sentry:<id>`, `metric:<hash>`) is not the 12-hex form the event schema allows, so the sanitizer sends `null` for it. The
tokens (`metrics.source.auth.token`, `sentry.auth_token`) are masked everywhere and absent from every dump of the objects that hold them. Tests:
`MetricsSentryPrivacyTest` plants an e-mail, a secret and a personal id in an issue title, culprit and label value and scans every sink, the model input,
the summary, the host log and the notices.

## What is sent to the log backend

Read-only queries: your stream tags, level words, and a subset of your watch rules as search filters: `template` rules that are plain literals and `error_type` rules whose regex RE2 can run (Loki), or only single-word patterns (Datadog). The text comes from your config, never from log data. `field` rules and non-literal `template` rules (a template regex applies to the masked template, not to raw lines) are never sent; they are matched in memory on the lines that were read. Nothing derived from log
content is sent back. Credentials should be read-only (Grafana Viewer role; Datadog application key with
`logs_read_data` only).

## What is stored (your state store)

Counters per fingerprint, per-window totals, model verdicts, "already reported" markers, run records, daily spend and
alert markers, and findings (the model's text, scrubbed as described above). Every key has an expiry, but not all of them
`state.retention` (30 days): spend and alert markers live 2 days; the weekly heartbeat counters 14 days; `noise` and `known`
verdicts 3 days; the delivered-digest marker 6 hours; consecutive-empty-window and failure-notice counters follow retention
and 2 days; heartbeat and trigger records 3 x `state.retention` (90 days by default; the failure reason in a heartbeat is
masked, up to 200 characters). The verdict cache stores the model's scrubbed finding text for the retention period although
only the triage result is read back. The SQL driver purges expired rows on every 50th write, so an expired row can remain in
the table for a while. Raw lines, field values and unmasked templates are not stored. The file driver names files by
the hash of the key.

**Redis.** The expiry above is set by Redis itself on every key and cannot be skipped: the store refuses a write without a positive TTL, and
the run lock, counters and markers get theirs in the same command (see [operations.md](operations.md#state)). Values larger than
`state.redis.compress_min_bytes` (512) are stored zlib-compressed (`z1:` prefix). Compression is storage encoding only, not protection: it changes
nothing about what is stored or who can read it, so the Redis instance needs the same access control either way. `watcher state:get` is the
read-only debug view of a state entry: every string is passed through the redactor and the configured secrets mask and cut to 200 characters,
and it refuses any key that is not one of the package's own logical keys. An entry that cannot be decoded is treated as missing and counted (`state_unreadable` in the JSON summary, when above 0); the
host log gets the key kind (`snap`, `run`, ...) only, never the key or any content.

## What reaches host logs and exit output

The one-line summary on stdout and `watcher [error] ...` lines on stderr carry messages only, with line breaks and
terminal control characters (ESC and the other C0 characters) removed. Exception objects are not logged, because an HTTP
request (including the API key) can hang on them; delivery failures are logged as the exception class and a masked message.
Config objects (`WatchConfig`, `FleetConfig`) hold every credential in a `SecretValue` (the value is read with `->reveal()`, the `WatchConfig::reveal()` helper, or `WatchConfig::plain()` for comparisons). A dump of them (`var_dump`, `print_r`, `var_export`, `json_encode`) shows no value, and `serialize` refuses to serialize a credential; the constructors that take a secret carry `#[\SensitiveParameter]` (PHP 8.2 and later removes those arguments from stack traces). `tests/Unit/Config/SecretDumpTest.php` plants a fake value at every secret key of the catalog and fails when one reaches a dump.

## Kubernetes events (spec 008)

Optional. The cluster is read with `GET` on `events` and `pods` only. Each event is parsed into a closed set of fields
(annotations, labels other than for the selector, `managedFields`, environment variables, arguments and commands are never read).
`EventMasker` (pod names -> `api-<POD>`, node names, image registry/path/tag, container ids, quoted secret/configmap/volume names,
namespaces in front of pods, the free text after an admission webhook's verdict) and then the Normalizer run before grouping; an
event that cannot be scanned is dropped.

Sent to the model for an event group: the usual allowlisted group plus `src` (`k8s`), `pods` (a number) and `wk` (a workload kind
from a closed list). Stored (state key `evt:<service>`, expiry = window + tolerance + 1 h at most): the fully masked text (at most
300 characters), level inputs, counts, unix times, the masked workload name and an 8-character one-way hash of each pod (only to
count distinct pods). Never stored or sent: raw events, pod/node/image names, registry hosts, annotations, the token or CA.
`tests/Fixtures/kubernetes/seeded-values.php` lists the values seeded in the fixtures; `tests/EndToEnd/KubernetesEventsTest.php`
fails if any reaches a model request, a message, a state write, the JSON summary or the host log.

Limit: a name that is identical in every occurrence of an event and not covered by a rule above (for example a person named by
an operator in free text) is not detectable; see "Limits you must know".

## Similarity grouping and baselines (spec 004)

Clustering by similarity runs on the already masked tokens, per distinct message, inside the process; nothing new is
stored or sent. The digest's grouping and comparison lines are built from integers, dates and fixed words only. The one
new value in the model payload is the group status `expected` (alongside `new`, `grown`, `unchanged`).

## Log hygiene reports (spec 006)

`watcher hygiene` reads a window through the same source and masking and reports where personal data is **written** to logs. It sends nothing to a model. Per report, what it contains and what it never does:

- It contains: a call-site identifier (`file:line` after the Redactor's frame rewriting, else `tpl:<fingerprint>`), a category (`email`, `token`, `address` = IP address, `phone`, `card`, `host`, `other`), a level, integers, times, a trend, and masked templates that the digest could already show (they pass the Redactor's second scan again).
- It never contains: a matched value, an example line, a context key or value, a stack frame beyond the site identifier. The scanner keeps integers per site and category; the matched text is read, classified and dropped.
- Categories are read off the Normalizer's own placeholders (`<EMAIL>`, `<TOKEN>`, `<IP>`, `<PAN>`, bare `<HOST>`/`<URL>`, `<USER>` and extra patterns = `other`); phone-like is the one extra check (a `+` followed by 9 or more digits, spaces, dashes or brackets), reduced to yes/no. Context **values** are scanned (up to 40 per event); field **names** are never interpreted.
- Stored (state retention): per-day aggregates (sites with counts, families with fingerprint, masked template, level, count, times, attributed tokens), a call-site registry (identifier, times, two integers) and declined proposal ids. Nothing else; a stored record that does not validate is ignored.
- A site is a **source file**: a context `file` + `line` (or the first stack frame) is accepted only when it resolves **below a `repo.path_map` root** (relative to the root, no `..`), its name is one stem plus one known code extension (`.pdf.php` and `john.smith.js` are rejected); a path that fails these rules falls back to `tpl:<fingerprint>`, every directory below the root is on a short built-in list of code-structure names (`app`, `src`, `lib`, `Http`, `Controllers`, `Models`, `Services`, `Jobs`, `Domain`, ... see `SiteId::CODE_DIRS`) or is named in `hygiene.site_allow_dirs` (an allow list: a directory such as `customers`, `Petrenko` or a hex token is never a site id, so a person's name cannot become one), and the file name is one identifier (letters and digits, at most one underscore: no hyphenated words, no dots) whose every word (split at capitals and underscores) is code vocabulary (`SiteId::CODE_DIRS`, the word floor's vocabulary, two letters or fewer) or named in `hygiene.site_allow_dirs` (`OrderController.php` is a site, `IvanPetrenko.php`, `ivan_petrenko.php` and a hex name are not). **Without `repo.path_map` no file site ids are produced at all**: every site is then keyed by the fingerprint (`tpl:<fingerprint>`); configure `repo.path_map` to get call-site names. Anything else (an upload, an import, a document named after a person) falls back to the fingerprint. The Redactor's frame rule applies on top (and reads `a.py` or `a.go` as a host name, so Python, Go and Java frames always fall back to the fingerprint).
- `--notify` posts to the `notice` destination only (nothing when `notify.notice.enabled=false`), and only non-empty reports. Every log-derived string in it (site, template, rule fragment) goes through the digest's Slack escaper, so `<!channel>`, `<@U..>` or `<https://x|y>` written by a log writer cannot ping or link in Slack, Telegram or Discord; at most 25 sites and 10 templates per notice, and only the sites shown are marked as sent; a "gone" retraction is one line per site, at most 25 per notice, the rest follow in the next one. Before any mask runs, every message loses its control characters (ESC and ANSI sequences, NUL, U+0085), format characters (zero-width, bidi overrides) and invisible fillers (U+2800, U+3164, U+115F/U+1160, U+FFA0, U+034F, variation selectors), and a line break or tab becomes a space, so none of them can hide part of an address from a mask, reach a terminal or be pasted into a rule (`ReportText::clean`); every log-derived string printed is cleaned the same way; the notice escaper still adds zero-width spaces after `a.`, `@` and `://` to defang links, so copy a fragment from the terminal or `--json`, not from the chat message. An unchanged site is repeated at most every `hygiene.repeat_days`; shape, proposals and cost at most that often.
- Tested by `tests/EndToEnd/HygienePrivacyTest` (seeded call sites in `tests/Fixtures/privacy/hygiene.log`; no seeded value in any text, JSON, notice or stored record, with a control run that proves the test sees a leak) and `tests/Unit/HygieneArchitectureTest` (no model client, no printing, no file writes, only the scanner and the site identifier see events).

Limits: card-like (`<PAN>`, any 13-19 digit run, so epoch milliseconds count) and host-like (any `word.word`) are inflated by false positives; texts that cannot be decoded are counted as `unscannable`, not categorised; a name that is identical in every event is not a pattern and is not found (the variable-free-text signal only sees names that vary), which is why a masked template is shown, stored and proposed as a watch rule only when it generalises at least `hygiene.variable_distinct` different messages (or `hygiene.allow_constant_labels` is true), and why each literal word of it must be common log vocabulary or seen at `hygiene.literal_min_sites` different code locations (files) in the pass; all messages without a location count as one source, so a family that varies one word is still one source (otherwise `<w>`; only the exact placeholders `<N>`, `<ID>`, `<EMAIL>`, ... are skipped, any other text in angle brackets is text: a name that is constant inside a family that varies elsewhere is hidden too, at the price of hiding domain words used at one site only) (a sentence that never varies may be one person's name: its row shows no label and no watch proposal is made for it; mute proposals carry a fingerprint only); phone numbers without a plus sign and postal addresses are not detected; digits in a path are masked, so `Controller1.php` and `Controller2.php` are one site; a line that is longer than 4096 characters is cut before it is scanned.

## Limits you must know

- **Outbound URLs are checked by their text, not by their DNS answer.** A configured host name that resolves to a loopback,
  private or metadata address (`127.0.0.1.nip.io`) is sent to, with any token configured for that source. Only the operator writes
  these URLs, so the check does not defend against them; keep them to hosts you trust (`docs/operations.md`, Proxies and the
  internal-address check).
- **Keyword-less secrets and bare names are not detected.** A credential is masked by its key name or its shape. A password
  written as a bare word (`login failed for hunter2`), a value after a key that is not in the list above, a token under 20
  characters after no key, a customer name inside SQL or Postgres detail text (`Key (name)=(Olena Shevchenko)`) or inside
  a path segment (`/customers/olena-shevchenko/orders/12` keeps its plain words) is not recognised. A value identical in
  every event of a cluster is kept by the variation masking (see the next item); only the key-name rule above removes it.

- **A name that is identical in every event of a cluster is not detectable, and no code tries to guess proper names by shape.** If the same person appears 3 or more times
  with exactly the same surrounding words, the template can contain the name (similarity grouping does not change this:
  identical messages are one message). Messages that merely share a name can also join one cluster. Raise `min_group_events_for_text` or add an
  `extra_patterns` entry if your logs contain such a pattern. Free-text names in rare, one-off messages are protected by
  withholding, not by recognising them.
- **Exception types must look like class names, and names inside them are not detected.** A type is sent only if it
  matches a PHP class-name shape (letters, digits, `_`, `\` separators, at most 120 characters); free text such as
  `declined for Olena Shevchenko` becomes no type. A personal name that is part of a valid class name
  (`AnnaKowalskiNotFoundException`) cannot be told from any other word and is sent.
- **Directory names below the kept part of a frame path** (`app/anna-corp/Foo.php`) are plain words and are not
  recognised; the parts above the first known directory (home dirs, customer dirs under `/var/www`) are cut, and with
  `repo.path_map` everything above the root is. Dotted forms that look like hosts or emails drop the frame.
- **Absolute paths written inside a log message** are masked by the Normalizer: the user segment of `/home/<x>`,
  `/Users/<x>` and `C:\Users\<x>` becomes `<USER>` (`/root` stays), and any absolute path of 3+ segments becomes
  `<PATH>/…/<last two segments>` (`<PATH>` alone when a kept segment is not plain or looks random; `<TOKEN>` when the whole
  run looks like a secret split over short segments). `<PATH>` and `<USER>` are reserved placeholder names. Cost: route
  paths in messages (`/api/v1/users/42`) are masked the same way, and fingerprints of messages with paths merge.
- **Stack-frame links** use the repository path the Redactor produced (`repo.path_map` root -> its repository path).
- **`extra_patterns` run on the raw message**, before digits become `<N>` (so `/CUST-\d{6}/` matches), then again on
  the template and on model output. Name them with letters or `_`; the placeholder `<NAME>` is never re-masked.
- **JSON `message` values keep their first physical line** (as text records do). The rest is dropped, never promoted to
  the message; the exception class and frames are still read from the whole text first. The count is
  `LineParser::$messageTailsDropped`.
- **Advice fields.** `how_to_check` and `related_checks` are model text like the rest: scrubbed, escaped in every channel (no link, mention or domain survives). `related_checks` is not free text at all: an entry is kept only when it is **exactly** a fingerprint of the request (12 hex characters) or a declared dependency name, and the digest prints just that reference; anything else, including advice around a reference, is dropped. The model sees only masked templates, so the prompt forbids reproduction steps; `how_to_check` says what to look at, not how to trigger the problem.
- **Model text is untrusted (security review 5).** Log text is attacker-influenced and the model can be steered by it, so the code does not rely on the prompt alone:
  - *The model cannot make an error group disappear.* A group at `error` or above, or matched by a watch rule, is never dropped by a triage verdict (`noise`, `known`, a missing or empty verdict are all overruled and the group is analysed; the digest says `triage marked N error-or-higher or watch-rule group(s) as noise, known or left them out`). A group triage was sent but gave no verdict for is treated as `interesting` (fail closed) and counted in the same digest line; a duplicate verdict, an unknown fingerprint or malformed JSON is invalid output (one retry, then a loud failure notice). If the analysis reports nothing, or only a `noise`/`info` finding, for such a group it is **not remembered** (no 30-day verdict): it is analysed again next window and the digest says `N error-or-higher or watch-rule group(s) were analysed but the model reported nothing for them`. Lower-level groups keep the cheap reuse (30 days with a finding or none, 3 days for a triage `noise`/`known`), and the digest now counts both, `N lower-level group(s) dropped by triage` and `N group(s) reused from earlier verdicts`. A run that silenced nothing visible can still not end as a quiet day when the model overruled the code.
  - *Severity and kind are bounded by the logs.* A finding is shown at most one step above the highest level its groups have in the logs (an `info`/`warn`/`error`/`critical` ladder), for an error-or-higher group never below that group's level (a model cannot put it below), for a watch-rule group at least `warn`, and never as `noise` for either. A cached verdict whose finding is below the level of an error-or-higher group is not reused. A `noise`/`info` finding is attached only to the first group it cites and to groups with the same exception type; other cited groups are treated as not covered. The model's confidence only decides whether an already `error`-level group is sent to the stronger model (capped by `escalation_cap`).
  - *Advice text is filtered in code.* `suggested_action`, `how_to_check` and `suspected_cause` have command-shaped text replaced by `[withheld]` (the rest of the finding stays): `curl`/`wget` piped to a shell, `| sh`, `&&`, `||`, `$(...)`, backticks, `; rm|sudo|curl|...`, `rm -`, `paste ... token|password|secret|key|credential`, `rotate all ...`, `sudo`, `chmod`, `chown`, `mkfs`, `DROP TABLE|DATABASE|SCHEMA`, `TRUNCATE TABLE`, `DELETE FROM`, `kubectl delete|exec|apply|patch|scale|...`, `systemctl stop|restart|disable`, `service X stop|restart`, `iptables -F|-X`, `ufw disable`, `curl|wget` uploading a file (`-d @`, `-F ...@`, `--post-file`), `nc -e`, `dd if=`, `shutdown|reboot|halt` with arguments, `killall`, `pkill`, `kill -9`, `> /dev/...`, `git push --force`, `docker rm|rmi|stop|kill`, `base64 -d`, `python|perl|ruby -c|-e`. The rules run on the canonical text too (zero-width and bidi characters stripped, NFKC folded, percent and entity decoded), so disguised forms are withheld. The match runs to the end of its sentence. This is a small denylist, not proof of safety. The no-URL rule (links, `www.` hosts, e-mail addresses) is enforced in code for every text field, not only by the prompt and the Redactor.
  - *Display.* Emphasis characters in model text are neutralised by the shared escaper (`SlackText::escape`). Bidi controls (U+202A-202E, U+2066-2069) and zero-width characters are not yet removed there; see the open item in the security review.
  - *Ordering and cost.* `top_m` slots are filled by watch rule, level, growth, then novelty, with volume and a salted hash as tie-breakers; new groups take at most half of the slots while older ones wait (the digest counts the overflow). A fingerprint minted by an attacker therefore cannot outrank a grown real group of the same level.
  - *Refusals.* A `refusal` stop reason is not retried; it fails the run loudly (`triage: the model refused the request`), is billed, and records the window as `failed` with the code `analysis_refused`, so hourly triggers do not pay for it again. Any other analysis failure is retried by the next trigger once; the second failure of the same window is recorded as failed too (`no further attempts for this window`). The next window starts clean.
  - *Limits.* This reduces what a steered model can do; it does not stop it writing misleading **text** inside the bounds above, which is why model text is labelled as hypothesis and verified by the on-call engineer. A user who can make the service log a string equal to a declared dependency host (three or more times) can still make a consumer group look like it references that dependency in fleet correlation; a placeholder typed inside another placeholder is not counted.
- **Model wording.** The model never sees personal data from masked input, but its text is still free text. The scrub
  catches the patterns above, not arbitrary words.
- **Field-based rules.** A `field`+`equals` watch rule or mute reads field values inside the process to decide; only the
  rule name leaves. The value `equals` is compared with is in your config.
- **Provider and channel (Slack, Telegram, Discord) retention are theirs.** Check your agreement and workspace settings. The package does not select a
  provider region; the API endpoint is the provider's default.
- **Legal basis.** Whether sending masked operational data to an AI provider and a chat tool is lawful for your
  organisation (processor agreements, transfers, retention) is your decision. This page describes the technical data
  flow only.

## How this is tested

- `tests/Fixtures/privacy/` holds a corpus of logs seeded with emails, names (Latin and Cyrillic), phones, tokens, IBANs,
  IPs, personal sub-domains, credentials in URLs and a dependency stream; `seeded-values.php` lists the seeded values (usernames and customer names in frame paths, ids glued to letters, digit-based patterns, JSON message tails included).
- The corpus also holds keyword-less credentials (`password=`, DSN, JSON, form body, query), fullwidth and
  zero-width emails, `%40` / `&#64;` / nested encodings, ideographic dots, IDN hosts and `user@bastion`;
  `tests/EndToEnd/LongLinePrivacyTest.php` covers lines past the parser caps.
- `tests/EndToEnd/PrivacyTest.php` runs the whole pipeline over it and fails if any seeded value appears in **any** request
  to the model (triage, analysis, dependency section, service context), any Slack message (raw, decoded once and twice)
  or any stored value. A control run proves the test sees leaks when the protection is switched off.
- Unit tests cover the normaliser table, the template miner, the redactor's fail-closed behaviour and the Slack text
  escaping. Several defects were found and fixed this way during development; they are listed in `CHANGELOG.md`.

## Auditing a live deployment

During the smoke run (see [smoke-test.md](smoke-test.md), check 4) search the Slack output and the state directory for
values you know exist in the logs. Repeat after any change to `redaction.*`, after adding `extra_patterns`, and when a
new log format appears (a new format can contain personal data in places no rule has seen).
