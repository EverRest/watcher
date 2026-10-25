# Data Model: Log Hygiene Reports

Everything here is an identifier, a category, a level, an integer, a time, or text already allowed to leave the service. There is no field for a value, an example line or a context entry; a unit test on each record's key set guards that.

## In memory (one pass)

**SiteStats** — per call site, kept while streaming; bounded to 5 000 sites, overflow counted.

| Field | Type | Note |
|---|---|---|
| id | string | masked identifier (see Call Site) |
| kind | `frame` \| `template` | how it was identified |
| lines | int | events at the site |
| byLevel | map level → int | |
| hits | map level → map category → int | lines with ≥ 1 pattern of the category |
| anyHit | int | lines with ≥ 1 category (for "share of lines affected") |
| long, trace, mismatch | int | shape counters |
| firstSeen, lastSeen | time | |

**ShapeTotals** — events, sum of lengths bucket counts, format class counts, long/trace/mismatch totals.

## Call Site (identifier)

`frame`: `app/Services/AuthService.php:118` after `Redactor` frame rewriting. `template`: `tpl:<fingerprint>` (and the masked template as `label` when the Redactor returned one). A site whose frame the Redactor drops uses the template form.

## Stored (state store, TTL = `state.retention`)

Keys (added to `Keys`, all `rawurlencode`d components):

| Key | Value |
|---|---|
| `hyg:day:<stateKey>:<YYYY-MM-DD>` | DayRecord |
| `hyg:sites:<stateKey>` | SiteRegistry |
| `hyg:declined:<stateKey>` | `{ <proposalId>: <ISO until> }` |
| `ledger:<stateKey>:<YYYY-MM-DD>` | LedgerDay (only when `hygiene.enabled`) |

**DayRecord** `v=1`

```text
date, events:int, sampled:bool, other_events:int,
sites:    [ { id, kind, label?, level, lines, any_hit, cats:{email,token,address,phone,card,host,other}:int,
              long, trace, mismatch, first, last } ]   ≤ 500, by lines desc
families: [ { fp, tpl:?string, level, n, first, last, tokens:int } ]   ≤ 300, by n desc
formats:  { access:int, json:int, text:int }
variable: [ { tpl, positions:int, distinct_messages:int } ]
```

`tokens` per family is copied from the ledger of that day when present (else 0).

**SiteRegistry** — `{ <siteKey>: { first, last, lastCount, reportedAt?, reportedCount? , seenLast:bool } }`, ≤ 2 000 entries. `siteKey` = first 16 hex of `sha1('f|' . file-without-line . '|' . shapeKey)` for a frame site, `sha1('t|' . fingerprint)` for a template site. `shapeKey` is the fingerprint's inputs without the frame (level, error type, token count, first two masked tokens), so a line that moves inside its file after a release keeps its key and is not reported as new. A site is one log call: the same line writing two different message shapes is two sites. It drives trend and FR-007.

**LedgerDay** — `{ calls:{triage,analyze,escalate}, in, out, cached, usd, families:{ <fp>:{ tpl:?string, tokens:int, runs:int } } }`, families ≤ 200 (the smallest is merged into `other` when full).

## Reports (outputs, schema 1)

Every report: `{ "schema": 1, "report": "<name>", "service": "...", "generated_at": "...", "window": {"start","end"}, ... }`. Field lists are in [contracts/report-schema.md](contracts/report-schema.md).

| Entity | Fields |
|---|---|
| Hygiene Finding | site, kind, category (or habit), level, count, share, first_seen, last_seen, trend |
| Variable Position | template, positions, distinct_messages |
| Rule Proposal | id, type (`mute`\|`watch`), fragment, evidence, replay (mute only), status (`open`) |
| Cost Day | date, runs, tokens_in/out/cached, cost_usd, calls per step (nullable), cap shares, flagged, causes |

## Validation rules

- Category names are the closed set `email, token, address, phone, card, host, other`.
- Levels are the lower-case PSR-3 names.
- `share` is `count / total`, rounded to 4 places; counts are ints.
- A record that fails to decode or has another `v` is ignored, never repaired (Principle VII: the report says history was unreadable).
- Nothing is stored for a site with no hit and no shape counter above zero, except `families` for proposals.
