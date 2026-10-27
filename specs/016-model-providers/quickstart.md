# Quickstart: validate the multi-provider layer

Written for: the implementer and the reviewer, to prove the feature works end to end without spending money.

## Prerequisites

- PHP 8.2 or newer, Composer, Docker (for Redis contract tests, optional).
- A checkout on branch `016-model-providers` with `composer install` run.

## 1. Gates (must all exit 0)

```bash
composer test    ; echo "test=$?"
composer stan    ; echo "stan=$?"
composer style   ; echo "style=$?"
```

Read the exit codes, not the tail of the output.

## 2. Provider contract (no network)

```bash
vendor/bin/phpunit --filter ModelProviderContract
```

Expected: the same cases run for `AnthropicSdkProvider`, `OpenAiProvider`, `GeminiProvider` and the fake, all green. Each failure case asserts that no URL, key or body text appears in the message.

## 3. Privacy across providers (no network)

```bash
vendor/bin/phpunit --filter Privacy
```

Expected: the seeded personal values in `tests/Fixtures/privacy` appear in none of the three outgoing bodies.

## 4. Config rules (no network)

```bash
php bin/watcher check --config=tests/Fixtures/config/provider-mix.php --json
```

Expected: a config with `triage = gemini` and the other steps on `anthropic` loads; a config with `analysis.client = sdk` fails at load with the R7 message; a config where a used provider has no key fails naming `analysis.api_keys.<name>`.

Fixture names above are the planned ones; tasks create them.

## 5. Live smoke (costs money; only after 1 to 4 are green)

Follow `docs/smoke-test.md`. Run it once per provider you enabled, with `analysis.provider` set to that provider and the same window. Compare the cost line with the provider console (within about 10%, as today).

## Expected outcomes summary

| Check | Expected |
|---|---|
| Gates | all exit 0 |
| Contract | every adapter passes the same cases |
| Privacy | no seeded value in any outgoing body |
| Config | mixed-provider config loads; `analysis.client = sdk` refused with the replacement message |
| Tokens | unchanged for the same configuration (R8); budget fixtures pass for each provider |
