# Contract: Public PHP API

Only the contracts the spec requires (Principle VIII): log sources (2+ implementations), state
stores (3), notifier (extension point, Slack in v1), analyzer (extension point for a future batch
mode). Everything else is a concrete `final` class.

```php
namespace Watcher;

/** Single entry point (FR-033). */
final class Watcher
{
    public static function fromConfig(
        array $config,
        ?\Psr\Log\LoggerInterface $hostLog = null,
        ?\Psr\Clock\ClockInterface $clock = null,   // PSR-20; default system clock; tests pass FrozenClock
    ): self;
    /** Decides whether a run is due (FR-013), runs it, returns the outcome. Never throws for expected failures. */
    public function run(?\DateTimeImmutable $now = null): RunResult;
}

final class RunResult
{
    public RunOutcome $outcome;   // enum: skipped_not_due, skipped_locked, skipped_done,
                                  //       baselined, quiet, reported, failed
    public ?Usage $usage;
    public ?string $failureReason;
    public function exitCode(): int; // 0 except failed → 1, invalid config → 2
}
```

## LogSource

```php
namespace Watcher\Source;

interface LogSource
{
    /**
     * Backend-side totals per level for the window, scoped by stream tags (FR-012a, FR-036).
     * @return array<string,int> level => count
     * @throws SourceUnavailable  network/auth/limit failure (→ "source failed" notice)
     */
    public function totals(Window $window, StreamSelector $selector): array;

    /**
     * Server-filtered lines (level ≥ $filter->minLevel OR any $filter->watchRules), paged,
     * stopping at $filter->eventCap. Yields lazily so the reducer never holds all events.
     * @return iterable<LogEvent>
     * @throws SourceUnavailable
     */
    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): iterable;

    /**
     * Deploy versions seen in the window via a per-line label (FR-041).
     * @return array<string, \DateTimeImmutable> version => first seen in window; [] if label absent
     * @throws SourceUnavailable
     */
    public function versions(Window $window, StreamSelector $selector, string $label): array;
}
```

v1 implementations: `LokiSource`, `DatadogSource`. Both pass `tests/Contract/LogSourceContractTest`
driven by recorded HTTP fixtures.

## StateStore

```php
namespace Watcher\State;

interface StateStore
{
    public function get(string $key): ?array;
    public function put(string $key, array $value, int $ttlSeconds): void;
    /** Atomic add-if-absent with expiry (FR-028). Returns false if the key exists. */
    public function acquire(string $key, string $owner, int $ttlSeconds): bool;
    public function release(string $key, string $owner): void;
    /** Atomic float increment for the daily spend counter (FR-022). */
    public function increment(string $key, float $by, int $ttlSeconds): float;
}
```

v1 implementations: `RedisStateStore` (ext-redis `\Redis`), `PdoStateStore` (any PDO: MySQL,
PostgreSQL, SQLite; one table), `FileStateStore` (JSON files + `flock`; single runner only). All pass
`tests/Contract/StateStoreContractTest`, including expiry and lock contention.

## Analyzer

```php
namespace Watcher\Analyze;

interface Analyzer
{
    /** One request. Returns validated, schema-conformant output plus usage. */
    public function triage(TriageRequest $req): TriageResult;
    public function analyze(AnalysisRequest $req): AnalysisResult;
}
```

v1 implementation: `AnthropicAnalyzer`. Routing (`ModelRouter`), budgets (`Budget`) and the
escalation rule live outside the analyzer so they are tested without any provider.

## Notifier

```php
namespace Watcher\Notify;

interface Notifier
{
    /** @throws DeliveryFailed */
    public function send(Message $message, Destination $to): void;
}
```

v1 implementation: `SlackWebhookNotifier`. Message rendering (`DigestRenderer`) is provider-neutral
data → Slack Block Kit in the notifier.

## Framework adapter (Lumen/Laravel)

- `Watcher\Laravel\WatcherServiceProvider` — merges `config/watcher.php`, binds `Watcher`.
- Artisan command `watcher:run {--now=}` → `Watcher::run()`, returns `RunResult::exitCode()`.
- Host schedules it hourly (`$schedule->command('watcher:run')->hourly()` or a k8s CronJob); the
  package decides whether a run is due (FR-013).

## Plain PHP

```bash
vendor/bin/watcher run --config=watcher.php   # same exit codes
```
