---

description: "Task list for More Notification Channels (everrest/watcher)"
---

# Tasks: More Notification Channels

**Input**: `specs/011-more-notifiers/` (plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md)

**Tests**: REQUIRED (Constitution III). In each task the test is written first and must fail first; the implementation
turns it green. No test touches the network (Guzzle `MockHandler`, recorded fixtures).

**Format**: `[ID] [P?] [Story] Description`. Paths relative to `/path/to/watcher`. `[P]` = different files, no
unfinished dependency.

**SEQUENTIAL** (shared files, run one at a time, in this order): **T011** (`src/Config/WatchConfig.php`), **T012**
(`src/Config/Factory.php`), **T013** (`src/Watcher.php`), **T014** (`src/Fleet/FleetConfig.php`,
`src/Fleet/FleetRunner.php`), **T015** (`src/Operate/Doctor.php`). T011 and T012 are the only tasks that touch
`src/Config/WatchConfig.php` and `src/Config/Factory.php`; nothing else may edit them. Other specs (004-006) also edit
these files: rebase on the then-current file before each of these tasks.

**Gate command**: `composer test; echo $?` then `composer stan; echo $?` then `composer style; echo $?`. Read the exit codes.

**No commits, pushes or tags** unless the owner asks.

---

## Phase 0: Gate

- [ ] T001 Run the gate command on the current tree and record the exit codes. Re-read `src/Notify/Destination.php`,
  `src/Watcher.php` (the `['webhook']` reads at the digest send, `announceDigestFailure`, `notices()`,
  `noticeDestination`), `src/Fleet/FleetRunner.php` and `src/Fleet/FleetConfig.php`; list every `['webhook']` read in
  `src/` with `grep -rn "\['webhook'\]" src` and paste the list into the T013/T014 notes (it is the checklist for them).
  Check no other spec has an uncommitted edit in `WatchConfig.php`, `Factory.php`, `Watcher.php`. **Blocks all.**

## Phase 1: Building blocks (parallel; each is a new or independent file)

- [ ] T002 [P] [US3] Red tests in `tests/Unit/Notify/DestinationTest.php` (extend): factories `slack/telegram/discord`;
  `new Destination($url)` still Slack; `driver()`; `sameAs` (same chat different thread = different); `invalidKey()`
  per contracts/config.md table (token shape, chat id forms, discord hosts incl. `discordapp.com`, a look-alike host
  `discord.com.evil.example` rejected); wrong-driver accessor throws `LogicException`; `var_export`, `print_r`,
  `json_encode`, `serialize` hide token, chat id and webhook. Implement in `src/Notify/Destination.php`. Greens FR-011
  (object part), FR-012.
- [ ] T003 [P] [US1] Red tests in `tests/Unit/Notify/SlackTextTest.php` (new): `defang()` breaks `://`, `www.`,
  `host.tld`, `@`, keeps `X.php:88`; `escape()` output is byte-identical to today for a corpus of 20 strings (copy them
  from `NoticeRendererTest`/`DigestRendererTest` expectations). Extract `defang()` in `src/Notify/SlackText.php`;
  `escape()` calls it. Greens R2.
- [ ] T004 [P] [US1] Red tests in `tests/Unit/Notify/TelegramTextTest.php`: every row of the Telegram table in
  contracts/message-limits.md; `testModelTextIsInert` (seed `<b>x</b>`, `&amp;`, `http://a.example`, `www.a.example`,
  `host.example`, `@everyone` through `SlackText::escape` then the formatter: no live tag other than `b i code a`, no
  autolinkable token); `testForgedLinkTokenInModelTextIsText` (model text `<http://evil.example|click>` is `&lt;...` in
  the source and must come out as text); URL with `"` or `<` in a package link is dropped to text; unmatched `*` and
  `` ` `` stay text; ZWSP preserved; `plain()` path for section-less text applies `defang()`. Implement
  `src/Notify/TelegramText.php` (`section(kind, source)`, `plain(text)`), static, no dependencies.
- [ ] T005 [P] [US2] Red tests in `tests/Unit/Notify/DiscordTextTest.php`: every row of the Discord table;
  `testModelTextIsInert` (seed `**x**`, `__x__`, `@everyone`, `<@123>`, `<#123>`, `<:e:1>`, `[x](http://y.example)`,
  `|| spoiler ||`, `# heading` at line start, `> quote`); forged-token test as in T004; backtick replacement; `plain()`
  path. Implement `src/Notify/DiscordText.php`.
- [ ] T006 [P] [US1] Red tests in `tests/Unit/Notify/MessageSplitterTest.php` with a fake render closure:
  `testPartsFitAndNumbered` (1, 5, 40 sections), no section split across parts, header first / footer last,
  `testOverlongSectionCutWithMarker` (cut on code point boundary, shrink loop terminates for a marker longer than the
  limit edge case), `testPartCapAnnouncesRemainder` (>10 parts), single part has no prefix, counts are multi-byte
  aware, empty section list. Implement `src/Notify/MessageSplitter.php`
  (`split(list<string|Closure> ...)` signature per data-model.md; sections supplied as `Closure(int $maxChars): string`
  so the cut re-renders the source, R5).
- [ ] T007 [P] [US1] Red tests in `tests/Unit/Source/RetryTest.php` (create if absent; existing behaviour covered by the
  Loki/Datadog contract tests): `sendRateLimited` retries only 429 (502, 503, transport error return/throw at once),
  uses `$hint` before `Retry-After`, caps one wait at 30 s, shares one budget across calls on the same instance (no
  per-call reset), fails fast when the hint exceeds the remaining budget, `send()` unchanged. Implement
  `Retry::sendRateLimited(Closure $send, string $name, ?Closure $hint = null)` in `src/Source/Retry.php` without
  touching `send()`.

## Phase 2: Notifiers (parallel with each other; depend on Phase 1)

- [ ] T008 [P] [US1] Fixtures `tests/Fixtures/telegram/{ok,429,403,400}.json`. Red tests in
  `tests/Unit/Notify/TelegramNotifierTest.php`: scenarios 1, 3, 6, 7, 8, 9 of quickstart.md; request URL
  `https://api.telegram.org/bot<token>/sendMessage`, JSON body per data-model.md, `message_thread_id` only when set,
  a malformed token is refused before any request, redirects off, `testNoSecretInAnyFailure` asserts `(string) $e`
  (message and trace, `zend.exception_ignore_args=0`) has neither token nor chat id for 403, 429-exhausted, transport
  error and malformed-token paths. Implement `src/Notify/TelegramNotifier.php`: throw only from a frame with no
  token/URL argument (R8); catch `SourceUnavailable` and rethrow an unchained `DeliveryFailed`. Depends T002, T004,
  T006, T007.
- [ ] T009 [P] [US2] Fixtures `tests/Fixtures/discord/{204,429-body,429-header,404}.json`. Red tests in
  `tests/Unit/Notify/DiscordNotifierTest.php`: scenarios 10, 12 plus the same secret-in-trace test for the webhook;
  host re-check refuses `https://discord.com.evil.example/...`. Implement `src/Notify/DiscordNotifier.php`. Depends
  T002, T005, T006, T007.
- [ ] T010 [US3] Red tests in `tests/Unit/Notify/RoutingNotifierTest.php`: each driver reaches the right inner notifier
  (inner fakes), failure from the inner propagates untouched. Implement `src/Notify/RoutingNotifier.php`
  (constructor takes the three `Notifier`s). `SlackWebhookNotifier` is not modified (it reads `$to->webhook()`, which
  works for a Slack destination). Depends T008, T009.

## Phase 3: Configuration and wiring (SEQUENTIAL)

- [ ] T011 [US3] **SEQUENTIAL, touches `src/Config/WatchConfig.php`.** Red tests in
  `tests/Unit/Config/NotifyConfigTest.php`: every row of the validation table in contracts/config.md (message text is
  exact, no value ever in a message: use a sentinel secret and assert absence), defaults, `notice` falls back to
  digest, `destination('digest')`/`destination('notice')` return the right driver, `notifySecrets()` lists all
  secrets, static `destinationFrom()` for the fleet; existing `WatchConfigTest` unchanged and green. Implement in
  `src/Config/WatchConfig.php`: make `destination()` per-driver (keep the Slack error texts), add the public methods,
  normalise `chat_id` to string. Depends T002.
- [ ] T012 [US3] **SEQUENTIAL, touches `src/Config/Factory.php`.** Red tests in `tests/Unit/Config/FactoryTest.php`:
  `notifier($http, $lockTtl)` returns a `RoutingNotifier`, retry budget `min(60, max(1, lockTtl/10))`, `doctor()` passes
  the config's `lock_ttl`, `heartbeat()` mask list equals `notifySecrets()`. Implement in `src/Config/Factory.php`
  (optional `int $lockTtl = 900`, so `FleetCheck`/`FleetRunner` callers compile unchanged). Depends T010, T011.
- [ ] T013 [US3] **SEQUENTIAL, touches `src/Watcher.php`, `src/Notify/NoticeRenderer.php`.** Red tests in
  `tests/EndToEnd/MultiChannelTest.php`: `testDigestFailureAnnouncedOnOtherChannel`, `testSameDestinationNoAnnouncement`
  (Telegram+Telegram same chat vs different chats), notices go to the notice destination, secrets from
  `notifySecrets()` masked in a failure reason (token embedded in a source error text). Replace the `['webhook']`
  reads listed in T001 with `$this->config->destination(...)`, `Destination::sameAs`, `notifySecrets()`; keep the
  `NoticeRenderer` constructor, only its callers change. Depends T011, T012.
- [ ] T014 [US3] **SEQUENTIAL after T013, touches `src/Fleet/FleetConfig.php`, `src/Fleet/FleetRunner.php`.** Red tests:
  `tests/Unit/Fleet/FleetConfigTest.php::testServiceDestinationReplacesFleetDefault` (and the reverse: service without
  its own entry inherits), `::testCombinedNeedsDigestDestinationOfAnyDriver`, fleet-level `notify` validated with the
  same rules and error path prefix; `MultiChannelTest::testFleetPostsUseFleetDestination` (budget alert, correlated
  finding, combined digest on a Discord fake). Implement: wholesale replace of `notify.digest`/`notify.notice` in
  `entries()` (R10), destinations from `WatchConfig::destinationFrom`, heartbeat secrets from each service's
  `notifySecrets()`. Depends T011, T013.
- [ ] T015 [US4] **SEQUENTIAL after T014, touches `src/Operate/Doctor.php`.** Red tests in
  `tests/Unit/Operate/DoctorTest.php`: scenarios 21-23 of quickstart.md (zero HTTP calls in plain `check`, labels,
  distinctness via `sameAs`, no secret in text or JSON output). Implement: `notifier()` uses
  `Destination::invalidKey()`; `postTest()` groups by `sameAs`. Depends T011.

## Phase 4: Proof and documentation

- [ ] T016 [US1] Privacy corpus (Constitution I, same change). Extend `tests/Unit/PrivacyCorpusTest.php` and
  `tests/EndToEnd/PrivacyTest.php`: run the seeded corpus through all three drivers; assert on every outbound request
  body, every exception string and trace, the `SpyLogger` records, the heartbeat record, `check`/`status`/`run --json`
  output: 0 seeded personal values, 0 credentials (token, chat id, webhooks); seeded `@everyone`/`<@1>` yield
  `allowed_mentions.parse === []` on Discord. Fix whatever it finds. Depends T013-T015.
- [ ] T017 [P] [US1] Architecture test in `tests/Unit/ArchitectureTest.php`: `TelegramNotifier` issues only POST to
  `https://api.telegram.org/bot<token>/sendMessage` (extend `assertReadOnly`'s idea: allowed methods and paths), no other
  Bot API method name appears under `src/`; `DiscordNotifier` only POSTs to the webhook; the notifier list
  (`glob('src/Notify/*Notifier.php')`) is pinned so a new notifier needs its own rule. Depends T008, T009 (can run in
  parallel with T011-T016).
- [ ] T018 Docs and release. Update `docs/configuration.md` (`notify` section: drivers, keys, fleet override rule),
  `docs/privacy.md` ("What is sent to Slack" becomes "to your notification channel", credential handling, Telegram/
  Discord retention is theirs), `docs/operations.md` (egress hosts, `check` output, duplicate part 1 on rerun),
  `docs/smoke-test.md` (manual scenario of quickstart.md section 3), `config/watcher.example.php`,
  `docs/examples/watcher-fleet.php`, `README.md` (channels line), `CHANGELOG.md` (MINOR, additive, no upgrade step).
  Run the gate command; all exit 0. Depends T016, T017.

---

## Dependencies and parallel groups

```
T001
 ├─ [P] T002  T003  T004*  T005*  T006  T007        (* T004/T005 also need T003)
 │        └──────────────┬──────────────────────────┘
 │              T008 [P] T009 [P]   (T008: T002,T004,T006,T007; T009: T002,T005,T006,T007)
 │                       └─ T010
 │  T011 (WatchConfig, needs T002) ─ T012 (Factory, needs T010,T011) ─ T013 (Watcher) ─ T014 (Fleet) ─ T015 (Doctor)
 │  T017 [P] (needs T008,T009)
 └──────────────────────────────── T016 ─ T018
```

| Group | Tasks | Mode |
|---|---|---|
| A: foundations | T002, T003, T006, T007 | parallel |
| A2: formatters | T004, T005 | parallel, after T003 |
| B: notifiers | T008, T009 | parallel; then T010 |
| C: wiring | T011 -> T012 -> T013 -> T014 -> T015 | **sequential** (T011, T012 own `WatchConfig.php` and `Factory.php`) |
| D: proof | T017 parallel with group C; T016 then T018 last | |

T011 may start as soon as T002 is green (it does not need the notifiers) and can overlap group B; T012 waits for T010.
