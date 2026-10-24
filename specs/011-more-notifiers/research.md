# Research: More Notification Channels

Only decisions where more than one design was reasonable. Facts are from the code as of 2026-10-24.

## R1. Where does channel-specific rendering live?

- **Decision**: renderers (`DigestRenderer`, `NoticeRenderer`, `CorrelationRenderer`, `CombinedDigest`) are untouched
  and keep emitting section text in the Slack-mrkdwn dialect they already produce. A per-driver formatter
  (`TelegramText`, `DiscordText`) converts a section's text to the channel's syntax inside the notifier.
- **Why**: a structured section model (bold/code/link spans) would touch four renderers and their golden tests for a
  feature that adds two channels (constitution VIII). The dialect the renderers produce is tiny and fixed: `*bold*`,
  `` `code` ``, `<https://url|label>` and the entities `&amp; &lt; &gt;`. Everything else is text.
- **The safety invariant**: the formatter **tokenises the source first and decodes entities after**. In the source,
  every `<` that came from model or log text is already `&lt;` (SlackText::escape), so a literal `<url|label>` can
  only be one the package built. Decoding first would let model text forge a link. Unrecognised text is escaped for the
  target; nothing is passed through.
- **Alternatives**: (a) structured sections: cleaner, four renderers and their tests change, rejected for size;
  (b) renderers take a `Formatter` argument: same blast radius; (c) strip formatting on the new channels: loses
  headings and links the digest relies on.
- **Residual**: model text can contain `*word*` which the tokenizer reads as bold, because `SlackText::escape` does not
  neutralise mrkdwn (review-2026-10-23, "mrkdwn not neutralised"). It is formatting only (balanced tags are rebuilt by
  us, so markup stays valid; nothing pings or links). Closing it belongs in `SlackText` for all channels (open
  decision O3), not here.

## R2. Defang once, for all channels

- **Decision**: extract the zero-width-space steps of `SlackText::escape()` into `SlackText::defang()`; `escape()`
  calls it (behaviour identical). The renderers already call `escape()` on every model/log string, so section text
  reaches the formatters defanged. Formatters keep the ZWSP (never call `SlackText::plain()` on sections) and use
  `defang()` for the one path with no Slack-escaped input: `Message::text` of section-less messages.
- **Why**: one rule, one test corpus. Telegram clients auto-link `scheme://`, `host.tld` and `@user`; the same tokens
  are broken as in Slack. Discord links `host.tld` the same way. `#tag` and `/command` are not defanged (harmless:
  no ping, no external link).
- **Known gap shared with Slack**: IDN TLDs are not matched by the `host.tld` pattern (review-2026-10-23). Not widened here.

## R3. Plain content, not Discord embeds

- **Decision**: `content` only. Embeds are not used.
- **Why**: an embed description allows 4096 characters, but a description cannot carry mentions anyway and embeds do
  not make the split problem go away (6000 characters per message across embeds, 10 embeds). The digest is already
  sections of text; `content` with markdown is the shortest path. `flags: 4` (suppress embeds) stops link unfurling.
- **Alternative**: one embed per part to get 4096: cuts the number of parts by about half for long digests. Reconsider
  only if owners complain about part counts.

## R4. Where Telegram's limit is counted

- **Decision**: budget against the rendered HTML string length (tags and entities included), with a headroom limit
  of 4000 (Discord 1900). Telegram counts characters after entity parsing, which is shorter, so the budget is
  conservative.
- **Why**: exact post-parse counting needs an HTML parser; over-counting costs at most one extra part.

## R5. Cutting an over-long section without breaking markup

- **Decision**: cut the **source** section text, re-render, and shrink until the rendered string plus marker fits
  (binary search over the source length). Never cut rendered HTML.
- **Why**: a cut inside `<a href=...>` or `&amp;` makes Telegram reject the whole post (HTTP 400) and Discord
  mangle a link. Cutting source then converting always yields balanced tags; a half token (`<https://x|la`) in the
  source is not a recognised token and is escaped as text.

## R6. Partial delivery of a split message

- **Decision**: parts are sent in order; the first failure stops the message and throws `DeliveryFailed` naming the part.
  No rollback and no retry of earlier parts.
- **Why**: neither API can delete a webhook/bot post atomically; Slack sends one post so the issue is new. A rerun
  after a failed digest delivery re-posts part 1 (the existing rule: the run is reported as failed, state is not
  advanced). Duplicate part 1 is visible and numbered. Idempotency would need a state key per part; not worth it.

## R7. 429 handling: reuse `Retry`, which only reads headers

- **Decision**: add one method to `Retry`: `sendRateLimited(Closure $send, string $name, ?Closure $hint)`. It retries
  **only** 429, using a `$hint` callback (response -> seconds or null) before the `Retry-After` header; same attempts
  (3), same single-wait cap (30 s), budget given at construction and **shared by all parts of one message** (a new
  `Retry` per `Notifier::send`).
- **Why**: Telegram puts `retry_after` in the JSON body (and not reliably in a header); Discord has both. Existing
  `send()` also retries 5xx and transport errors, which is right for idempotent reads and wrong for a POST that may
  have been accepted (duplicate posts). A new method leaves the Loki/Datadog callers untouched.
- **Budget**: `min(60, max(1, lock_ttl / 10))` seconds per message. The digest is posted inside the run lock; with the
  default `lock_ttl` 900 that is 60 s, and with a short `lock_ttl` the waits shrink with it. `Factory::notifier()` gains
  an optional `int $lockTtl = 900` argument.
- **Exception translation**: `Retry` throws `SourceUnavailable` chained to the Guzzle exception (whose message embeds
  the URL). The notifier catches it and throws a fresh, unchained `DeliveryFailed`.

## R8. Keeping the token out of traces

- **Decision**: the token is read from `Destination` only inside the closure passed to `Retry`; no method with a URL or
  token parameter is on the stack when `DeliveryFailed` is thrown. A test throws from every failure path and asserts that
  `(string) $e` (message **and** trace, with `zend.exception_ignore_args=0`) contains neither token nor chat id.
- **Why**: PHP traces print string arguments of active frames. A helper `post(string $url, ...)` that throws would put
  `https://api.telegram.org/bot<TOKEN>/sendMessage` in `getTraceAsString()`. The Slack notifier avoids this by accident
  of structure; for Telegram it is the main leak path.
- **Also**: the token shape is re-checked in the notifier before building the URL (a value containing `/`, `?` or `#`
  would otherwise change the request path or host). Discord's host is re-checked too.

## R9. Destination identity and "same destination"

- **Decision**: `Destination` gets a `driver()` and `sameAs(Destination)`; equality compares driver and every secret
  value inside the closure. It replaces the `$n['notice']['webhook'] === $n['digest']['webhook']` comparisons
  (`Watcher.php:391`, `Doctor::postTest`) and the `['webhook']` reads everywhere.
- **Why**: Telegram has no `webhook` key; comparing one field is wrong (two chats with one bot are different).

## R10. Fleet notify merge

- **Decision**: in `FleetConfig::entries`, `notify.digest` and `notify.notice` of a service **replace** the fleet
  default wholesale; they are not deep-merged.
- **Why**: today `array_replace_recursive` merges `['driver' => 'slack', 'webhook' => ...]` with a service's
  `['driver' => 'telegram', 'bot_token' => ...]` into an entry that has a `webhook` the Telegram validator must reject.
  Replacing the entry is what an operator means by overriding a destination.

## R11. Which validations are config-time, which are `check`-time

- **Decision**: `WatchConfig` validates presence, type and key membership per driver (as it does for Slack: required
  string, no shape). `Destination::invalidKey()` validates shape; `Doctor` calls it (`check`) and the notifiers call it
  before any request.
- **Why**: matches the current split (config: `str`; `check`: https URL) and keeps an operator's unrelated command
  (`status`) from failing on a shape that only matters when posting.

## R12. No `getMe` probe

- **Decision**: `check` is syntactic for Telegram; no API call by default (owner decision O1).
- **Why**: `getMe` would put the token on the wire in a plain `check` that today sends no credential of the notifier
  anywhere; it proves the token but not the chat, which only `--post-test` proves. Syntactic checks catch typos, the
  post-test catches the rest.
