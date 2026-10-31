<?php

declare(strict_types=1);

namespace Watcher\Health;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Watcher\Config\Build\StateFactory;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Notify\Destination;
use Watcher\Notify\Notifier;
use Watcher\Notify\NoticeRenderer;
use Watcher\Schedule\SystemClock;
use Watcher\State\Keys;
use Watcher\State\Redis\RedisClient;
use Watcher\State\StateStore;

/**
 * Hourly check of the health snapshot a host app keeps in its own Redis: one JSON object, service => {success, version,
 * checked_at}. Alerts at once when a service's version changes, and keeps the last {success, version, checked_at} per
 * service for the next comparison. Response bodies are never read or stored. Every failure stays in this command.
 */
final class HealthCheck
{
    /** One check per hour: the lock is taken for the hour and expires before the next one. */
    private const LOCK_TTL = 3300;

    private const UNKNOWN_VERSION = 'Version not defined';

    public function __construct(
        private readonly RedisClient $source,
        private readonly string $sourceKey,
        private readonly StateStore $state,
        private readonly Notifier $notifier,
        private readonly Destination $to,
        private readonly NoticeRenderer $renderer,
        private readonly LoggerInterface $log,
        private readonly string $service,
        private readonly string $environment,
        private readonly int $ttlSeconds,
        private readonly bool $notifyTransitions,
        private readonly ?SummarySchedule $summary = null,
    ) {
    }

    /**
     * Null when the check is off: `health_check.enabled` is not true, or the watcher's master switch is off. The section
     * is not part of WatchConfig, so it is validated here (key paths only in the messages).
     *
     * @param array<string, mixed> $config the raw watcher configuration
     *
     * @throws InvalidConfig
     */
    public static function fromConfig(#[\SensitiveParameter] array $config, ?LoggerInterface $log = null): ?self
    {
        $h = $config['health_check'] ?? [];
        if (!is_array($h)) {
            throw new InvalidConfig('health_check must be a map');
        }
        $enabled = $h['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('health_check.enabled must be true or false');
        }
        if (!$enabled || WatchConfig::masterSwitchOff($config)) {
            return null;
        }

        $src = $h['source'] ?? null;
        if (!is_array($src) || !is_string($src['host'] ?? null) || $src['host'] === '' || !is_string($src['key'] ?? null) || $src['key'] === '') {
            throw new InvalidConfig('health_check.source.host and health_check.source.key are required');
        }
        $ttlDays = $h['ttl_days'] ?? 14;
        if (!is_int($ttlDays) || $ttlDays < 1 || $ttlDays > 365) {
            throw new InvalidConfig('health_check.ttl_days must be 1..365');
        }
        $transitions = $h['notify_transitions'] ?? false;
        if (!is_bool($transitions)) {
            throw new InvalidConfig('health_check.notify_transitions must be true or false');
        }

        // Own zone for the summary, so the daily run's schedule.timezone does not move the summary hours.
        $tz = $h['timezone'] ?? 'UTC';
        if (!is_string($tz) || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidConfig('health_check.timezone must be an IANA zone name');
        }
        $summary = SummarySchedule::fromConfig($h, new \DateTimeZone($tz));

        $c = WatchConfig::fromArray(WatchConfig::withoutFleet($config));
        $log ??= new NullLogger();
        $redis = array_filter([
            'host' => $src['host'],
            'port' => $src['port'] ?? null,
            'database' => $src['database'] ?? null,
            'username' => $src['username'] ?? null,
            'password' => $src['password'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
        $secrets = [...$c->secrets(), ...(is_string($src['password'] ?? null) ? [$src['password']] : [])];

        return new self(
            source: StateFactory::redisClient($redis),
            sourceKey: $src['key'],
            state: Factory::state($c, new SystemClock(), $log),
            notifier: Factory::notifier(Factory::http(), $c->schedule['lock_ttl']),
            to: $c->destination('notice'),
            renderer: new NoticeRenderer($secrets),
            log: $log,
            service: $c->service,
            environment: $c->environment ?? 'default',
            ttlSeconds: $ttlDays * 86400,
            notifyTransitions: $transitions,
            summary: $summary,
        );
    }

    /** @return int 0 when every service was handled or this hour was already checked; 1 when the snapshot or any service failed */
    public function run(DateTimeImmutable $now): int
    {
        // Nothing is read, sent or stored on a day outside summary_days. The first allowed run compares with the last stored copy, so a change made on a weekend is sent then.
        if ($this->summary !== null && !$this->summary->allowsDay($now)) {
            $this->log->info('health: day not allowed, nothing sent');

            return 0;
        }

        try {
            // Taken for the hour and never released: an overlapping or repeated run in the same hour finds it and stops.
            if (!$this->state->acquire(Keys::lock($this->service, 'health:' . $now->format('Y-m-d\TH')), bin2hex(random_bytes(8)), self::LOCK_TTL)) {
                return 0;
            }
            $snapshot = $this->snapshot();
        } catch (Throwable $e) {
            $this->log->error('health: check not run', ['error' => $this->renderer->describe($e)]);

            return 1;
        }

        $code = 0;
        $rows = [];
        foreach ($snapshot as $name => $entry) {
            try {
                $row = $this->handle((string) $name, $entry);
                if ($row !== null) {
                    $rows[] = $row;
                }
            } catch (Throwable $e) {
                $code = 1;
                $this->log->error('health: service not stored', ['service' => (string) $name, 'error' => $this->renderer->describe($e)]);
            }
        }
        $this->summarise($now, $rows);

        return $code;
    }

    /**
     * The health summary, when the schedule says it is due: a failed send is logged and never changes the alert result. The hourly lock already makes it once per hour.
     *
     * @param list<array{name: string, version: ?string, success: bool, checked_at: string}> $rows
     */
    private function summarise(DateTimeImmutable $now, array $rows): void
    {
        if ($this->summary === null || $rows === [] || !$this->summary->due($now)) {
            return;
        }
        try {
            $this->notifier->send($this->renderer->healthSummary($this->service, $this->environment, $rows), $this->to);
        } catch (Throwable $e) {
            $this->log->error('health: summary not sent', ['error' => $this->renderer->describe($e)]);
        }
    }

    /** @return array<mixed> */
    private function snapshot(): array
    {
        $raw = $this->source->get($this->sourceKey);
        if ($raw === null) {
            throw new RuntimeException('health snapshot is missing');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('health snapshot is not a JSON object');
        }

        return $data;
    }

    /** @return array{name: string, version: ?string, success: bool, checked_at: string}|null the summary row; null for a malformed entry */
    private function handle(string $name, mixed $entry): ?array
    {
        if (!is_array($entry) || !is_bool($entry['success'] ?? null) || !is_string($entry['checked_at'] ?? null)) {
            $this->log->warning('health: entry malformed, skipped', ['service' => $name]);

            return null;
        }
        $version = self::known($entry['version'] ?? null);
        $copy = ['success' => $entry['success'], 'version' => $version, 'checked_at' => $entry['checked_at']];
        $key = Keys::healthcheck($this->service, $name);
        $prev = $this->state->get($key);

        $messages = [];
        // The first run for a service stores silently: there is nothing to compare with.
        if ($prev !== null) {
            $old = self::known($prev['version'] ?? null);
            if ($old !== null && $version !== null && $old !== $version) {
                $messages[] = $this->renderer->versionChanged($name, $this->environment, $old, $version, $copy['checked_at']);
            }
            if ($this->notifyTransitions && is_bool($prev['success'] ?? null) && $prev['success'] !== $copy['success']) {
                $messages[] = $this->renderer->healthTransition($name, $this->environment, $copy['success'], $copy['checked_at']);
            }
        }
        foreach ($messages as $message) {
            $this->notifier->send($message, $this->to);
        }
        // Stored after the sends: a failed send keeps the old copy, so the next hour compares again and retries.
        $this->state->put($key, $copy, $this->ttlSeconds);

        return ['name' => $name, ...$copy];
    }

    /** A version worth comparing: a non-empty string that is not the host's "unknown" text. Anything else is unknown and never alerts. */
    private static function known(mixed $version): ?string
    {
        return is_string($version) && $version !== '' && $version !== self::UNKNOWN_VERSION ? $version : null;
    }
}
