# Contract: ticket draft

One `Message` per finding with one section of kind `draft` (preformatted). Layout is fixed; a line whose value is absent is
omitted (never blank, never a placeholder). Every value is already allowed in the digest. Width: lines are not wrapped;
total capped at `MAX_SECTION_CHARS`, cutting `Evidence`, then `Hypothesis`, with a visible `…` marker.

```
Title: [orders/int] error: Payment gateway timeouts after deploy
Update on: ABC-123                       (only for a known_grown finding; title becomes "Update on ABC-123 — ...")
Severity: error · kind: regression
Service: orders · environment: int · release: 1.4.2
Window: 2026-10-24T00:00Z — 2026-10-25T00:00Z
Counts: 120 events (previous window 8) · trend: grown · first seen 2026-10-24T03:12Z · last seen 2026-10-24T22:40Z

Evidence:
<finding.evidence>

Hypothesis (unconfirmed):
<finding.suspectedCause>

Suggested next step:
<finding.suggestedAction>

How to check:
<finding.howToCheck>

Related: <relatedChecks, up to 3>
Logs: <links.logs URL>
Code: <links.code URLs, up to 3>
Fingerprint: 3fa9c1d2e4b7
```

## Rules

- `Title` = `[service/environment] severity: finding title`, title as shown in the digest (already scrubbed).
- `Hypothesis` is always labelled `(unconfirmed)`; the draft never states a cause as fact.
- Links are the digest's own (built by code from config and redacted data); no link otherwise.
- Message-level markup: the section is rendered as a code block. Slack ``` fence; Telegram `<pre>` with HTML escaping;
  Discord fenced block. The three formatters neutralise the fence character sequence inside model text and apply the
  defang of spec 011 FR-007; `allowed_mentions` stays `[]`.
- Fields are exactly those the digest shows for a finding (title, count, trend, evidence, hypothesis, next step, check,
  related, 12-character fingerprint, links, release) plus enumerations (severity, kind), the window, and first/last seen
  of the group. Model `confidence` is not shown in the digest body and is not in the draft.
- Never in a draft: raw lines, templates, frames, field values, labels, secrets, destination values, ticket URLs.
- Order and cap: severity desc, count desc, fingerprint asc; `drafts.max_per_run`; skipped count goes to the digest as
  `N more drafts not sent`.
- Idempotency: marker `draft:<service>:<windowEnd>:<fp>` written after the draft is delivered.
