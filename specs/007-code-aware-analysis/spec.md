# Feature Specification: Code-Aware Analysis

**Feature Branch**: `007-code-aware-analysis`

**Created**: 2026-10-24

**Status**: BLOCKED — requires owner approval of the constitution amendment below (Principle I). No plan, tasks or code may start before the amendment is ratified.

**Input**: User description: "Code-aware analysis for everrest/watcher: the model reads code fragments for the top stack frames at the deployed commit, so it can give a real root-cause hypothesis instead of guessing from a template and five frame names. Read-only repository token; only the functions referenced by frames of the groups selected for analysis; fragment size caps; secrets redacted from code; its own token budget counted in the daily cap and the budget reservation; repository access through a new optional interface with GitLab and GitHub read-only implementations; deployed commit from DeployInfo; off by default. Source: docs/audit-2026-10-23.md feature 9, docs/roadmap.md item 007 (approved by the owner), docs/review-2026-10-23.md W9."

## Overview

Today the model sees a masked message template, an error type and at most five frames (`path:line`). It can name the
suspect file but cannot say *why* the line fails, so its hypothesis is generic and the Reader still opens the repository.
The package already knows the deployed commit (`DeployInfo`) and already turns frames into permalinks
(`CodeLinkBuilder`). This feature closes the gap: for the few groups selected for analysis it fetches **only the function
body around each top frame** at the deployed commit, redacts it, and gives it to the model as extra evidence, so the
hypothesis can point at the actual statement.

This is the first feature that sends **source code** out of the host environment. Principle I of the constitution allows
only a fixed list of fields to leave. The feature therefore cannot ship until the constitution is amended. Section
"Constitution amendment required" gives the exact wording; the feature is **BLOCKED** until the owner approves it.

**Actors**

- **Reader** — reads the digest; wants a hypothesis naming the failing statement.
- **Operator** — enables the feature per service, supplies a read-only repository token, sets the caps.
- **Data protection reviewer** — must be able to state exactly what code classes leave the service and prove that secrets
  and personal data in code do not.
- **Repository owner** — grants the read-only token; must be confident nothing outside the referenced functions is read.

## Constitution amendment required

**Blocking.** Principle I (`.specify/memory/constitution.md`, v1.0.0) says: "Only allowlisted fields MAY leave the host
service: level, normalised message template, error type, at most 5 stack frames, counters and timestamps." Code fragments
are not on that list, so sending them contradicts a NON-NEGOTIABLE principle. Per Governance an amendment is a diff to the
constitution approved by the owner, and it is a MINOR bump (materially expanded rule) to **1.1.0**.

**Proposed amended wording of Principle I, first bullet and one new bullet** (all other bullets unchanged):

> - Only allowlisted fields MAY leave the host service: level, normalised message template, error type, at most 5 stack
>   frames, counters and timestamps, and — only for a service whose configuration explicitly enables it — **code
>   fragments** (a new allowlisted field class, defined below). Raw log context, request bodies and raw sample lines MUST
>   never be sent to the AI provider or to any notifier. Code fragments MUST never be sent to any notifier.
> - **Code fragment class.** A code fragment is the body of one function or method referenced by a stack frame of a group
>   selected for analysis, read at the deployed commit through a read-only repository credential, capped in lines and
>   bytes, and passed through the code redaction pipeline (the Normalizer secret and personal-data rules, string-literal
>   and comment stripping per configuration, and a final pattern scan) before it leaves. Whole files, repository
>   listings, search results, other branches and files not named by a frame MUST NOT be read. A fragment that fails
>   redaction, exceeds a cap, or cannot be attributed to a frame MUST be dropped, never passed through. Fragments are
>   opt-in **per service**, off by default, and MUST NOT be persisted (they are held in memory for the run only).

**Also amended (wording only)**: Principle I's last bullet gains "…and, for code fragments, the code privacy corpus"; and
Principle II's "Credentials for log sources MUST be read-only" gains "and repository credentials". The roadmap row for 007
and `docs/privacy.md` ("What leaves the service") MUST be updated in the same change that ratifies the amendment.

**What the owner decides**: approve as written, approve with changes, or reject (in which case this spec is closed and
roadmap item 007 is withdrawn). Until approved, the status above stays BLOCKED and the checklist item "Constitution
amendment approved" stays open.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A hypothesis that names the failing statement (Priority: P1)

For a group selected for analysis whose top frames point into the service's own code, the Reader sees a hypothesis that
refers to the actual code ("`$user->profile` is dereferenced without a null check after `find()` can return null") and the
digest marks that the hypothesis was made with code.

**Why this priority**: it is the whole value of the feature; everything else constrains how it may be done.

**Independent Test**: with a fake repository returning a function body for frame `app/Services/AuthService.php:118` and a
fake analyzer that records its input, run a group whose top frame is that line; the analyzer input contains the redacted
function body and the digest shows the "code-aware" marker.

**Acceptance Scenarios**:

1. **Given** the feature is enabled for a service and a deployed commit is known, **When** a group is selected for
   analysis, **Then** the package fetches, for at most the configured number of top frames of that group (default 2), the
   function containing the frame line at that commit, and adds it to that group's analysis input.
2. **Given** a frame in `vendor/`, outside the configured repository path map, or not resolvable to a repository path,
   **When** fragments are collected, **Then** it is skipped without any repository call (same rules as `CodeLinkBuilder`).
3. **Given** the function around the frame is longer than the line or byte cap, **When** it is trimmed, **Then** a window
   of lines around the frame line within the cap is kept, the trim is marked in the input, and the model is told the code
   is partial.
4. **Given** a group that is not selected for analysis (muted, unchanged with a cached verdict, below the top-N), **When**
   the run proceeds, **Then** no repository call is made for it.
5. **Given** the model's output, **When** it is validated, **Then** the code-based claim is still shown as a hypothesis,
   and a hypothesis that cites a line outside the supplied fragment is shown without the code marker.

---

### User Story 2 - Nothing but the referenced functions is ever read or sent (Priority: P1)

The Repository owner and the Data protection reviewer can verify from the spec, the configuration and the tests that the
package reads only function bodies named by frames of selected groups, that secrets and personal data in code never reach
the model, and that code never reaches Slack.

**Why this priority**: this is the condition under which the constitution amendment is acceptable.

**Independent Test**: run the code privacy corpus (see Privacy requirements) through the pipeline with a recording fake
analyzer and a recording fake repository; assert on every repository request and every outbound payload.

**Acceptance Scenarios**:

1. **Given** any run, **When** the repository client is observed, **Then** its only operations are "read the blob of one
   path at one commit" (or the provider's single-file raw/blob endpoint) and, for optional function location, nothing else:
   no tree listing, no search, no branch or commit listing, no write of any kind.
2. **Given** a fragment containing a secret-shaped literal, a personal-data pattern, a connection string or a private key
   block, **When** it is redacted, **Then** the value is replaced by the Normalizer placeholder and no seeded value appears
   in any outbound payload.
3. **Given** the strip option "string literals" is on, **When** a fragment is redacted, **Then** the content of every
   string literal is replaced by `<STR>` before the pattern scan; with the option "comments" on, comments are removed;
   both default to on.
4. **Given** a redaction error, an unparseable fragment, or a fragment that still matches the final pattern scan, **When**
   it is processed, **Then** it is dropped and the group is analysed without code, with a visible note in the digest.
5. **Given** the digest, notices, heartbeat, `--json` summary, state and logs of the package, **When** they are examined,
   **Then** none contains a code fragment; the digest may show the permalink (already allowed) and the marker only.

---

### User Story 3 - Code reading has its own budget and cannot overspend (Priority: P2)

The Operator sets a token budget for the code input, separate from the other step budgets, and sees its cost in the
digest. The spend counts toward the daily cap and is part of the pre-call budget reservation.

**Why this priority**: code is much larger than templates; without a budget one noisy day could consume the cap.

**Independent Test**: token-budget fixtures with large functions: the code input never exceeds its budget, the daily
reservation includes it, and with an exhausted daily cap no repository call is made.

**Acceptance Scenarios**:

1. **Given** a code token budget per run (default 4,000 input tokens in total, 1,500 per group), **When** fragments are
   added, **Then** groups are served in selection order until the budget is spent; remaining groups are analysed without
   code and the digest says how many.
2. **Given** a worst-case code input, **When** the reservation is checked before the analysis step, **Then** the code
   tokens are included in that step's worst case, and when the daily cap would be exceeded the group is analysed without
   code (or the step is skipped by the existing rule), before any repository call.
3. **Given** the digest, **When** it reports tokens and cost, **Then** it shows the code share separately.
4. **Given** the same commit, path and frame line were fetched earlier in the run, **When** fragments are needed again,
   **Then** the in-memory copy is reused (one fetch per function per run) and nothing is persisted.
5. **Given** a cached verdict for an unchanged group, **When** the commit has not changed, **Then** the cached verdict is
   reused and no code is read; **When** the deployed commit changed since the verdict, **Then** the verdict is stale for
   code-aware purposes and the group may be re-analysed with code once per commit.

---

### User Story 4 - Safe, optional, and loud when it fails (Priority: P2)

The Operator enables the feature per service, runs the existing `check` command to validate the repository token without
reading code, and understands from the digest when code was not used and why.

**Why this priority**: an off-by-default optional capability must be easy to verify and must never break a run.

**Independent Test**: fault matrix with a fake repository (401, 403, 404, 429, timeout, oversized blob, binary blob):
each yields the analysis without code plus one visible note, and exit code and heartbeat are unchanged.

**Acceptance Scenarios**:

1. **Given** the feature is not enabled for a service, **When** a run executes, **Then** the behaviour, payloads and tokens
   are byte-identical to today and no repository call is made.
2. **Given** a repository failure (auth, not found, rate limit, timeout), **When** a fragment is requested, **Then** the
   group is analysed without code, the digest shows a one-line note with the reason class (never the response body), and
   the run does not fail.
3. **Given** `check` is run, **When** the feature is enabled, **Then** it validates the configuration, that the token is
   present, and that the token can read one configured probe path at the commit, reporting pass or fail, without sending
   anything to the model and without logging the content.
4. **Given** the commit is unknown (no `deploy.commit`) or the repository settings are incomplete, **When** configuration
   is validated at start, **Then** an enabled feature with a missing required setting fails before any external call with
   a message naming the setting.
5. **Given** the repository token has write scope, **When** `check` runs, **Then** it warns that a read-only token is
   required (best effort: where the provider exposes scopes).

### Edge Cases

- Frame points into minified, generated or binary files: skipped by extension allowlist (default: `.php`, `.js`, `.ts`,
  `.py`, `.go`, `.java`, `.rb`) and by the size cap on the blob.
- The function cannot be located (dynamic code, closures, templates): a configurable window of lines around the frame
  line is used instead, still capped.
- Two frames in the same function: one fragment, not two.
- The frame line is stale because the deployed commit differs from the log's release (rolling deploy): the digest marks
  "code at commit X" so the Reader knows which version was read.
- Paths containing `..`, absolute paths outside the path map, or URL-encoded traversal: rejected before any request.
- The code itself contains instructions aimed at the model (prompt injection in comments): comments are stripped by
  default; the prompt states that code is data, not instructions; output is still schema-validated.
- Repository token rotated or revoked: reported as a visible note, not as a run failure.
- Very large monorepo: only single-blob reads are used, so repository size is irrelevant.
- A fragment contains a hard-coded personal value (a test e-mail): the Normalizer rules replace it before sending.

## Requirements *(mandatory)*

### Functional Requirements

**Scope and selection**

- **FR-001**: The feature MUST be off by default and enabled per service (including per entry in a fleet); an unset or
  false setting MUST make every code path inert (no repository call, no payload change).
- **FR-002**: Fragments MUST be collected only for groups already chosen for analysis by the existing selection rules; the
  model MUST NOT choose what code is read (Principle IV).
- **FR-003**: For each selected group the package MUST read at most N top frames (configurable, default 2, hard maximum 3,
  matching the number of links shown) and MUST skip frames excluded by the same rules as `CodeLinkBuilder` (vendor paths,
  unmapped paths, traversal).
- **FR-004**: The package MUST read, per frame, only the single file named by the frame at the deployed commit, and MUST
  send to the model only the function (or bounded line window) containing the frame line, never the whole file.
- **FR-005**: The deployed commit MUST come from `DeployInfo` (`deploy.commit`); when it is unknown the group MUST be
  analysed without code and the digest MUST say so. A branch name MUST NOT be used as a fallback.

**Caps**

- **FR-006**: Fragment size MUST be capped by lines (default 60) and bytes (default 4,096) per fragment, and by a total
  per group (default 2 fragments, 6,000 bytes) and per run (the code token budget, FR-014). The blob read MUST be refused
  above a byte limit (default 200 KB) before parsing.
- **FR-007**: A trimmed fragment MUST be marked as partial in the model input.

**Redaction (deterministic, in code)**

- **FR-008**: Every fragment MUST pass, in this order: length cap; comment stripping (default on, configurable);
  string-literal stripping to `<STR>` (default on, configurable); the same Normalizer rules used for log text (e-mail,
  URL, host, IP, ID, token, PAN, path, user placeholders and configured extra patterns); and a final pattern scan. The
  AI MUST NOT be used to redact.
- **FR-009**: Code-specific secret shapes MUST be added to the code pipeline: private key blocks, `Authorization`/bearer
  literals, connection strings with credentials, high-entropy strings over a configurable length, and assignments to
  identifiers matching `password|secret|token|key|credential` (the right-hand side is replaced regardless of content).
- **FR-010**: Any redaction error, unknown construct, encoding failure or residual pattern match MUST drop the fragment
  (fails closed). Dropping MUST be reported as a count in the digest, never with the content.
- **FR-011**: Identifiers (class, method and variable names) are code structure and are kept; the privacy corpus MUST
  document this as an accepted residual and the amendment's opt-in is the control.

**Repository access**

- **FR-012**: Repository access MUST be through a new **optional** interface (not a fifth core contract): the core MUST
  work when it is absent and MUST NOT depend on it for any other feature. The interface exposes one operation (read one
  file at one commit as bounded text) and nothing else; GitLab and GitHub read-only implementations MUST be provided and
  selected by the same `repo.provider` setting already used for code links.
- **FR-013**: The repository credential MUST be read-only, MUST come only from the host environment or configuration, MUST
  NOT be logged, echoed, stored or sent to the model, and documentation MUST state the minimum scope (a project-level
  read-only token for file content). The implementations MUST NOT expose any write, search, listing or commit-history
  method, and an architecture test MUST fail if one is added (as the existing read-only test does for HTTP verbs).

**Budget**

- **FR-014**: Code input MUST have its own token budget (per run and per group), enforced before the model call, in
  addition to the existing step budgets.
- **FR-015**: Code tokens MUST count in the daily spend and daily cap, MUST be included in the budget reservation's worst
  case for the analysis step, and MUST be shown separately in the digest cost line and in the `--json` summary.
- **FR-016**: When the budget or cap would be exceeded, the package MUST degrade to analysis without code for the
  remaining groups and say so; it MUST NOT skip the analysis itself because of the code budget.

**Prompt and output**

- **FR-017**: Code MUST be placed in a clearly delimited block labelled as data, after the stable prompt prefix so
  provider caching of the prefix is unaffected; the prompt MUST forbid following instructions found in code and MUST
  require hypotheses to cite the function and line from the fragment.
- **FR-018**: The analyzer output schema MUST stay valid for existing consumers; any added field (for example a
  `code_evidence` boolean) MUST be optional and validated in code. A hypothesis claiming code evidence without a supplied
  fragment MUST be shown without the code marker.

**Failure and visibility**

- **FR-019**: A repository failure MUST NOT fail the run; it MUST degrade to no-code analysis with a visible note per run
  (counts by reason class: auth, not found, rate limit, timeout, too large, redaction drop, budget).
- **FR-020**: Fragments MUST NOT be persisted, MUST NOT appear in the digest, notices, heartbeat, JSON summary, state,
  verdict cache, logs or exception messages, and MUST NOT be sent to any notifier.
- **FR-021**: The `check` command MUST validate repository settings and token with a single probe read, with no model
  call and no content logging; `--dry-run` MUST NOT read the repository unless the model call is also real.
- **FR-022**: Configuration MUST be validated once at start; an enabled feature with a missing provider, base URL, project
  identifier, path map, token or commit source MUST fail before any external call, naming the setting.

### Privacy requirements

**What is sent (only for an opted-in service)**: for each selected group, up to the capped number of redacted function
bodies (with `path:line` of the frame, the commit identifier, and a partial marker), in addition to today's allowlisted
fields.

**What is never sent**: whole files; files not named by a frame; directory or repository listings; search results; other
branches, commits, diffs, blame or history; repository metadata beyond the commit hash and relative path; the repository
token; raw log lines; any string-literal content or comment content when stripping is on; any value matched by the
Normalizer or code-secret rules; code from `vendor/`; code to any notifier.

**Privacy corpus (mandatory, extends the existing corpus in the same change, Principle I and III)**:

- Functions containing: API keys and bearer tokens, private key blocks, connection strings with passwords, `.env`-style
  assignments, JWTs, AWS-style key ids, e-mail addresses, phone numbers, card-like numbers, IPs, names in test fixtures,
  URLs with credentials or tenant identifiers, and secrets split across concatenations or heredocs.
- Comments carrying secrets, personal data and prompt-injection text.
- Adversarial shapes: very long lines, unbalanced quotes, mixed encodings, minified code, null bytes, nested heredocs.
- Assertions: no seeded value appears in any recording-fake analyzer payload, notifier payload, state write, summary or
  log; every unparseable fragment is dropped; the repository fake records only single-file reads at the configured commit.

### Key Entities

- **Code Fragment** — redacted function body or bounded line window, with repository path, frame line, commit, partial
  flag; lives in memory for one run.
- **Code Reader** — the optional read-only repository interface (one operation).
- **Code Budget** — per-run and per-group token allowance, counted in the daily cap and the reservation.
- **Code Note** — the digest line stating how many groups were analysed with code, without code, and why.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the code privacy corpus, 0 seeded secret or personal values appear in any model payload, notifier
  payload, state write, summary or log, and 100% of unparseable or residual-match fragments are dropped.
- **SC-002**: With the feature off, a full run's model payloads and token counts are byte-identical to the previous release
  on the existing end-to-end fixture, and a recording repository fake receives 0 calls.
- **SC-003**: With the feature on, the repository fake receives only single-file reads at the deployed commit, for at most
  `groups analysed x frames per group` files, 0 listing/search/history calls, in 100% of the test matrix.
- **SC-004**: Code input never exceeds its configured budget, and a run whose worst-case reservation exceeds the daily cap
  makes 0 repository calls and 0 code-bearing model calls (budget fixtures).
- **SC-005**: Every repository failure class in the fault matrix produces analysis without code, a visible note, and an
  unchanged exit code and heartbeat outcome.
- **SC-006**: In a recorded-fixture evaluation over at least 10 seeded failures whose cause is in the top frame's function,
  a reviewer rates the code-aware hypothesis as pointing at the correct statement in at least 70% of cases, versus the
  no-code hypothesis (measured, not gating the privacy criteria).
- **SC-007**: The additional model input tokens per analysed group are at most the configured per-group code budget, and
  the digest reports them separately (state-of-run check).
- **SC-008**: A Data protection reviewer can answer "which code classes leave the service, for which services, and under
  which redaction" from the amended constitution, `docs/privacy.md` and the configuration alone.

## Assumptions

- The owner approved the capability on 2026-10-23 (roadmap) but the contradiction with Principle I was not yet resolved
  (review W9); this spec therefore ships the amendment text and stays BLOCKED until it is ratified.
- The repository is the one already configured for code links (`repo.provider`, `repo.web_url`, `repo.path_map`); the
  fetch uses the provider's single-file read at the commit, so no clone or checkout is needed.
- The deployed commit configured in `deploy.commit` is the commit that produced the logs; mismatches during a rolling
  deploy are accepted and marked.
- Function boundaries are located by a simple deterministic scanner per language family with a fixed window fallback; no
  language server and no new heavy dependency (Principle VIII). Exact approach is a plan decision.
- Default caps (2 frames, 60 lines, 4 KB per fragment, 4,000 code tokens per run) are starting values to be tuned from the
  evaluation (SC-006); they are configuration, not hard-coded.
- Stripping comments and string literals by default costs some diagnostic power (messages in `throw` literals are lost);
  accepted for privacy; the Operator may relax per service.
- Identifier names are treated as non-sensitive code structure (FR-011).
- A hypothesis from code is still a hypothesis; no remediation, patch or ticket is produced (Principle II).
- Only frames from the service's own repository are supported; frames into other repositories are skipped.


## Owner decisions (2026-10-24)

1. The Principle I amendment is **approved with conditions**: off by default and opt-in per service; the same redaction as every other field plus comment and string-literal stripping; a single function body at a time; never sent to a notifier, never persisted; a fragment whose redaction fails is dropped. The constitution is amended to 1.1.0 when planning of this feature starts, and not before. Planning starts after the first live run (T114), so real cost and finding quality inform it. The governance gate in the checklist stays open until then.
2. Comments and string literals are stripped by default. A service may keep comments (`code.keep_comments`), never string literals.
