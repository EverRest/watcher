# Feature Specification: Feature Flags

**Feature Branch**: `012-feature-flags`

**Created**: 2026-10-24

**Status**: Draft

**Input**: Owner request: every feature must be switchable on and off through the config array and through environment variables, so one package can be enabled or disabled per environment (int, prod, ...).

## Overview

One package serves several environments. Today an Operator cannot say "int runs, dev does not" or "prod posts a weekly line, int does not" without editing code. This feature adds explicit boolean flags and an `environment` name, settable from the config array and from `WATCHER_*` environment variables, with one documented variable per flag.

**Actors**: Operator (owns the config and the deploy environment), Host monitoring (reads `status`).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Master switch per environment (Priority: P1)

The Operator deploys the same package and config to int, prod and dev and turns the watcher on only where wanted.

**Independent Test**: run with fakes and `enabled` false (the default): outcome `disabled`, exit 0, no call reached source, state, analyzer or notifier.

**Acceptance Scenarios**:

1. **Given** `enabled` is not set or false, **When** `watcher run` starts, **Then** the outcome is `disabled`, the exit code 0, `watcher: disabled (set enabled=true / WATCHER_ENABLED=1)` goes to stderr, and nothing external or stored is touched (no heartbeat either).
2. **Given** `enabled` is false, **When** `watcher status` runs, **Then** it reports state `disabled` without reading the store, exit 0.
3. **Given** `enabled` is false, **When** `watcher check` runs, **Then** the configuration is still validated, every other check is skipped as `disabled`, and nothing is called.
4. **Given** `enabled` is true, **Then** the run behaves exactly as before this feature.

### User Story 2 - Switch individual features (Priority: P1)

The Operator turns each behaviour on or off with one flag. Every flag defaults to on (today's behaviour) except the master `enabled` (off) and `heartbeat.weekly_summary` (already opt-in).

**Acceptance Scenarios**:

1. **Given** a flag (`heartbeat`, `purge`, `dependencies`, `deploy`, `links.logs`, `links.code`, `service_context`, `watch_rules`, `mute`, `level_anomaly`, `selection.escalation`, `analysis.triage`, `baseline`, `grouping`, `budget_alerts`, `notify.notice`, `notify.digest`, fleet `correlation`) is set false, **When** a run happens, **Then** that feature performs no read, write, model call or notice.
2. **Given** no flag is set and `enabled` is true, **Then** behaviour and output equal the pre-feature behaviour byte for byte. A feature that needs its own block (dependencies, links, deploy, watch_rules, mute) stays inert when unconfigured.
3. **Given** `analysis.triage.enabled` is false, **Then** no triage call is made and the selected candidates go straight to analysis within budget.
4. **Given** `notify.notice.enabled` is false, **Then** failures still reach the host log and the exit code, but no notice is posted.
5. **Given** `notify.digest.enabled` is false, **Then** the run reads, snapshots and ends `quiet` (reason `digest_disabled`) before any model call.
6. **Given** a flag in an array config is not a real boolean (for example the string `'false'`), **Then** loading fails with `InvalidConfig` naming the key path.
7. **There is no flag for redaction, the normalizer or the allowlist, and none for read-only behaviour.** Privacy is not optional.

### User Story 3 - Environment name and environment variables (Priority: P2)

1. **Given** `environment` is set, **Then** every state key prefix is `<service>@<environment>`, and the digest header and JSON summary show it.
2. **Given** a plain-PHP config, **Then** `Watcher\Config\Env` (`bool`, `int`, `string`, `float`, `list`) reads the variables; an invalid value throws `InvalidConfig` naming the variable, never its value.
3. **Given** a fleet, **Then** a service with `enabled` false is skipped with outcome `disabled` (not an error); fleet `enabled` false disables all; a duplicate `service@environment` is rejected.

## Requirements *(mandatory)*

- **FR-001**: `enabled` (default false) gates the whole run.
- **FR-002**: `environment` (optional) prefixes state keys as `service@environment`; two processes with the same prefix share state; this is not detectable per process and is documented; fleet validation rejects duplicates.
- **FR-003**: Each flag in the table of docs/configuration.md exists with the stated default and the stated `WATCHER_*` variable.
- **FR-004**: A disabled feature does nothing: no read, write, model call, notice or cost.
- **FR-005**: Flags are validated as booleans, strictly, with the key path in the message.
- **FR-006**: No config key disables redaction (architecture test).
- **FR-007**: `analysis.enabled` is not provided (see plan).

## Success Criteria

- **SC-001**: With `enabled` true and no new key, outputs and state are unchanged.
- **SC-002**: One test per flag proves "disabled does nothing" with spies.

## Assumptions

- Only the master switch is opt-in (owner decision): existing configs must add `enabled => true` (`WATCHER_ENABLED=1`) after upgrading.
- Fleet entries inherit `enabled` from `defaults`.
