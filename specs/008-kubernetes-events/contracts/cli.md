# Contract: `watcher check`

New result `events`, shown after `source`, only when the events source is configured:
`events: pass` | `events: fail unauthorised` | `events: fail namespace shop: forbidden (list events)` | `events: warn exporter and
native events both configured` | `events: skip (disabled)`. Reasons come from the fixed vocabulary; no URL, token or body. Reads
are real (two GETs per namespace, `limit=1`); nothing is written; `--dry-run` of a run reads the cluster and writes no ledger key.
