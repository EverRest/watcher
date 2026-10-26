# Quickstart: Kubernetes Events Source

Each scenario is a test: Guzzle `MockHandler` + fixtures in `tests/Fixtures/kubernetes/`, `FrozenClock`, `InMemoryStateStore`.
`composer test`, `composer stan`, `composer style` must each exit 0 (read `$?`).

| # | Scenario | Expected | Test |
|---|---|---|---|
| **US1** | | | |
| 1 | Fixture: 8 pods CrashLoop (`BackOff`), 2 OOMKilled, 1 pull failure, 1 HPA metric error | 4 groups, pods 8/2/1/hpa, no pod/node/image text in any payload | `KubernetesEventsTest::testPodEventsArriveBesideLogGroupsAndCollapse` |
| 2 | `Warning` + critical reason | Error; `Warning` other -> Warning; `Normal` -> Info and dropped by `read_min_level` | `ReasonLevelsTest` |
| 3 | 24 hourly reads of a growing event, then the due run | group count = true count (1 %), re-read of unchanged adds 0 | `EventLedgerTest::testHourlyReadsSumOnce`, `KubernetesEventsTest::testTwentyFourHourlyTriggersFeedTheDailyRunWithoutAGapNote` |
| 4 | Normal-only window | quiet run, 0 model calls | `KubernetesEventsTest::testANormalOnlyWindowIsQuietAndCostsNoTokens` |
| 5 | Digest of an event group | reason, count, pod count, workload kind, "pod event"; no names | `DigestRendererTest::testPodEventFragment` |
| 6 | N = 1, 8, 200 pods | exactly one fingerprint per reason | `EventMaskerTest::testTwoHundredGeneratedPodsCollapseToOneText` |
| **US2** | | | |
| 7 | Service with `events`: both sources merged, tagged | one digest, one budget | `KubernetesEventsTest::testPodEventsArriveBesideLogGroupsAndCollapse` |
| 8 | Service without `events` | zero cluster requests, output and payload byte-identical | `KubernetesEventsTest::testWithoutEventsNothingIsAskedOfAnyCluster` |
| 9 | Events 401/403/404/429/timeout/invalid JSON | log part identical + one notice per class, exit per partial-failure rule | `KubernetesEventsTest::testAnEventsFailureLeavesTheLogPartAndAddsOneNotice`, `testAnEventsFailureOnAQuietDayIsNeverSilent` |
| 10 | Two entries, two selectors, one namespace | each event to the matching entry; non-matching counted | `EventMapperTest::testSelectorSelectsPodsAndTheirWorkloadsOnly` |
| 11 | Two entries, same credentials | one events list and one pods list per namespace | `FactoryKubernetesTest::testEntriesWithTheSameCredentialsShareOneClientAndOthersDoNot`, `KubeApiTest::testListsAreMemoisedForAMinuteOnTheInjectedClock` |
| **US3** | | | |
| 12 | Full matrix with a recording handler | only GET on events/pods; no watch/write/exec | `KubeApiTest`, `KubernetesReadOnlyTest` |
| 13 | `check`: ok / forbidden / unreachable / exporter+native | pass, named fail, warn | `DoctorEventsTest` |
| 14 | token and CA | only from config/env; never in a notice, log, state or summary | `KubernetesEventsTest::testNothingSeededReachesAnyPayloadMessageOrState`, `testTheJsonSummaryAndHostLogCarryOnlyNumbersAndFixedWords` |
| **US4** | | | |
| 15 | 3 namespaces, mixed labels | only matching; selector in code even if the API filter is coarse | `LabelSelectorTest`, `EventMapperTest` |
| 16 | `involved_kinds`; Node events | dropped unless listed | `EventMapperTest` |
| 17 | Cap exceeded | `lastReadTruncated()` true, digest says so | `tests/Contract/KubernetesEventsSourceTest` |
| 18 | Empty `namespaces` | config error naming the key, before any call | `KubernetesConfigTest::testAnEmptyNamespaceListIsRejectedThereIsNoAllNamespacesDefault` |
| **Cross** | | | |
| 19 | No trigger for longer than `event_ttl` | coverage note, never silent (SC-008) | `EventLedgerTest::testGapIsRecorded`, `KubernetesEventsTest::testAMissedTriggerIsStatedNeverSilent` |
| 20 | Privacy corpus: private registry images, node names/IPs, hashed pod names, secret/configmap names, probe URLs with tokens, annotations with emails, free text | no seeded value in any model payload, Slack message, state write, summary or log | `KubernetesEventsTest::testNothingSeededReachesAnyPayloadMessageOrState`, `testTheJsonSummaryAndHostLogCarryOnlyNumbersAndFixedWords` |
| 21 | `--dry-run` | cluster read, no ledger write | `KubernetesEventsTest::testADryRunReadsTheClusterAndWritesNoLedger` |
