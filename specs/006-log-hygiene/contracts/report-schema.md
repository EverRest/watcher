# Contract: report schema (version 1)

All names are stable; a new field is added, never renamed, within schema 1. `--json` prints one envelope; each report's body (below) sits under `reports.<name>`:

```json
{ "schema": 1, "service": "orders-api", "environment": null,
  "generated_at": "2026-10-24T06:00:00+00:00", "window": {"start": "…", "end": "…"},
  "events_read": 123456, "sampled": false, "failure": null, "notes": [],
  "reports": { "privacy": {…}, "shape": {…}, "proposals": {…}, "cost": {…} } }
```

`sampled` is true when the source stopped at `hygiene.event_cap`; the text then says so.

## privacy

```json
{ "sites": [ { "site": "app/Auth/AuthService.php:118", "kind": "frame|template", "label": null,
               "level": "error", "category": "email", "count": 412, "lines": 1900, "share_of_lines": 0.2168,
               "first_seen": "…", "last_seen": "…", "trend": "new|growing|stable|gone" } ],
  "variable_text": [ { "template": "Payment declined for <*> <*>", "positions": 2, "distinct_messages": 87, "trend": "new" } ],
  "gone": [ "app/Old/Site.php:9" ],
  "other_events": 0, "unscannable": 0 }
```

One row per site × category × level. `label` is the allowed masked template when `kind` is `frame` and the template is known, else null. Clean sites are absent. `variable_text.template` is a template that is already allowed to leave the service (withheld ones are not listed).

## shape

```json
{ "habits": [ { "habit": "noisy_site|long_lines|trace_in_message|mixed_format|level_mismatch",
                "site": "…|null", "level": "info|null", "count": 51000, "share": 0.31, "measure": {"limit": 2000, "max_length": 9120},
                "proposal": "mute …|lower the level at the source …" } ],
  "formats": { "access": 120, "json": 40, "text": 9000 } }
```

`proposal` is fixed text chosen by code per habit, naming the site id or fingerprint only; never applied.

## proposals

```json
{ "history_days": 16, "days_needed": 0,
  "proposals": [ { "id": "a1b2c3d4e5", "type": "mute|watch", "status": "open",
                   "fragment": "['fingerprint' => 'a1b2c3d4e5f6', 'until' => null],",
                   "evidence": { "days_seen": 16, "events_per_day": 8100, "tokens": 0 },
                   "replay": { "days": 16, "candidates_removed": 0, "tokens_saved": 0 },
                   "expected_saving": { "tokens_per_day": 0.0, "events_per_day": 8100 } } ] }
```

With fewer than `hygiene.min_history_days` days: `proposals` is empty and `days_needed` says how many more. `replay` exists for `mute`; a `watch` proposal carries `evidence: {days_with_precursor, days_with_severe, lead_seconds}`.

## cost

```json
{ "days": [ { "date": "2026-10-23", "runs": 1, "tokens_in": 1200, "tokens_out": 300, "tokens_cached": 0,
              "tokens": 1500, "cost_usd": 0.0421, "calls": {"triage": 1, "analyze": 1, "escalate": 0},
              "cap_share": {"usd": 0.084, "tokens": null}, "flagged": false, "causes": [], "unbooked": 0 } ],
  "total": { "runs": 7, "tokens": 10500, "cost_usd": 0.29 },
  "top_spenders": [ { "fingerprint": "a1b2c3d4e5f6", "template": "…|null", "tokens": 900, "runs": 4 } ],
  "median_cost_usd": 0.04 }
```

`calls` and `top_spenders` are `null` / empty with `"ledger": false` when `hygiene.enabled` was off. `causes` ⊂ `new_families`, `grown_families`, `escalations`, each with a count. `unbooked` (per day, and summed in `total`) is the number of billed calls whose ledger write failed: when it is above 0 the per-step numbers and top spenders of that day are understated.

## Never present

A matched value, an example line, a field value or key from a log event, a raw message, a stack frame beyond the site id, a secret, a webhook or chat id.
