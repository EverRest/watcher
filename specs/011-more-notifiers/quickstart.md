# Quickstart: validating More Notification Channels

## 1. Gates (no network)

```bash
composer install
composer test; echo $?
composer stan; echo $?
composer style; echo $?
```

Read each exit code on its own line. Fakes and recorded HTTP only: `FakeNotifier`, Guzzle `MockHandler` with
`tests/Fixtures/telegram/*.json` and `tests/Fixtures/discord/*.json`, harness `tests/EndToEnd/Harness.php`.

## 2. Scenarios, mapped to tests

| # | Scenario | Expected | Test (file::method) | Spec |
|---|---|---|---|---|
| 1 | Short digest to Telegram | one `sendMessage`, HTML, preview off, thread id passed | `tests/Unit/Notify/TelegramNotifierTest.php::testShortDigestIsOnePost` (T008) | US1-1 |
| 2 | Digest of 40 max-size sections | <= 10 posts, each <= limit, numbered, sections once | `tests/Unit/Notify/MessageSplitterTest.php::testPartsFitAndNumbered`, `::testPartCapAnnouncesRemainder` (T006) | US1-2, FR-013, SC-003 |
| 3 | One section of 6000 chars | cut with marker, balanced tags | `MessageSplitterTest::testOverlongSectionCutWithMarker`; `TelegramTextTest::testCutSourceStaysBalanced` (T004, T006) | US1-3, R5 |
| 4 | Model text with `<b>`, `&`, URLs, `@x` | inert | `tests/Unit/Notify/TelegramTextTest.php::testModelTextIsInert` (T004) | US1-4, SC-002 |
| 5 | Forged `<http://evil\|click>` in model text | stays text (entity-escaped in source) | `TelegramTextTest::testForgedLinkTokenInModelTextIsText`; `DiscordTextTest::...` | R1 |
| 6 | Telegram 429 `retry_after` 2 | second attempt after 2 s sleeper call | `TelegramNotifierTest::testRateLimitHonoured` | US1-5, SC-004 |
| 7 | 429 `retry_after` 600 | fails `HTTP 429`, no sleep | `TelegramNotifierTest::testHintAboveBudgetFailsFast` | SC-004 |
| 8 | 5xx / transport error | not retried, unchained `DeliveryFailed` | `TelegramNotifierTest::testServerErrorNotRetried`, `::testTransportErrorUnchained` | FR-009, FR-010 |
| 9 | Failure paths and traces | token and chat id absent from message and trace | `TelegramNotifierTest::testNoSecretInAnyFailure` | FR-011, R8 |
| 10 | Discord body | `allowed_mentions.parse` is `[]`, flags 4, `content` only | `tests/Unit/Notify/DiscordNotifierTest.php::testBodyShape` (T009) | US2-1, FR-006 |
| 11 | `**`, `@everyone`, `<@1>`, `[x](http://y)` in model text | inert | `DiscordTextTest::testModelTextIsInert` (T005) | US2-3 |
| 12 | Discord 429 body and header | wait from body, else header | `DiscordNotifierTest::testRateLimitBodyThenHeader` | US2-4 |
| 13 | Rate-limit method only retries 429 | 502 returns immediately; existing `send()` behaviour unchanged | `tests/Unit/Source/RetryTest.php::testRateLimitedRetriesOnly429`, `::testSharedBudgetAcrossParts` (T007) | R7 |
| 14 | Router | each driver reaches its notifier; unknown driver impossible | `tests/Unit/Notify/RoutingNotifierTest.php` (T010) | FR-003 |
| 15 | Config: each driver's keys, wrong-driver key, missing key, bad types | messages of contracts/config.md | `tests/Unit/Config/NotifyConfigTest.php` (T011) | FR-001, FR-002, FR-014 |
| 16 | Existing Slack configs | identical behaviour and messages | existing `WatchConfigTest`, `SlackWebhookNotifierTest` unchanged and green | FR-016, SC-005 |
| 17 | Factory: router, lock-ttl budget, secrets in heartbeat | `Factory::notifier` returns router; budget = `min(60, lock_ttl/10)` | `tests/Unit/Config/FactoryTest.php::testNotifierBudgetFromLockTtl` (T012) | R7 |
| 18 | Digest on Telegram, notice on Discord, digest delivery fails | digest-failure notice goes to Discord; same destination: not posted | `tests/EndToEnd/MultiChannelTest.php::testDigestFailureAnnouncedOnOtherChannel`, `::testSameDestinationNoAnnouncement` (T013, T016) | US3-1/2 |
| 19 | Fleet default Slack, service overrides with Telegram | valid; service entry has no `webhook` | `tests/Unit/Fleet/FleetConfigTest.php::testServiceDestinationReplacesFleetDefault` (T014) | US3-3, R10 |
| 20 | Fleet combined digest and correlated finding to Discord | posted to fleet destination | `MultiChannelTest::testFleetPostsUseFleetDestination` | US3 |
| 21 | `check` with malformed token / webhook | `digest bot_token invalid`; zero HTTP calls | `tests/Unit/Operate/DoctorTest.php::testTelegramShapeChecked` (T015) | US4-1, SC-006 |
| 22 | `--post-test`, digest = notice | one message, label `(digest, notice)`; different chats: two | `DoctorTest::testPostTestOncePerDistinctDestination` | US4-2 |
| 23 | Post-test failure output | no secret in text or JSON | `DoctorTest::testPostTestFailureHasNoSecret` | US4-3 |
| 24 | Privacy corpus for three drivers | 0 seeded values and 0 credentials in any payload, trace, log, heartbeat, check/status/run JSON | `tests/Unit/PrivacyCorpusTest.php`, `tests/EndToEnd/PrivacyTest.php` (T016) | SC-001, SC-002 |
| 25 | Architecture | notifiers POST only to message endpoints; hosts pinned | `tests/Unit/ArchitectureTest.php` (T017) | FR-017 |

## 3. Manual smoke (owner, once per channel; not part of the gates)

Add to docs/smoke-test.md: create a test bot and chat (or a Discord test webhook), set the destination from the
environment, run `bin/watcher check --config=... --post-test`, expect the labelled test message in the channel;
run once with `--now` against sample logs and compare the digest with the Slack one; search the channel history and the
process output for the token.
