# Implementation Plan: Incident Workflow (v1)

**Branch**: `005-incident-workflow` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

**Input**: [spec.md](spec.md). Decisions: [research.md](research.md). Objects and state: [data-model.md](data-model.md).
Contracts: [contracts/config.md](contracts/config.md), [contracts/known-issues.md](contracts/known-issues.md),
[contracts/ticket-draft.md](contracts/ticket-draft.md), [contracts/weekly-report.md](contracts/weekly-report.md).
Scenarios: [quickstart.md](quickstart.md). Order: [tasks.md](tasks.md).

## Summary

Four independent increments on the existing pipeline, none with inbound traffic or tracker access: (1) `KnownIssues`
removes operator-listed groups from candidate selection until they grow, and labels findings with their ticket key; (2)
`TicketDraft` renders a fixed-layout text per finding and sends it to a destination after the digest; (3) `RouteTable`
splits the findings of a digest across destinations by severity using the existing drivers; (4) `watcher report` builds a
weekly message from the spec-013 event file. The four core contracts are unchanged; no new contract is introduced.

## Technical Context

**Language/Version**: PHP ^8.1. **Dependencies**: none added. **Storage**: existing state store, four new key kinds with
expiry (data-model.md); reads the spec-013 JSONL file. **Testing**: PHPUnit `Unit` / `EndToEnd`; fakes for notifiers and
analyzer; fixture event file; no network. Gates: `composer test`, `composer stan`, `composer style`; read `$?`.
**Performance**: known issues add one state read per matching group; drafts add at most `max_per_run` sends; the report
reads one week of a local file (bounded by `max_bytes` x `max_files` of the sink). **Egress added**: none (existing
destinations only).

## Constitution Check

| Principle | Result |
|---|---|
| I Privacy fails closed | Pass, with work. Drafts, route messages and known lines use only digest-allowed data plus configured names; the report reads an allowlist of event fields and ignores text (FR-031). Persisted: counts and markers with expiry. Corpus extended in the same change (T009, T019; FR-042). |
| II Read-only | Pass: outputs are notifier messages and own state; the only extra read is the package's own event file. No listening socket, no tracker call (FR-040, architecture test). Buttons/threads/tracker access are deferred (D1-D3) because they would need an amendment. |
| III Test-first | Pass: every task is red then green; mandatory suites extended (privacy corpus, token-budget fixture, formatter injection corpus). |
| IV Code decides | Pass: suppression, thresholds, routing, draft selection and layout are code from configuration and counts; no model involvement; the label text is code-built. |
| V Token/cost | Pass, decrease only: known groups make 0 calls (SC-001). Drafts and report make no model call. Token-budget fixture added. |
| VI Config/contracts | Pass: all thresholds, destinations, severities and ticket keys from configuration; no tracker name in the core; no new contract. |
| VII Failures loud | Pass: draft/route failures produce notices; report failure is a non-zero exit; "known" and "routed" are visible lines, never silent; empty week prints why. |
| VIII Simplicity | Pass with notes: four small concrete classes groups; rejected for size: tracker search, templates, mentions, threads, post-release runs, a report inside `run`, copy-to-all routing. |

No unjustified violation; the complexity table is empty.

## Design in one page

1. **Config** (`WatchConfig`): `known_issues`, `drafts`, `notify.routes`, `report.weekly` parsed into value objects
   (`KnownIssue[]`, `DraftSettings`, `Route[]`, `ReportSettings`); secrets list unchanged (routes reuse `Destination`).
2. **Known issues** (`Watcher::attempt`): after `$mute->filter(...)`, `KnownIssues::partition()` returns kept groups and
   lines; kept groups still include `known_grown` ones (reason carried to `Selector`). References are written through
   `$buffer` after the digest is delivered. The line goes to `DigestData` (`known` lines) and the renderer emits one
   `minor` section. Finding labels are added where the digest formats a finding.
3. **Routing** (`Watcher` delivery block near the digest send): `RouteTable::assign()` splits findings; routes are sent
   first, each with its own marker, then the default digest with the `routed` line. A failure per destination is caught,
   noticed and does not stop the rest.
4. **Drafts** (after the delivered marker is written): `DraftPlan` selects, `TicketDraft` renders, each message goes to
   the drafts destination; markers after delivery; failures noticed.
5. **Formatters** (`SlackText`, `TelegramText`, `DiscordText`, `MessageSplitter` limits): section kind `draft` as a code
   block with fence neutralisation.
6. **Report** (`src/Report/*`, `Cli\Application` command `report`, `fleet:report`): `EventLog` -> `WeeklyReport` ->
   renderer -> notifier; marker `rsent`.
7. **Operate**: `JsonSummary` gains `known`, `known_grown`; `Doctor` reports the four settings and informational lines;
   `--dry-run` prints drafts.
8. **Docs**: configuration, operations, privacy, roadmap, changelog, upgrade note (none required).

## Files

| New | Modified (**bold = hot: other specs and agents edit them**) |
|---|---|
| `src/Incident/{KnownIssue,KnownIssues,KnownResult,KnownReferences,TicketDraft,DraftPlan,Route,RouteTable}.php`, `src/Report/{EventLog,WeeklyReport,ReportData,ReportRenderer}.php` | **`src/Watcher.php`**, **`src/Config/WatchConfig.php`**, **`src/Config/Factory.php`**, **`src/Notify/{DigestData,DigestRenderer,SlackText,TelegramText,DiscordText,MessageSplitter}.php`**, **`src/State/Keys.php`**, `src/Select/{Selector,CandidateReason,Candidate}.php`, **`src/Operate/{JsonSummary,RunSummaryData,Doctor}.php`**, **`src/Cli/Application.php`**, **`src/Fleet/{FleetConfig,FleetRunner}.php`**, `tests/Unit/ArchitectureTest.php`, `tests/Unit/PrivacyCorpusTest.php`, `config/watcher.php`, `config/watcher.example.php`, `docs/examples/*.php`, docs (configuration, operations, privacy, roadmap), `CHANGELOG.md`, `VERSION`/`composer.json` per release practice |
| tests under `tests/Unit/{Incident,Report}/`, `tests/Unit/Config/IncidentConfigTest.php`, `tests/EndToEnd/{KnownIssues,Drafts,Routing,Report}Test.php`, fixtures `tests/Fixtures/report/` | |

## Release

Additive configuration, every new feature default off or inert: **MINOR** release. CHANGELOG entry; upgrade note "none
required". Order of releases follows the task phases: each phase may ship alone.

## Risks

| Risk | Handling |
|---|---|
| Hot-file collisions with 013, 014, 009, 010 in flight | sequential tasks, re-read file before editing, `tasks.md` hot-file table; known-issues hunk in `Watcher.php` is one block after the mute filter |
| A known entry silences a real regression | growth threshold, `resolved` never suppresses, line always shows level and count, `check` shows stale entries |
| Draft text breaks a channel's markup or pings | `draft` kind goes through the spec 011 injection corpus on all drivers |
| Partial multi-destination delivery repeats posts on retry | per-destination markers written after delivery (FR-022) |
| Report reads a huge or hostile file | line length cap, file size cap from the sink, allowlist parse, counts of skipped lines |
| 013 not landed when Phase 5 starts | Phase 5 is gated on 013's `FileSink` and schema; phases 1-4 do not depend on it |

## Order and versions on hot files

`Watcher.php`, `WatchConfig.php`, `Factory.php`, `Notify/*` and `Keys.php` are edited by 013, 014 and (pending) 009/010. This spec
lands after whichever of them is already on `main`; every HOT task re-reads the file and rebases on it (tasks.md table).
Each phase is its own MINOR bump relative to the then-current `VERSION` on `main` (never a number reserved here), with its
own CHANGELOG entry. The weekly report (Phase 4) additionally waits for 013 (FR-029, T020a).
