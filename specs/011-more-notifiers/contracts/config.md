# Contract: `notify` configuration and `check` output

## Keys

See data-model.md for shapes. Defaults: `driver` = `slack`; `notice` = `digest` when absent or null; `thread_id` absent.

## Validation messages (config load, before any call)

Every message names the key path, never a value. Existing Slack messages are unchanged.

| Condition | Message |
|---|---|
| `notify.<kind>` not a map | `notify.<kind>.webhook is required` (Slack, unchanged) for slack/default; for a map with `driver: telegram` see below |
| driver not a string | `notify.<kind>.driver must be a string` |
| driver unknown | `notify.<kind>.driver must be one of slack, telegram, discord` |
| required key missing / not a non-empty string | `notify.<kind>.<key> is required` |
| `chat_id` not int or string | `notify.<kind>.chat_id must be an integer or a string` |
| `thread_id` not a positive integer | `notify.<kind>.thread_id must be a positive integer` |
| key of another driver | `notify.<kind>.<key> is not valid for driver <driver>` |
| unknown key | existing `only()` message |
| fleet `digest: combined` without a digest destination | `digest combined needs notify.digest` |

Fleet errors keep the entry prefix (`services[1] (bff-a): notify.digest.bot_token is required`).

## `watcher check` (syntactic, no network)

Check name `notifier` (unchanged). Fail detail lists `<kind> <key> invalid` per destination, joined by `, `:

| Driver | Rule (from `Destination::invalidKey()`) |
|---|---|
| telegram `bot_token` | `^\d+:[A-Za-z0-9_-]{30,}$` |
| telegram `chat_id` | `^-?\d{1,20}$` or `^@[A-Za-z][A-Za-z0-9_]{4,31}$` |
| telegram `thread_id` | integer >= 1 (already guaranteed by config; checked again) |
| discord `webhook` | `^https://(?:(?:canary\|ptb)\.)?(?:discord\|discordapp)\.com/api(?:/v\d+)?/webhooks/\d+/[A-Za-z0-9_-]+$` |
| slack `webhook` | https URL with a host (as today) |

Example failure: `notifier: digest bot_token invalid, notice webhook invalid`. Nothing else is printed.

`--post-test` (check name `post_test`): one message per distinct destination (`sameAs`), text
`watcher check: test message for <service> (<kinds>) - safe to ignore`, e.g. `(digest, notice)`. Failure detail:
`delivery failed (digest)`; the exception text is not printed (it carries no secret, but the label is enough).
`--json` output has the same fields; no destination value in any field.

## Dry run

Unchanged: `== DRY RUN message to digest ==` followed by `Message::text`.

## Secrets (FR-011)

Surfaces that must not contain a secret: exception message and trace of `DeliveryFailed`; Watcher/fleet logger
context; notices (masked via `notifySecrets()`); heartbeat `last failure` reason; `check`, `status`, `run --json`;
`Destination` under `var_dump`, `print_r`, `var_export`, `json_encode`, `serialize`.
