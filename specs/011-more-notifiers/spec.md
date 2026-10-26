# Feature Specification: More Notification Channels (Telegram and Discord)

**Feature Branch**: `011-more-notifiers`

**Created**: 2026-10-24

**Status**: Implemented

**Input**: "Add Telegram and Discord as notification channels besides Slack, for the digest, the notices and the fleet posts."

## Overview

Today every message goes to a Slack incoming webhook (`notify.digest`, `notify.notice`; the fleet has the same two
keys). Teams that do not use Slack cannot adopt the package. This feature adds two drivers, `telegram` and `discord`,
behind the existing `Notifier` contract. Slack stays the default and its output does not change. Each destination
(digest, notice, fleet digest/notice) chooses its driver on its own, so the digest may go to Telegram and the failure
notices to Discord.

The risk is not the HTTP call, it is what the new channels do with text that originates in logs and in the model:
Telegram puts the **bot token in the request URL**, Discord renders `@everyone` and `<@id>` unless told not to, both
have message-size limits far below the digest size, and both rate-limit with a `retry_after`. The privacy rules do not
change (constitution I): the same masked, model-written text leaves the host, to a different place.

**Actors**: **Operator** (configures destinations, runs `watcher check`), **Service owner** (reads the digest in the
channel), **Security reviewer** (needs proof that no credential appears anywhere and that model text cannot ping or link).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Receive the digest in Telegram (Priority: P1)

The operator sets `notify.digest` to the `telegram` driver with a bot token and chat id and receives the same digest
content as in Slack, readable, split in several numbered posts when long.

**Why this priority**: first requested channel; proves the whole path (config, render, split, deliver, rate limit).

**Independent Test**: end-to-end run with fakes and a recorded Telegram HTTP fixture; assert payloads.

**Acceptance Scenarios**:

1. **Given** a short digest, **When** the run delivers, **Then** one `sendMessage` with `parse_mode` HTML reaches the
   configured chat (and thread when `thread_id` is set), headings bold, links clickable, no link preview.
2. **Given** a digest longer than the Telegram limit, **When** delivered, **Then** it is several posts, each prefixed
   `(1/3)`, `(2/3)`, `(3/3)`, split only between sections, in order.
3. **Given** a single section longer than the limit, **When** delivered, **Then** it is cut with a visible marker and
   the post is still valid HTML.
4. **Given** model text containing `<b>`, `&`, `http://x.example`, `@someone`, **When** delivered, **Then** it appears as
   inert text: escaped, not parsed, not a link, not a mention.
5. **Given** Telegram answers 429 with `retry_after`, **When** delivering, **Then** the post is retried after that
   wait within the bounded budget; beyond the budget it fails loudly.

### User Story 2 - Receive the digest in Discord (Priority: P1)

The operator sets `notify.digest` to the `discord` driver with a webhook URL and receives the digest as Discord
markdown posts.

**Independent Test**: same as US1 with a recorded Discord fixture.

**Acceptance Scenarios**:

1. **Given** any digest, **When** delivered, **Then** every request body carries `allowed_mentions: {"parse": []}`, so
   no text can notify anyone, even if a mention-looking string slipped past escaping.
2. **Given** a digest longer than 2000 characters, **When** delivered, **Then** it is split at section boundaries and
   numbered, as in US1.
3. **Given** model text containing `**`, `@everyone`, `<@123>`, `<#123>`, `[x](http://y.example)`, **When** delivered,
   **Then** it is inert text.
4. **Given** Discord answers 429 (`retry_after` in body and `Retry-After` header), **When** delivering, **Then** as US1-5.

### User Story 3 - Different channels for different messages (Priority: P1)

The digest goes to one driver, the failure/budget/weekly notices to another, and a fleet posts its combined digest,
correlated findings and budget alerts to its own destination, which may differ from those of its services.

**Acceptance Scenarios**:

1. **Given** digest on Telegram and notice on Discord, **When** a run fails, **Then** the notice is posted to Discord.
2. **Given** the digest destination is down, **When** the notice destination is another one, **Then** the existing
   "digest delivery failed: HTTP nnn" notice is posted there; when both are the same destination it is not
   (today's rule, extended to any driver).
3. **Given** a fleet whose default `notify.digest` is Slack and a service that overrides it with Telegram, **When** the
   fleet file is validated, **Then** the service uses Telegram only (no Slack key leaks into the merged entry).

### User Story 4 - `watcher check` validates and tests every destination (Priority: P2)

`watcher check` reports, per destination, whether the configuration is syntactically valid; `--post-test` posts one
labelled test message per **distinct** destination.

**Acceptance Scenarios**:

1. **Given** a malformed Telegram token, **When** `check` runs, **Then** it fails with `digest bot_token invalid`
   (key name only, never the value) and makes no call.
2. **Given** digest and notice are the same Telegram chat, **When** `--post-test`, **Then** one message is posted,
   labelled `(digest, notice)`.
3. **Given** delivery fails, **When** `--post-test`, **Then** the report says `delivery failed (digest)` and nothing in
   the output (text or `--json`) contains the token, chat id or webhook.

### Edge Cases

- `chat_id` as `@channelname` (public channel) vs. a negative integer (group/supergroup) vs. a positive user id.
- `thread_id` set for a chat that has no topics: Telegram rejects; surfaces as `HTTP 400`, no detail.
- Telegram and Discord both receive an emoji-heavy digest (the icons are UTF-8; length is counted in characters).
- Message with no sections (the `check` test message, fleet alerts built from `text`): rendered from `text`, escaped.
- Telegram or Discord returns a 3xx (webhook moved): treated as failure; redirects are not followed with credentials.
- A destination key from another driver (`webhook` under `telegram`): rejected at start.
- Heartbeat/status JSON and `--json` summaries: never contain destination values.
- Digest of 40 sections where each is near the section cap: the number of posts is capped (FR-013), the remainder is
  announced, never silently dropped.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `notify.<digest|notice>.driver` accepts `slack` (default), `telegram`, `discord`. An unknown driver fails
  at start naming `notify.<kind>.driver`.
- **FR-002**: Telegram destination keys: `bot_token` (required), `chat_id` (required), `thread_id` (optional positive
  int). Discord: `webhook` (required). Slack: `webhook` (required). A key that does not belong to the chosen driver, or a
  missing required key, fails at start naming the key path, never the value.
- **FR-003**: Each destination (digest, notice, fleet digest, fleet notice) chooses its driver independently; omitted
  `notice` still means "same as digest".
- **FR-004**: The `Message` value object and the `Notifier` contract do not change. Sections stay renderer-agnostic in
  meaning; the driver turns them into its format (contracts/message-limits.md).
- **FR-005**: Telegram posts use `parse_mode` HTML, link previews off. Every character from model, log or exception
  text is HTML-escaped; only package-built tags (`b`, `i`, `code`, `a href`) appear.
- **FR-006**: Discord posts use plain `content` (no embeds) with `allowed_mentions: {"parse": []}` and embeds
  suppressed. Markdown control characters in model text are escaped.
- **FR-007**: The zero-width-space defang of auto-linkable tokens (`scheme://`, `www.`, `host.tld`, `@`) applies on all
  three channels, to every string that originates outside the package.
- **FR-008**: Length limits and splitting follow contracts/message-limits.md: split between sections only, parts
  numbered `(n/m)`, an over-long section cut with a visible marker, markup always valid after a cut.
- **FR-009**: A 429 is retried after the server's `retry_after` (Telegram body `parameters.retry_after`; Discord body
  `retry_after` and header `Retry-After`) using the existing bounded retry rules: at most 3 attempts per post, a single
  wait capped at 30 s, total waits per message within a budget derived from `schedule.lock_ttl`
  (`min(60 s, lock_ttl / 10)`). Only 429 is retried; a transport error or 5xx is not (a POST may have been accepted).
- **FR-010**: Delivery failure throws `DeliveryFailed` whose message is the driver name and status code only
  (`telegram delivery failed: HTTP 403`, `discord delivery failed: transport error`), never chained to the HTTP
  library exception, and for a split message names the part (`HTTP 429 at part 2 of 3`).
- **FR-011**: Secrets MUST NOT appear in any exception message or trace, log line, notice, heartbeat record,
  `check`/`status`/`run` output (text or JSON), dry-run output, or `var_dump`/`json_encode`/`serialize` of any object.
  Secrets are: Telegram `bot_token` and `chat_id`, Discord and Slack `webhook`.
- **FR-012**: `watcher check` validates each destination syntactically without a network call: Telegram token
  `^\d+:[A-Za-z0-9_-]{30,}$`, chat id an integer or `@name` (`^@[A-Za-z][A-Za-z0-9_]{4,31}$`), thread id positive int;
  Discord `https://discord.com|discordapp.com/api[/vN]/webhooks/<id>/<token>`; Slack as today (https URL). The failure
  names the destination and key only. `--post-test` posts one labelled message per distinct destination (distinct =
  different driver or different secret values).
- **FR-013**: At most 10 posts per message. Sections beyond the cap are replaced by one visible line
  `… N more sections not shown`.
- **FR-014**: Config validation errors for notify keys name the key path and never the value.
- **FR-015**: Dry run (`--dry-run`) is unchanged: it prints `Message::text`, posts nothing, reads no destination value.
- **FR-016**: A Slack-only configuration (every existing config) behaves byte-for-byte as before.
- **FR-017**: Hosts reached by the package grow by `api.telegram.org` and `discord.com` (`discordapp.com`); each
  notifier performs only a POST to its message endpoint (read-only rule, constitution II, extended in the architecture test).

### Key Entities

- **Destination** — driver plus secret values, redacted on every dump (data-model.md).
- **Channel formatter** (`TelegramText`, `DiscordText`) — converts section text and plain text to the channel's
  syntax with its escaping rules.
- **Message splitter** — packs rendered sections into parts under a limit.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Privacy corpus: 0 seeded personal values and 0 seeded credentials (token, chat id, webhooks) in any
  outbound payload, exception string, trace, log line, check/status output, for all three drivers.
- **SC-002**: A seeded model string with `<b>`, `<a href>`, `**`, `[x](y)`, `@everyone`, `<@1>`, `http://a.example`,
  `www.a.example`, `host.example` yields in every driver's payload no live markup, link or mention (asserted on the
  payload text, and `allowed_mentions.parse` is `[]` for Discord).
- **SC-003**: For digests of 1, 5, 40 sections (sizes up to the section cap): every part is within the channel limit;
  concatenated parts contain every section exactly once (or are announced as not shown); no part starts or ends
  inside a section.
- **SC-004**: 429 with `retry_after` 2: delivered after the stated wait on the second attempt; `retry_after` 600 or
  an exhausted budget: fails with HTTP 429, no sleep beyond the cap.
- **SC-005**: Every existing test for Slack passes unchanged (FR-016).
- **SC-006**: `watcher check` on a config with one bad key of each driver reports exactly that key, 0 network calls.

## Assumptions

- The Bot API method is `sendMessage` over HTTPS at `api.telegram.org`; HTML parse mode supports `b`, `i`, `code`, `a`.
- The operator creates the bot, adds it to the chat and supplies token and chat id through the host's environment.
  The package never calls `getMe`, `getUpdates` or any method except `sendMessage` (open decision O1).
- Discord webhook messages accept `content` up to 2000 characters; Telegram `text` up to 4096 characters after entity
  parsing.
- No threads/buttons/inbound handling (spec 005 US3 stays out of scope); one-way posting only.
- The digest content and what may leave the host are unchanged (docs/privacy.md); only the recipient differs.
- The first release keeps `Message::text` as the plain fallback; it is used only when a message has no sections.

## Owner decisions (2026-10-24)

O1 no `getMe` probe in `check` (syntactic validation only); O2 a re-post after a partial multi-part failure may visibly repeat part 1, accepted; O3 mrkdwn neutralisation of model text is separate work for all channels (batch 5); O4 `--dry-run` output unchanged; O5 plain content, no embeds; O6 retry budget `min(60, lock_ttl/10)` and the 10-post cap confirmed; O7 no Discord thread equivalent.
