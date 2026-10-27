# Quickstart: validate 0.5.0 offline

Written for the implementer and the reviewer. No network, no spend.

## 1. Gates

```bash
composer test    ; echo "test=$?"
composer stan    ; echo "stan=$?"
composer style   ; echo "style=$?"
```

## 2. Removed keys fail at load (no network)

```bash
vendor/bin/phpunit --filter RemovedKeys
```

Expected: each key in `contracts/config-migration.md` fails with its exact message.

## 3. No budget or currency name in the shipped tree (no network)

```bash
vendor/bin/phpunit --filter NoBudgetNames
```

Expected: the guard passes over `src/`, `docs/`, `docs/examples/`, excluding the CHANGELOG and specs.

## 4. Event v2 (no network)

```bash
vendor/bin/phpunit --filter EventV2
```

Expected: every emitted run record validates against `docs/schema/watcher.event.v2.schema.json`; no removed field appears.

## 5. Same findings (no network)

```bash
vendor/bin/phpunit --filter SameFindings
```

Expected: for the recorded window and each provider's recorded fixtures, the findings equal the 0.4.1 baseline.

## 6. Live smoke

Not part of this change's automated checks; it needs the owner's explicit go-ahead.
