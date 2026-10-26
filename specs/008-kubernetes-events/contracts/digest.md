# Contract: digest and summary

Finding line for an event group (existing finding layout, one added fragment after the title/count line):
`🟠 OOMKilled · Deployment · pod event · 14× across 3 pods` - reason (error type), workload kind, marker, occurrences,
distinct pods as a number. Pod and workload names are not shown (acceptance 5).

Model payload, only for event groups: `"src":"k8s","pods":3,"wk":"Deployment"` appended to the group; other groups unchanged.

Notices (kind `notice`, after the truncation notices):
- `⚠️ Pod events were not read: unauthorised.` (reason class from the fixed vocabulary)
- `ℹ️ Pod events: possible gap 03:10-06:25 (no read within the cluster's event retention); 4 events matched no service.`
  built from times and integers; present only when a gap, a short ledger, or unattributed events exist.

`--json` adds `"events":{"status":"ok","read":42,"deduplicated":17,"unattributed":0,"truncated":false}` only when configured
(`not_configured` is omitted so existing summaries stay identical). Heartbeat run record gets `events` status.
