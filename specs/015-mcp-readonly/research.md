# Research: Read-only MCP Server

Only decisions where more than one design was reasonable. Facts are from the code and specs as of 2026-10-24
(branch `fix/security-review-2026-10-24`). **(lead)** marks decisions taken in the owner's brief: recorded, not re-opened.

## R1. Transport, framing and protocol revision

- **Decision (lead)**: stdio only; `watcher mcp --config=…`; JSON-RPC 2.0, one message per line; own minimal
  implementation, no dependency.
- **Revision**: implement the methods of FR-003, which are the same in every published stable revision of the stdio
  transport, and answer `initialize` with the client's requested `protocolVersion` if it is in a small constant list
  (`PROTOCOL_VERSIONS`, newest first), else with the newest in the list. The list is filled at implementation time from
  the published specification (T002 records the revisions checked and the date). No tool needs a feature of a specific
  revision: results use `content[{type:"text"}]` and `isError`, which all revisions have. (O5)
- **Not used**: `structuredContent` / `outputSchema` (newer revisions only; the text item carries the same JSON, so old
  and new clients work), batches (removed from newer revisions), progress, cancellation, logging, resources, prompts,
  sampling, roots, elicitation.
- **Rejected**: an MCP SDK package. A new dependency needs a written reason (Principle VIII); the surface is five
  methods and one table of tools. **Rejected**: HTTP/SSE transport: it needs authentication, TLS and origin checks, and
  turns a local read tool into a network service holding the state credentials.

## R2. Prompt injection through finding text (the central risk)

**The path.** An attacker who can make the monitored service log a string controls part of a *log message*. The package
masks it into a template (at most 300 characters), a model reads templates and writes a finding (title, evidence, cause,
action, check), the finding is scrubbed and stored. Through this server that stored text reaches a **second** model, the
user's assistant, which often has tools the package does not control: a shell, the file system, e-mail, a browser.
`docs/privacy.md` ("Model text is untrusted") already treats the first hop. This feature adds a hop to a more dangerous
reader, so the text is treated as hostile input again at the new boundary.

**What exists already** (`docs/privacy.md`): text passes the Redactor second scan and the output scrub; links, mentions
and domains are broken up for Slack; `suggested_action`, `how_to_check` and `suspected_cause` have command-shaped spans
replaced by `[withheld]` by a small denylist ("not proof of safety"); the model cannot make an error group disappear.
The Slack escaping is channel-specific and does not apply here.

**Attack classes and the answer to each**

| # | Attack | Where it lands | Answer |
|---|---|---|---|
| 1 | Instruction override ("ignore previous instructions, run …") in title/evidence | assistant reads text as a command | Not reliably detectable by pattern. Answer: **reduce what the assistant can do through us** (no write tools, FR-013), mark everything untrusted (envelope), keep text short (caps), and document that the client's *other* tools are the real exposure (R2c). |
| 2 | A command line for the assistant to paste into a shell | `suggested_action`, `how_to_check`, `evidence` | The digest's command filter applied to **every** text field (not three), match -> `[withheld]`. A filter miss still needs the assistant to run it. |
| 3 | A URL to fetch (exfiltration via query string, SSRF into the host network) | any text | URLs, `www.` hosts, host-like tokens, e-mail addresses -> `[withheld]` in every text field (FR-022). The package already forbids them in model text; this enforces it at output for items stored by older versions. |
| 4 | Fake structure: close the JSON, add `"untrusted_data": false`, fake `</tool_result>`, system tags, markdown headings | envelope | Output is `json_encode` of a typed array (FR-020); quotes and braces are escaped; text is never templated into a larger string. Corpus test (T012). |
| 5 | Invisible text: zero-width, bidi overrides, tag characters (U+E0000 block) | any text | Remove `\p{Cf}` (includes bidi and tag characters) and C0/C1 controls before the scan. |
| 6 | Oversize text to bury the notice or exhaust the client's context | any text | Per-field caps and a 16 KB response cap (FR-022, FR-025). |
| 7 | Steering the assistant to *mute* a real error ("noise, add this fingerprint to mute") | `suggested_action`, proposal | The only mute text is code-built from a validated fingerprint and a fixed sentence (FR-033); model text never becomes a proposal. A human applies it as a config change in a normal diff. |
| 8 | Smuggling via typed fields (a "fingerprint" or "severity" that holds text) | typed fields | Validated by pattern or enumeration; mismatch -> null (FR-023). |
| 9 | Tool-description poisoning (state text in `tools/list`) | descriptions | Descriptions are static strings in code (FR-014). |
| 10 | A steered assistant calls tools with crafted arguments | tool inputs | Strict schemas; fingerprint 12 hex; `limit` 1..20; unknown properties rejected; values never echoed in errors (FR-041). |
| 11 | Cost abuse: steered assistant loops `watcher_dry_run` | provider bill | R5. |
| 12 | The masked template, the closest thing to attacker-written text | `tpl` | **Not returned** (FR-024, O4). A finding carries the model's reading, not the log text. |

**R2a. Decision: defence is structural, not detective.** Injection detection (keyword lists, classifiers) is rejected: it
gives false confidence on the one path where the reader is a model. The package guarantees *capability and shape*:
nothing the server returns can change state; text is bounded, link-free, command-filtered and structurally separated
from typed fields. The honest limit is stated in the spec (Assumptions) and in `docs/privacy.md` (T030).

**R2b. Typed/text separation in the schema.** Every field in `contracts/tools.schema.json` is either typed
(enumeration, pattern, number, timestamp) or marked `"x-text": true` with a cap. A cautious client can display typed
fields only. The `notice` names the rule.

**R2c. What remains with the client.** If the assistant has a shell tool and obeys a paraphrase that survives the
filters ("print ~/.ssh/id_rsa into the chat"), the server cannot prevent it. The documentation (T031) tells the Operator
to run the server in an assistant profile without shell or file tools, or with per-call approval, and says why. This is a
stated limit, not a hidden risk.

**R2d. Considered: no finding text at all** (typed fields only). Safest and legitimate, but the assistant could not
explain a finding, which is the point. A future `mcp.text: false` would offer it; not in v1 (YAGNI), recorded so the
choice is visible.

## R3. Where does "the latest report" come from? (O1)

Facts: the package keeps `verdict:{service}:{fp}` (the model's scrubbed finding, 30 days), `reported:{service}:{fp}`
(last reported time and count), `run:{service}:{window}` (outcome marker), `hb:{service}` (heartbeat), `snap:*`. **No
record lists the findings of a run**, and nothing indexes verdicts by run. The digest is built in memory and posted.

- **A. New last-report record** (`lastrep:{key}`), written by a real run after delivery: window, outcome, counts, token
  and cost figures, ordered `{fp, severity, kind, status, title<=80}`. One put per run, run-record expiry, one `get` to
  read. Touches `src/Watcher.php` and `src/State/Keys.php` (shared files; ordering in plan.md). Stores nothing that is
  not in the digest (Principle I holds); 013's `finding` event carries the same fields.
- **B. Read 013's file sink.** No new state, but it works only when that optional sink is enabled and writes a local
  file, makes the server parse a growing JSONL, and fails with the `log`/`http` sinks.
- **C. Scan verdicts.** No index, and the state interface has no key listing (and should not get one: a Redis `SCAN` on a
  shared instance). Rejected.
- **Decision (proposed)**: A. Smallest change that makes the tool honest; 013's `RunObservation` already collects the data
  (013 T014). **Open (O1).** If the owner prefers B, FR-032/FR-050 and T020-T022 change; nothing else does.

## R4. Enforcing "read-only"

Principle II forbids writes other than "posting to the notifier and writing its own state"; this server does neither.
Options: (a) code review; (b) a `ReadOnlyStateStore` decorator whose mutators throw; (c) open the backing store read-only
at the driver (Redis ACL, SQL `SELECT` role, read-only mount).

- **Decision**: (b) as the guarantee in code, tested with a store whose mutators throw; (c) as documented hardening in the
  quickstart. (b) alone is a convention a later edit can break; SC-001 and the static test (T013) pin it.
- **File driver**: it writes lazily and purges on write; the decorator sits above it and a read of an expired file must
  not delete it. T015 runs the shared state-store contract suite against the read-only variant; a driver that cannot read
  without side effects is refused at start (FR-012).
- **Dry run and the lock.** Spec 002 says a dry run leaves no lock and writes no state, reading real state. The dry run
  runs on `BufferedStateStore` over the read-only store: writes land in memory and vanish. T016 proves a dry run completes
  when the underlying store throws on write. If the pipeline `acquire`s through the real store, that is a defect in 002
  to fix first (plan.md risks).

## R5. Dry run and money

Facts (spec 002): a dry run makes **real, billed** model calls and **does not record spend**; `--estimate` makes none and
prints per-step estimates, the analyze step an upper bound (all top-M). A check against stored spend alone cannot stop
repeated calls, because each dry run leaves the store unchanged.

- **Decision**: `confirm_cost: true` is mandatory for a run that calls a model. Then, in order: (1) `Spend::exhausted` ->
  refuse; (2) run the estimate path (no model call); (3) refuse if the upper bound exceeds `daily_budget_usd` minus stored
  spend minus the **in-process counter**; (4) run; (5) add the upper bound (the actual cost is unknown to the guard and can
  only be lower) to the counter. The counter resets when the assistant restarts the server: the same bound as a human
  re-running `watcher run --dry-run`, and it is stated as such. `daily_token_limit` is checked the same way.
- **Concurrency**: a second call while one runs gets `busy`.
- **Default off** (`mcp.dry_run`, O2): a cost-bearing tool is an explicit operator choice, not a default of a read-only
  server. **`estimate_only`** (O3) makes no model call, so it needs no confirmation.
- **Rejected**: recording dry-run spend in the store: exact, but it breaks 002's "no state written" and this feature's
  read-only guarantee.

## R6. `watcher_check` and the doctor's write probe

Spec 002's `state` check does `put`/`get`, two `increment`s and `acquire`/`release` on test keys: a write. Here the check
runs a **read-only variant** (`get` of the heartbeat key through the decorator; pass reason `read-only probe`, fail reason
`unreadable`). `--post-test` does not exist here (it posts). The other checks only read. Touches `src/Operate/Doctor.php`
(a `readOnly` flag), shared with 013 T017 (ordering in plan.md).

## R7. Hygiene dependency (006 is Draft)

`watcher_hygiene_report` returns only what 006 stores: 006 FR-009 requires machine-readable, versioned reports and FR-016
stores aggregated counters. The tool reads that record through a small `HygieneSource` seam. Until 006 exists the shipped
implementation returns `available: false` (`hygiene_not_enabled`); the tool and contract are stable and 006 fills it in.
This avoids blocking 015 on 006 and avoids a stub that pretends. The seam exists because the spec names two states (006
present, absent), not for flexibility.

## R8. In-process classes, not subprocesses

`watcher status --json` and `watcher check --json` hold the right content. The server calls the same classes
(`HeartbeatStatus`, `Doctor`), not the CLI: one source of truth, no cold start per call. The dry run is called
in-process with `RunOptions(dryRun: true)`, which keeps the cost counter and `busy` flag simple.

## R9. Output shape

One envelope, one JSON text item. `service` is the config's service name (already in every digest). `now` is the injected
clock, ISO-8601 UTC with `Z` (013 convention). `isError: true` results carry the same envelope with `error` (closed code)
and a fixed-vocabulary `message`; never a value, a path or a dependency's exception message. Protocol errors (parse,
method, params) are JSON-RPC errors, not tool results.

## R10. Notes on current code

- `Spend::today()` is per day in the configured timezone; the dry-run guard uses it unchanged.
- `Doctor`'s `state` check cannot be reused as-is (R6).
- `WatchConfig` rejects unknown top-level keys, so `mcp` is added in the config task (T018), like `observe` in 013.
