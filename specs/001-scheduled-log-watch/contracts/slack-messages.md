# Contract: Slack messages

Same layout for every service (FR-032). Rendered by `DigestRenderer` into a neutral `Message`, then
to Block Kit by `SlackWebhookNotifier`. Text is plain (no user-supplied mrkdwn), and every string
comes from redacted data or the model's bounded fields.

## Digest (FR-029, FR-030)

```
🔎 orders-api · stg · 2026-10-22 06:00 → 2026-10-23 06:00 (Europe/Kyiv)

🔴 critical (1)
• QuotaTracking: deadlock on quota update — 412× (↑ from 3)  `a1b2c3d4e5f6`
  Evidence: SQLSTATE[40001] in QuotaTrackingService::reserve since 02:14.
  Hypothesis: concurrent reservations on the same pathway row.  Next: check lock order in reserve().

🟠 error (2)
• ...

➕ 7 minor (low confidence / noise) · 23 more groups, 1,840 events · 2 muted
⚠️ truncated: 5 groups dropped (budget)            ← only when FR-021 applied
— tokens in 18.2k / out 1.9k / cached 6.1k · triage haiku-x · analyze sonnet-x · ≈ $0.04
```

- Ordered critical → info; within a severity by count desc.
- Each finding: title, count, trend (`new`, `↑ from N`), fingerprint, one-line evidence, hypothesis
  (always prefixed "Hypothesis:"), next step.
- If the rendered message exceeds Slack limits, the lowest-severity findings move into the "minor"
  line; the digest is never split into several messages.

## Notices (FR-031, FR-035, FR-037)

```
✅ watcher · orders-api · stg — baseline recorded: 214 groups, 96,310 events. Analysis starts next run.
❌ watcher · orders-api · stg — source failed: loki 401 Unauthorized (check WATCHER_LOKI_TOKEN).
❌ watcher · orders-api · stg — analysis failed: output did not match schema twice.
⚠️ watcher · orders-api · stg — budget exhausted ($0.50/day): triage only today.
```

Notices go to `notify.notice`, falling back to `notify.digest`. A notice never contains secrets,
raw log lines or field values.
