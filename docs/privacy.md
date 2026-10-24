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
terminal control characters (ESC and the other C0 characters) removed. Exception objects are not logged, because the SDK's
request (including the API key) hangs on them; delivery failures are logged as the exception class and a masked message.
Config objects (`WatchConfig`, `FleetConfig`) show `[redacted]` for their credential sections in `var_dump`, `print_r`,
`dd()` and `json_encode`, and the constructors that take a secret carry `#[\SensitiveParameter]` (PHP 8.2 and later removes
those arguments from stack traces).

## Similarity grouping and baselines (spec 004)

Clustering by similarity runs on the already masked tokens, per distinct message, inside the process; nothing new is
stored or sent. The digest's grouping and comparison lines are built from integers, dates and fixed words only. The one
new value in the model payload is the group status `expected` (alongside `new`, `grown`, `unchanged`).

## Limits you must know

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
  - *The model cannot make an error group disappear.* A group at `error` or above, or matched by a watch rule, is never dropped by a triage verdict (`noise`, `known`, a missing or empty verdict are all overruled and the group is analysed; the digest says `triage marked N error-or-higher or watch-rule group(s) as noise, known or left them out`). Triage must also return exactly one verdict per group sent: a missing, duplicate or empty list is invalid output (one retry, then a loud failure notice). If the analysis reports nothing, or only a `noise`/`info` finding, for such a group it is **not remembered** (no 30-day verdict): it is analysed again next window and the digest says `N error-or-higher or watch-rule group(s) were analysed but the model reported nothing for them`. Lower-level groups keep the cheap reuse (30 days with a finding or none, 3 days for a triage `noise`/`known`), and the digest now counts both, `N lower-level group(s) dropped by triage` and `N group(s) reused from earlier verdicts`. A run that silenced nothing visible can still not end as a quiet day when the model overruled the code.
  - *Severity and kind are bounded by the logs.* A finding is shown at most one step above the highest level its groups have in the logs (an `info`/`warn`/`error`/`critical` ladder), never below `warn` for an error-or-higher or watch-rule group, and never as `noise` for one. A `noise`/`info` finding is attached only to the first group it cites and to groups with the same exception type; other cited groups are treated as not covered. The model's confidence only decides whether an already `error`-level group is sent to the stronger model (capped by `escalation_cap`).
  - *Advice text is filtered in code.* `suggested_action`, `how_to_check` and `suspected_cause` have command-shaped text replaced by `[withheld]` (the rest of the finding stays): `curl`/`wget` piped to a shell, `| sh`, `&&`, `||`, `$(...)`, backticks, `; rm|sudo|curl|...`, `rm -`, `paste ... token|password|secret|key|credential`, `rotate all ...`, `sudo`, `chmod`, `chown`, `mkfs`, `DROP TABLE|DATABASE|SCHEMA`. The match runs to the end of its sentence. This is a small denylist, not proof of safety. The no-URL rule (links, `www.` hosts, e-mail addresses) is enforced in code for every text field, not only by the prompt and the Redactor.
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
