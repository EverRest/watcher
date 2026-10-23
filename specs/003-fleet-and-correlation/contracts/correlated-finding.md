# Contract: correlated finding

Produced by `Correlator` from already-redacted data; rendered by `CorrelationRenderer`; posted to the fleet `notify.digest`
(or printed under `--dry-run`). One finding per **origin group**.

## Structure (`CorrelatedFinding::toArray()`, also the unit-test shape)

```json
{
  "fleet": "platform",
  "hypothesis": true,
  "origin": {"service": "api", "fingerprint": "ab12...", "level": "error", "status": "grown",
             "count": 412, "previous_count": 9, "template": "SQLSTATE[HY000]: connection refused to <HOST>",
             "first_seen": "2026-10-23T05:41:00Z", "last_seen": "2026-10-24T05:58:00Z"},
  "consumers": [
    {"service": "bff-a", "groups": 2, "count": 130, "dependency": "api"},
    {"service": "bff-b", "groups": 1, "count": 41,  "dependency": "api"}
  ],
  "window": {"start": "2026-10-23T05:41:00Z", "end": "2026-10-24T05:58:00Z"},
  "basis": ["host_reference", "window_overlap", "origin_grown"]
}
```

Allowed content: service names, fingerprints, level, status, counts, times, the **origin's** redacted template
(already allowed to leave the host in its own digest). Not allowed: consumer templates, raw lines, field values, stack
frames, URLs, secrets. Privacy corpus asserts none of the seeded values appears (T016).

## Rendered message (`Message`)

```text
🔗 *Probable upstream cause: api* (hypothesis) · platform · 23 Oct 05:41 - 24 Oct 05:58
`SQLSTATE[HY000]: connection refused to <HOST>`  ·  error · grown 9 -> 412 in `api` (ab12cd34ef56)
Affected consumers (they reference `api` in the same window): bff-a 130 events (2 groups), bff-b 41 events (1 group)
Each consumer's own digest still contains its finding, annotated with this origin.
```

Sections (for `Message::$sections`): `header`, `finding`, `footer`. Capped at the same character limit as
`DigestRenderer`; consumers beyond what fits collapse into `+N more services`.

## Consumer annotation

When a consumer group is linked, the consumer's own finding (same digest, same position) gets one extra line:
`🔗 likely caused by the api finding ab12cd34ef56 (hypothesis)`. Service name and the first 12 characters of the origin
fingerprint only. The correlated message is posted in addition; it never replaces a consumer finding.

## Correlation rules (what the tests pin)

1. Link only when consumer declares dependency `d`, an origin service provides `d`, consumer template has `<URL:d>` or
   `<HOST:d>`, origin group is `new`/`grown` at or above its `selection.min_level`, and the group time ranges overlap
   (plus `correlation.tolerance`).
2. No consumer reference -> no finding (origin errors alone stay an ordinary finding).
3. Origin service not in the fleet, or not run in this trigger -> no link (existing dependency context applies).
4. Several consumers of one origin group -> one finding listing all.
5. Cycle -> origin is the group with the earlier `first_seen`; equal -> the service that sorts first.
6. Disabled, or an entry in `--only` excluded -> no link.
7. Zero model calls; the text is a template.
