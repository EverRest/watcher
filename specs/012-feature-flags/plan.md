# Implementation Plan: Feature Flags

**Spec**: [spec.md](spec.md)

## Summary

`WatchConfig` parses every flag strictly into `flags` (`on(name)`), plus `enabled`, `environment`, `stateKey`. `Watcher` consults `on()` at each feature; `Env` supplies the environment variables in config files.

## Decisions

- Flags live in the natural section as `enabled`. Where the section is a list, a map keyed by user names, or a string, a reserved `enabled` key is read from it (`mute`, `watch_rules`, `dependencies`) or the string becomes `['enabled' =>, 'text' =>]` (`service_context`).
- `notify.notice` may be `['enabled' => bool]` alone: it then uses the digest destination.
- Disabled `watch_rules` / `mute` are stored empty; the other gates are in `Watcher`.
- `analysis.triage` off: `ModelRouter` treats every fresh group as `interesting` (no triage call, no checkpoint).
- `selection.escalation` off: router escalation cap 0.
- `level_anomaly` off: no totals read; snapshots omit `totals`, and a missing `totals` means "unknown", not "appeared".
- `baseline` off: resolver mode forced to `previous`. `grouping` off: `Reducer` `groupBySimilarity=false` (old exact token-count clusters).
- `budget_alerts` off: no threshold alert and no exhausted notice. `notify.notice` off: every notice and weekly line is skipped before any state access.
- `notify.digest` off: ends like a quiet run before redaction and model calls.
- Fleet: `ServiceEntry::name()` is the state key; disabled services get `ServiceResult::disabled`.
- Skipped: `analysis.enabled` (a digest without findings needs a new groups-only renderer section and privacy corpus extension, not small).

## Tests

Test-first: `tests/Unit/Config/EnvTest`, `FeatureFlagsConfigTest`, `tests/EndToEnd/FeatureFlagsTest`, fleet and CLI tests, architecture test for redaction.
