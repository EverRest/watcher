<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use Watcher\Config\WatchConfig;
use Watcher\Hygiene\Hygiene;
use Watcher\Level;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Shared wiring for the hygiene suites: fakes only, no network. The clock stands at the end of the window the fixtures fall in. */
final class HygieneHarness
{
    public readonly FrozenClock $clock;
    public readonly InMemoryStateStore $state;
    public readonly FakeNotifier $notifier;

    /** @param array<string, mixed> $configPatch */
    public function __construct(private readonly array $configPatch = [])
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $this->state = new InMemoryStateStore($this->clock);
        $this->notifier = new FakeNotifier();
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $config = array_replace_recursive([
            'service' => 'svc',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => [
                'driver' => 'loki',
                'loki' => ['url' => 'https://loki.example'],
                'stream_tags' => ['app' => 'svc', 'env' => 'stg'],
            ],
            'analysis' => [
                'api_keys' => ['gemini' => 'k'],
                'models' => ['triage' => 'm-t', 'analyze' => 'm-a', 'escalate' => 'm-e'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent']],
            'notify' => [
                'digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/digest'],
                'notice' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/notice'],
            ],
            'repo' => ['provider' => 'gitlab', 'web_url' => 'https://git.invalid/x', 'path_map' => ['/var/www/html/' => '']],
            'hygiene' => ['enabled' => true, 'site_allow_dirs' => ['auth', 'audit', 'orders']],
        ], $this->configPatch);
        // A destination in the patch replaces the default wholesale (a Telegram entry must not inherit the Slack webhook).
        foreach (['digest', 'notice'] as $kind) {
            if (is_array($this->configPatch['notify'][$kind] ?? null)) {
                $config['notify'][$kind] = $this->configPatch['notify'][$kind];
            }
        }

        return $config;
    }

    public function hygiene(LogSource $source, ?\Watcher\Schedule\RunGuard $guard = null): Hygiene
    {
        return new Hygiene(WatchConfig::fromArray($this->config()), $source, $this->state, $this->notifier, $this->clock, $guard);
    }

    /** @param list<LogEvent> $events */
    public static function source(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** @return list<LogEvent> the events of a privacy fixture file */
    public static function fixture(string $name): array
    {
        $parser = new LineParser();
        $events = [];
        foreach (file(__DIR__ . '/../Fixtures/privacy/' . $name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }

        return $events;
    }

    /** @param array<string, scalar|null> $fields */
    public static function event(Level $level, string $message, array $fields = [], string $time = '2026-10-07T12:00:00+00:00', ?string $type = null): LogEvent
    {
        return new LogEvent(new DateTimeImmutable($time), $level, $message, $type, [], $fields, []);
    }
}
