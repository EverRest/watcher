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

Raw log lines are read into the memory of the watcher process. They are **never** written to disk by the package, never
sent to the model and never sent to Slack.

## What is sent to the model provider (Anthropic)

Per request, and only this:

| Item | Content |
|---|---|
| Instructions | Fixed prompt text from `src/Analyze/prompts/` (it forbids inventing facts, writing URLs/hosts/emails, and obeying instructions found in data). |
| Service context | `service_context` from your config (up to 1000 characters), after the redaction scan. You write it; keep personal data out. |
| Groups | For each problem group exactly these fields (`Watcher\Privacy\RedactedGroup::toArray`): `fp` fingerprint, `tpl` masked message template **or null**, `lvl` level, `err` exception class, `frames` up to 5 `path:line` strings, `n` count, `prev` previous count, `st` new/grown/unchanged, `watch` names of matching watch rules, `first`/`last` timestamps. |
| Dependency section | Optional: for declared dependencies, error totals and up to 5 masked top error groups (same shape). |

The type that carries this to the model has **no other fields**, so nothing else can be added by accident
(`tests/Unit/Privacy/RedactedGroupTest.php`).

Never sent: raw messages, log context, request or response bodies, field values, labels, sample lines, user identifiers,
credentials, anything from `fields`.

## How a message becomes a template

1. **Pattern masking** (`src/Reduce/Normalizer.php`, one ordered table): emails -> `<EMAIL>`; URLs -> `<URL>` (path,
   query, credentials and port always removed; the placeholder carries the declared dependency name only (`<URL:name>`, never a suffix) and only when the host equals it or is `name.<domain>` / `name.<label>.<domain>` for a configured `dependency_domains` entry; the digest only counts
   undeclared call targets, never names them). Each message is cut at 4096 characters before these rules run, and a trailing partial token is dropped with the cut (no whitespace at all: empty text); UUIDs and long hex -> `<ID>`; IP addresses -> `<IP>`; JWT-like strings, known-prefix keys (`sk-`, `ghp_`, `github_pat_`, `AKIA`, `xox*-`, `glpat-`, `AIza`), PEM private keys, ssh keys, credential headers (`Authorization`, `Proxy-Authorization`, `x-api-key`/`api-key`, `x-auth-token`, `Cookie`, `Set-Cookie`: only a known scheme word such as `Bearer`, `Digest` or `AWS4-HMAC-SHA256` is kept, the rest of the value up to the end of the line or closing quote is masked, commas and spaces included), a standalone `Bearer`/`Basic`/`Token` value (digits or `+/=`, mixed case of 12+ characters, or for `Basic` any base64-looking 8+ run that is not one plain word) and any other long random-looking run (20+ characters, mixed classes, entropy >= 3.5 bits per character; plain class and identifier names are kept) -> `<TOKEN>`. A run holding slashes is split into path segments only when it clearly is a path (rooted with `/`, `./`, `~/`, or at least two slashes), and then only while every kept segment is plain words (2+ letter words, at most 3 digits, no run of 6 consonants; a trailing date and CPU architecture names are allowed); otherwise the whole run is judged and masked whole, so a base64 secret cannot hide behind short segments, and a rooted path with one random segment among odd ones is masked whole. Known false positives, kept on purpose (doubt means masked): camelCase words of 12+ characters right after `token`/`basic`, and the rest of a line after a `cookie:`/`authorization:`-style label; digit-only 32/40/64-character hashes -> `<ID>`; bare host
   names -> `<HOST>`; card-like numbers -> `<PAN>`; remaining digits -> `<N>`; long quoted strings -> `<STR>`. Placeholder-
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
   closed). Digit runs inside identifiers are masked too (`Tenant_8812Exception` -> `Tenant_<N>Exception`) unless the
   identifier is a well-known token (`utf8`, `md5`, `sha256`, `base64`, `http2`, `ipv4`, `ipv6`, `x86_64`, `oauth2`,
   `s3`, `ec2`, `h264`; `Http2ConnectionTimeoutException` stays). **Frame paths are rewritten before they are sent:**
   with `repo.path_map` configured only the part below a configured container root is kept
   (`/var/www/html/app/Foo.php:12` -> `app/Foo.php:12`) and a frame outside every root is dropped; without it the path is
   kept from its first `vendor/`, `app/` or `src/` directory, at most the last three segments, and what was cut becomes
   `…/` (`/home/jsmith/app/X.php:12` -> `…/app/X.php:12`); no such directory: no frame. A segment that is not plain
   `[A-Za-z0-9_.-]` or looks random drops the frame; digits are masked. Line numbers and file names are kept.
5. **Model output scrub:** everything the model writes (title, evidence, cause, action) is scanned the same way before it
   is shown, stored or cached; plain numbers are kept.

## What is sent to Slack

The digest and notices: finding titles, evidence, hypothesis and suggested action written by the model from the masked
input; 12-character fingerprints; counts and trends; token and cost figures; release names; and optional links built by
the package (log-query links from configured base URLs, tags, level, time range and a masked template fragment; code links
from your repository URL, commit and a mapped file path). Text from the model has Slack mentions and links disabled
(`@channel`, `<...|...>`, `scheme://`, `www.`, `host.tld` are broken up). Links we build are the only clickable URLs.
Failure notices contain a short reason (for example `source failed: loki 401 Unauthorized`), never keys, webhook URLs,
log lines or stack traces.

## What is sent to the log backend

Read-only queries: your stream tags, level words, and a subset of your watch rules as search filters: `template` rules that are plain literals and `error_type` rules whose regex RE2 can run. The text comes from your config, never from log data. `field` rules and non-literal `template` rules (a template regex applies to the masked template, not to raw lines) are never sent; they are matched in memory on the lines that were read. Nothing derived from log
content is sent back. Credentials should be read-only (Grafana Viewer role; Datadog application key with
`logs_read_data` only).

## What is stored (your state store)

Counters per fingerprint, per-window totals, model verdicts, "already reported" markers, run records, daily spend and
alert markers, and findings (the model's text, scrubbed as described above). Everything expires after `state.retention` (30 days; spend and
alert markers 2 days). Raw lines, field values and unmasked templates are not stored. The file driver names files by
the hash of the key.

## What reaches host logs and exit output

The one-line summary on stdout and `watcher [error] ...` lines on stderr carry messages only, with line breaks removed.
Exception objects are not logged, because the SDK's request (including the API key) hangs on them.

## Limits you must know

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
- **Model wording.** The model never sees personal data from masked input, but its text is still free text. The scrub
  catches the patterns above, not arbitrary words.
- **Field-based rules.** A `field`+`equals` watch rule or mute reads field values inside the process to decide; only the
  rule name leaves. The value `equals` is compared with is in your config.
- **Provider and Slack retention are theirs.** Check your agreement and workspace settings. The package does not select a
  provider region; the API endpoint is the provider's default.
- **Legal basis.** Whether sending masked operational data to an AI provider and a chat tool is lawful for your
  organisation (processor agreements, transfers, retention) is your decision. This page describes the technical data
  flow only.

## How this is tested

- `tests/Fixtures/privacy/` holds a corpus of logs seeded with emails, names (Latin and Cyrillic), phones, tokens, IBANs,
  IPs, personal sub-domains, credentials in URLs and a dependency stream; `seeded-values.php` lists the seeded values (usernames and customer names in frame paths, ids glued to letters, digit-based patterns, JSON message tails included).
- `tests/EndToEnd/PrivacyTest.php` runs the whole pipeline over it and fails if any seeded value appears in **any** request
  to the model (triage, analysis, dependency section, service context), any Slack message (raw, decoded once and twice)
  or any stored value. A control run proves the test sees leaks when the protection is switched off.
- Unit tests cover the normaliser table, the template miner, the redactor's fail-closed behaviour and the Slack text
  escaping. Several defects were found and fixed this way during development; they are listed in `CHANGELOG.md`.

## Auditing a live deployment

During the smoke run (see [smoke-test.md](smoke-test.md), check 4) search the Slack output and the state directory for
values you know exist in the logs. Repeat after any change to `redaction.*`, after adding `extra_patterns`, and when a
new log format appears (a new format can contain personal data in places no rule has seen).
