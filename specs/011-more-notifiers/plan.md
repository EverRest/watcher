# Implementation Plan: More Notification Channels

**Branch**: `011-more-notifiers` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: [spec.md](spec.md). Decisions: [research.md](research.md). Config and objects: [data-model.md](data-model.md).
Contracts: [contracts/config.md](contracts/config.md), [contracts/message-limits.md](contracts/message-limits.md).
Scenarios: [quickstart.md](quickstart.md). Order: [tasks.md](tasks.md).

## Summary

Add `telegram` and `discord` next to `slack` behind the unchanged `Notifier` contract. A `Destination` learns its driver;
a `RoutingNotifier` picks the notifier by driver; each new notifier renders section text through a small formatter,
splits at section boundaries, and honours 429. No new dependency (Guzzle is already used), no new state key, no new CLI
command, no change to `Message` or to the four core contracts (`LogSource`, `StateStore`, `Notifier`, `Analyzer`).

## Technical Context

**Language/Version**: PHP ^8.1. **Dependencies**: none added (Guzzle already present). **Storage**: none.
**Testing**: PHPUnit `Unit` / `Contract` / `EndToEnd`; Guzzle `MockHandler` with recorded fixtures under
`tests/Fixtures/telegram` and `tests/Fixtures/discord`; no network. Gates: `composer test`, `composer stan`,
`composer style`; read `$?`, not the last output line.
**Performance**: a digest is at most 10 posts; waits bounded by `min(60 s, lock_ttl / 10)` per message.
**Egress added**: `api.telegram.org`, `discord.com` (and `discordapp.com`); documented in docs/operations.md.

## Constitution Check

| Principle | Result |
|---|---|
| I Privacy fails closed | Pass, with work: what leaves is unchanged, but credentials (token in URL, chat id) are new secrets; FR-011 + privacy corpus extension (T016) in the same change. Escaping fails closed: unrecognised markup is text. |
| II Read-only | Pass: notifier posts a message to its configured channel and nothing else; no `getMe`, no `getUpdates`, no deletes. Architecture test extended (T017) to pin the endpoints. |
| III Test-first | Pass: every task is red then green; HTTP recorded, no network. |
| IV Code decides | Pass: no model involvement; splitting and cutting are deterministic. |
| V Token/cost | Pass: no change in tokens. |
| VI Config/contracts | Pass: driver, token, chat, thread from config; no hard-coded channel; `Notifier` contract unchanged; validated once at start; secrets from env via config. |
| VII Failures loud | Pass: cut sections carry a visible marker, dropped sections are announced (FR-013), delivery failure names part and status. |
| VIII Simplicity | Pass with one note: three small new classes shared by both drivers (`MessageSplitter`, `RoutingNotifier`, drivers' formatters) are the minimum for 2 new implementations; structured-section model rejected (R1). |

## Design in one page

1. **Destination** (`src/Notify/Destination.php`): static constructors `slack($webhook)`, `telegram($token, $chatId,
   ?$threadId)`, `discord($webhook)`; the existing `new Destination($webhook)` stays and means Slack (no caller or test
   breaks). Secrets stay in closures. Adds `driver()`, `sameAs()`, `invalidKey()`, and accessor methods that only the
   matching notifier calls (`webhook()`, `botToken()`, `chatId()`, `threadId()`).
2. **WatchConfig** builds destinations: `destination('digest'|'notice'): Destination`, `notifySecrets(): list<string>`,
   and a static `destinationFrom(array $entry)` for the fleet. `Watcher`, `Doctor`, `FleetRunner` stop reading
   `['webhook']`.
3. **RoutingNotifier** (`src/Notify/RoutingNotifier.php`) implements `Notifier`, dispatches on `$to->driver()`.
   `Factory::notifier()` returns it; its signature gains an optional lock-ttl argument.
4. **TelegramNotifier / DiscordNotifier** (`src/Notify/`): render each section via the formatter, hand the rendered
   strings to `MessageSplitter`, POST the parts in order through `Retry::sendRateLimited`.
5. **TelegramText / DiscordText**: `section(string $kind, string $sourceText): string`, `plain(string $text): string`;
   pure functions, the escaping tables of contracts/message-limits.md.
6. **MessageSplitter**: packs rendered sections into parts under a limit, numbers them, cuts, caps at 10.
7. **Fleet**: destinations built with `WatchConfig::destinationFrom`; service `notify` entries replace fleet defaults
   (R10); `digest: combined` requires a digest destination of any driver.
8. **Doctor**: `Destination::invalidKey()` for the syntactic check; `--post-test` groups by `sameAs`.

## Files

| New | Modified |
|---|---|
| `src/Notify/TelegramNotifier.php`, `DiscordNotifier.php`, `RoutingNotifier.php`, `TelegramText.php`, `DiscordText.php`, `MessageSplitter.php` | `src/Notify/Destination.php`, `SlackText.php` (extract `defang`), `NoticeRenderer.php` (secrets list only) |
| tests under `tests/Unit/Notify/`, `tests/Unit/Config/NotifyConfigTest.php`, `tests/EndToEnd/MultiChannelTest.php`, fixtures `tests/Fixtures/{telegram,discord}` | `src/Source/Retry.php` (new method), **`src/Config/WatchConfig.php`**, **`src/Config/Factory.php`**, `src/Watcher.php`, `src/Fleet/FleetConfig.php`, `src/Fleet/FleetRunner.php`, `src/Operate/Doctor.php`, `tests/Unit/ArchitectureTest.php`, `tests/Unit/PrivacyCorpusTest.php`, `tests/EndToEnd/PrivacyTest.php`, `config/watcher.example.php`, docs (configuration, privacy, operations), `CHANGELOG.md`, `docs/examples/watcher-fleet.php` |

`src/Operate/CapturingNotifier.php` and `src/Fleet/CollectingNotifier.php` need no change (they ignore the destination
or pass it through).

## Release

Additive configuration, Slack unchanged: **MINOR** release with a CHANGELOG entry and an upgrade note ("none required").

## Risks

| Risk | Handling |
|---|---|
| Token in a trace/log | R8; test on every failure path |
| Model text forges a link/mention via decoded entities | R1 ordering invariant; SC-002 corpus |
| WatchConfig/Factory/Watcher are being edited by other specs (004-006 in flight) | tasks touching them are sequential and rebased on the then-current file; T001 checks |
| Duplicate part 1 on rerun | accepted (R6), documented in docs/operations.md |
