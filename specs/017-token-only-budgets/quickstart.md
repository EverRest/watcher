# Quickstart: validate 0.5.0 offline

Written for: the implementer and the reviewer. No network, no spend.

## 1. Gates

```bash
composer test    ; echo "test=$?"
composer stan    ; echo "stan=$?"
composer style   ; echo "style=$?"
```

## 2. Migration rejects removed keys (no network)

```bash
vendor/bin/phpunit --filter Migration
```

Expected: each key in `contracts/config-migration.md` fails at load with its exact message.

## 3. Token limits (no network)

```bash
vendor/bin/phpunit --filter 'BudgetReservation|RunLimit'
```

Expected: a run with `run_token_limit` set stops before the next billable step; default null changes nothing.

## 4. No currency anywhere (no network)

```bash
vendor/bin/phpunit --filter NoCurrency
```

Expected: the repository guard passes; no `usd`, `Usd`, `USD`, `CostCalculator` or `cost_usd` in `src/`, `docs/`, `docs/examples/`.

## 5. Event v2 (no network)

```bash
vendor/bin/phpunit --filter EventV2
```

Expected: every emitted run record validates against `docs/schema/watcher.event.v2.schema.json`; no cost property is present.

## 6. Same tokens (no network)

```bash
vendor/bin/phpunit --filter ProviderRunTest
```

Expected: for the recorded window, the token counts equal those of 0.4.1 (zero change).

## 7. Live smoke (costs money; owner go-ahead only)

`docs/smoke-test.md` check 6 compares the digest's tokens with the provider console for the same window (within about 10%).
