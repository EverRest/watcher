<!--
Sync Impact Report
- Version change: (none) → 1.0.0 (initial ratification)
- Principles added: I. Privacy Fails Closed; II. Read-Only by Design; III. Test-First;
  IV. Code Decides, the Model Judges; V. Token and Cost Discipline; VI. Configuration and
  Contracts over Hard-Coding; VII. Failures Are Loud; VIII. Simplicity
- Sections added: Technical Constraints; Development Workflow; Governance
- Sections removed: none
- Templates: none modified (they read this file at runtime)
- Deferred TODOs: none
-->

# watcher Constitution

## Core Principles

### I. Privacy Fails Closed (NON-NEGOTIABLE)

- Only allowlisted fields MAY leave the host service: level, normalised message template, error
  type, at most 5 stack frames, counters and timestamps. Raw log context, request bodies and raw
  sample lines MUST never be sent to the AI provider or to any notifier.
- Redaction is deterministic code with two layers (allowlist, then pattern scan). The AI MUST NOT
  be used to redact.
- Any redaction error, unknown field or unparseable value MUST be dropped, never passed through.
- Raw log lines MUST NOT be persisted anywhere. Every persisted item MUST have an expiry
  (default 30 days).
- A change that touches what leaves the service MUST extend the privacy test corpus in the same
  change.

Rationale: the first consumer holds personal data under GDPR; one leak outweighs every finding
the package will ever produce.

### II. Read-Only by Design

- The package MUST NOT perform any write, delete, restart or administrative action on any system
  other than posting a message to its configured notifier and writing its own state.
- Credentials for log sources MUST be read-only; documentation MUST say so.
- Automatic remediation and ticket creation are out of scope until a ratified amendment adds them.

Rationale: a watcher that can act is an incident generator; trust comes from being unable to
cause harm.

### III. Test-First (NON-NEGOTIABLE)

- Every behaviour is written red → green → refactor; no business rule ships without a failing
  test written first.
- Tests MUST NOT touch the network: sources, AI provider and notifiers are faked or driven by
  recorded HTTP fixtures.
- Mandatory suites: privacy corpus (no seeded personal value reaches any outbound payload),
  selection table tests, model-routing table tests, token-budget fixtures, a shared contract suite
  run against every state store and every log source, and one end-to-end run with fakes.
- A bug fix starts with a test that reproduces it.

Rationale: the package runs unattended on a schedule; tests are the only reviewer present at 06:00.

### IV. Code Decides, the Model Judges

- What reaches a model is decided by deterministic code (new, grown, level, watch rules, top-N,
  mute list, first-run baseline). The model MUST NOT select its own input.
- Model output MUST follow a fixed schema and be validated in code; invalid output is retried once,
  then reported as a failure — never silently accepted or repaired by guessing.
- Prompts MUST forbid stating facts absent from the input; causes are always shown as hypotheses.
- Given the same input and configuration, everything except the model's wording MUST be
  reproducible.

Rationale: reproducible selection makes the digest debuggable and keeps cost proportional to
change, not to log volume.

### V. Token and Cost Discipline

- The cheapest correct path wins: no candidates → zero AI requests; first run → baseline only;
  unchanged groups reuse cached verdicts.
- Work is routed by tier (triage → analysis → escalation) with bounded escalation; a stronger model
  is used only where a rule in the spec sends it.
- Inputs are compact (templates sent once and referenced, no redundant formatting); outputs are
  bounded per field and per step; stable prompt prefixes are structured for provider caching.
- Every run enforces its token budgets and the daily spend cap before calling, and reports tokens
  and approximate cost in the digest.
- A change that increases tokens per run MUST state the expected increase and be covered by the
  token-budget fixtures.

Rationale: the job runs in every service, every environment, every day; small waste multiplies.

### VI. Configuration and Contracts over Hard-Coding

- No model identifier, log-backend detail, channel, threshold or schedule MAY be hard-coded in the
  core; all come from configuration with documented defaults.
- Log sources, state stores, notifiers and the analyzer are reached only through contracts; the
  core MUST NOT depend on any framework. Framework integration lives in adapters.
- Configuration is validated once at start; invalid configuration fails before any external call
  with a message naming the setting.
- Nothing consumer-specific (orders-api or any other service) MAY appear in the package.

Rationale: the owner's stated goal is one package for any service and any environment, switched
by configuration alone.

### VII. Failures Are Loud

- Every failure (source, analysis, budget, delivery, configuration) MUST produce an explicit
  notice or a non-successful exit; "nothing found" and "did not work" MUST never look the same.
- Catch-and-continue is allowed only where the spec defines the fallback, and the fallback MUST be
  visible in the digest or notice.

Rationale: a silent watcher is worse than none, because people stop reading the logs it replaced.

### VIII. Simplicity

- Smallest diff that satisfies the spec; no speculative features, options or layers.
- A contract exists only where the spec names more than one implementation or an extension point;
  otherwise write the concrete class.
- Prefer the standard library and already-chosen dependencies; a new dependency needs a written
  reason in the plan.

Rationale: flexibility comes from the few contracts the spec requires, not from abstraction
everywhere.

## Technical Constraints

- PHP ^8.2; core is framework-agnostic; the first adapter targets Lumen/Laravel.
- Package `everrest/watcher`, namespace `Watcher\`, semver releases with a changelog entry per
  release; breaking configuration changes require a MAJOR bump and an upgrade note.
- Secrets (AI key, log-source tokens, webhooks) come only from the host's environment or
  configuration and MUST never be logged, echoed in notices or included in AI input.
- State stores (key-value cache, local file, relational database) MUST pass the same contract
  suite; the local-file store is documented as single-runner only.
- Locks and stored items always carry an expiry.

## Development Workflow

- Spec-driven: specify → clarify → plan → tasks → implement. A change that contradicts the spec is
  either a spec amendment first or a bug.
- When more than one reasonable design exists or a requirement is ambiguous, the options are put
  to the owner before building.
- Quality gates before hand-off: full test suite, static analysis and code-style checks pass
  (tools fixed in the plan); exit codes are read, not tail output.
- Commits, pushes, tags and releases happen only when the owner explicitly asks; hooks are never
  skipped; commit messages carry no AI co-author trailer.

## Governance

- This constitution supersedes other practices in this repository. Plans MUST include a
  Constitution Check against every principle; a violation is either removed or recorded with a
  justification in the plan's complexity tracking.
- Amendments are proposed as a diff to this file with rationale, approved by the owner, and
  versioned: MAJOR for removing or redefining a principle, MINOR for a new principle or materially
  expanded rule, PATCH for wording.
- Reviews verify compliance with Principles I–VIII; Principles I and III are non-negotiable and
  cannot be waived in a plan.
- Runtime guidance for agents lives in `CLAUDE.md` and `docs/brief.md`.

**Version**: 1.0.0 | **Ratified**: 2026-10-23 | **Last Amended**: 2026-10-23
