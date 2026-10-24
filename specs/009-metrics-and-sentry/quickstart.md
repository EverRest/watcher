# Quickstart: validating Metrics, Sentry Issues and Trace Links

All scenarios run with fakes or recorded fixtures (no network). Gates: `composer test`, `composer stan`, `composer style`,
each exit 0 (read `$?`, not tail output).

| # | Scenario | Expected | Test file |
|---|---|---|---|
| 1 | Metrics declared, one log candidate, fake returns 0.004/0.001 and 820/310 | one `Metrics:` line; payload `m` holds the same numbers; no label in either | `tests/EndToEnd/MetricsTest.php` |
| 2 | Query with `{service}`, `{window}`, `{start}`; value with a quote | substitution from config/window only; a quote in a var fails config naming the key | `tests/Unit/Metrics/QueryTemplateTest.php`, `tests/Unit/Config/WatchConfigTest.php` |
| 3 | Backend returns labelled series | only numbers kept; with `label_allowlist: [route]`, <= 5 masked values | `tests/Unit/Metrics/PrometheusSourceTest.php` |
| 4 | Backend unreachable / 401 / timeout | `Metrics unavailable (<token>)`; log findings identical to a run without metrics | `tests/EndToEnd/MetricsTest.php` |
| 5 | Empty baseline | current value + `no baseline`, no percent | `tests/Unit/Metrics/MetricsReaderTest.php` |
| 6 | Nothing configured | no request, no line, no `m`, digest and token use byte-identical (SC-005) | `tests/EndToEnd/MetricsTest.php`, `tests/EndToEnd/DigestTest.php` |
| 7 | p95 x3, empty log window | exactly one `metric_anomaly` candidate, selected by code | `tests/Unit/Metrics/MetricAnomaliesTest.php`, `tests/EndToEnd/MetricsTest.php` |
| 8 | Same data plus an Error group | no anomaly, only the context line (SC-003) | same |
| 9 | Anomaly persists next day | not re-analysed unless count grew by `growth_factor` | `tests/EndToEnd/MetricsTest.php` |
| 10 | 5 anomalies, `max_anomalies` 3 | 3 largest kept, truncation stated | `tests/Unit/Metrics/MetricAnomaliesTest.php` |
| 11 | Sentry fixture with e-mail/id/token titles, user/tag/breadcrumb fields | groups carry masked title, counts, times only; no seeded value anywhere (SC-001) | `tests/Unit/Issues/*`, `tests/EndToEnd/SentryTest.php`, `tests/EndToEnd/PrivacyTest.php` |
| 12 | Sentry + log group on the same problem | two entries, not merged, Sentry entry has a link | `tests/EndToEnd/SentryTest.php` |
| 13 | Resolved / ignored issues in the fixture | not candidates | `tests/Unit/Issues/SentryIssueSourceTest.php` |
| 14 | Sentry 401 | `Sentry unavailable (unauthorized)`; log path unchanged | `tests/EndToEnd/SentryTest.php` |
| 15 | Trace-link goldens (Tempo, Datadog), id validation, earliest id | exact URLs; bad id dropped; one id per group | `tests/Unit/Context/TraceLinkBuilderTest.php`, `TraceIdCollectorTest.php` |
| 16 | Corpus with trace ids | id absent from every model input, state, JSON, notice; present only inside a link URL (SC-007) | `tests/EndToEnd/TraceLinksTest.php` |
| 17 | `watcher check` with fakes | two new checks pass/fail with a reason, no post, no tokens, < 10 s (SC-008) | `tests/Unit/Operate/DoctorTest.php` |
| 18 | `--json` | counts of metric queries, Sentry requests/issues, attributable tokens; keys absent when not configured | `tests/EndToEnd/RunSummaryTest.php` |
| 19 | Token-budget fixture | metrics <= 60 input tokens/service; Sentry group cost within 5% of a log group; default unchanged | `tests/Unit/Analyze/BudgetTest.php`, `tests/EndToEnd/CostTest.php` |

Manual check against a live system is **not** part of this plan; the UNVERIFIED shapes (Grafana proxy path, Sentry
parameters and `stats`, Explore and Datadog URLs) are listed in research.md and are verified in task T052 by the Operator.
