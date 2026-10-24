# Tasks: Feature Flags

Tests first; each must fail before its implementation. Gates: `make ci; echo $?`.

- [x] T001 EnvTest, then `src/Config/Env.php`
- [x] T002 FeatureFlagsConfigTest (defaults, strict booleans, environment, stateKey, no redaction flag), then `WatchConfig`
- [x] T003 RunOutcome::Disabled; run/status/check/JSON for a disabled config, stderr hint (CliTest, FeatureFlagsTest), then `Watcher`, `Application`, `Factory`, `Doctor`, `HeartbeatStatus`
- [x] T004 one e2e test per flag proving "disabled does nothing", then gates in `Watcher`, `ModelRouter`
- [x] T005 environment in keys, digest header and JSON summary
- [x] T006 fleet: service and fleet `enabled`, duplicate `service@environment`
- [x] T007 config files, examples, docs, CHANGELOG, README
- [x] T008 fix existing tests that relied on old defaults by setting the flag explicitly
