# Data Model: More Notification Channels

No new state key kinds; nothing is persisted. The heartbeat record already stores a masked failure reason; the mask list
grows (see Secrets).

## Config shape (`notify`)

```php
'notify' => [
    'digest' => ['driver' => 'telegram', 'bot_token' => getenv('TG_TOKEN'), 'chat_id' => '-1001234567890', 'thread_id' => 7],
    'notice' => ['driver' => 'discord',  'webhook'   => getenv('DISCORD_HOOK')],   // optional; null = same as digest
],
```

| Driver | Required | Optional | Types after validation |
|---|---|---|---|
| `slack` (default) | `webhook` | | string |
| `telegram` | `bot_token`, `chat_id` | `thread_id` | `bot_token` string; `chat_id` string (int or numeric string is cast); `thread_id` int >= 1 or absent |
| `discord` | `webhook` | | string |

The validated entry always has `driver`. Keys of another driver, and any unknown key, are rejected
(`notify.digest.webhook is not valid for driver telegram`). Same shape in the fleet file's `notify`.

## Destination (value object, `src/Notify/Destination.php`)

| Member | Note |
|---|---|
| `driver(): string` | `slack`, `telegram`, `discord` |
| `webhook()`, `botToken()`, `chatId()`, `threadId()` | secret accessors; called only by the matching notifier; wrong driver throws `LogicException` |
| `sameAs(self): bool` | driver and all secret values equal |
| `invalidKey(): ?string` | `bot_token`, `chat_id`, `thread_id`, `webhook`, or null; shape rules of FR-012; never returns a value |

Secrets live in closures (as today). `__debugInfo`, `jsonSerialize`, `__serialize`, `var_export` expose
`['driver' => ..., 'secrets' => '[redacted]']` at most.

## Secrets list (`WatchConfig::notifySecrets()`)

Every secret string of both destinations (token, chat id, webhooks), in the order digest then notice. Used by
`NoticeRenderer` (reason masking, minimum 8 characters as today), `Heartbeat` (stored failure reason) and the fleet
heartbeat. Chat ids shorter than 8 characters are not masked by substring (they are not present in any message the
package builds; asserted by the corpus test).

## Rendering types

- `Message` (unchanged): `text`, `sections: list<{kind, text}>`. Section kinds in use: `header`, `severity`, `finding`,
  `minor`, `notice`, `footer`.
- `MessageSplitter::split(list<Closure(int): string> $sections, int $limit, string $cutMarker): list<string>` — each
  closure renders its section from source text capped at the given source length (re-render on cut, R5); returned parts
  are already numbered; throws nothing.

## Payloads

Telegram `sendMessage` JSON: `{chat_id, text, parse_mode: "HTML", link_preview_options: {is_disabled: true}, message_thread_id?}`.
Discord webhook JSON: `{content, allowed_mentions: {parse: []}, flags: 4}`. URL of Telegram:
`https://api.telegram.org/bot<token>/sendMessage` (the only place the token is used).
