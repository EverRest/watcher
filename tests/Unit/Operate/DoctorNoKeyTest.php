<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Operate\Doctor;
use Watcher\Operate\ModelCatalog;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Spec 019 (FR-001, FR-002): a provider in use without a key loads, and `check` says AI analysis is off. */
final class DoctorNoKeyTest extends TestCase
{
    /** @param array<string, mixed> $analysis */
    private function config(array $analysis = []): WatchConfig
    {
        return WatchConfig::fromArray([
            'service' => 'orders',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test', 'auth' => ['type' => 'bearer', 'token' => 'LOKITOKEN']], 'stream_tags' => ['app' => 'orders']],
            'analysis' => [
                'models' => ['triage' => 'm-small', 'analyze' => 'm-mid', 'escalate' => 'm-big'],
            ] + $analysis,
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => ['digest' => ['webhook' => 'https://hooks.slack.test/services/T0/B0/SECRETHOOK']],
        ]);
    }

    /** One provider without a key does not hide the model check of the providers that have one. */
    public function testAMissingKeyDoesNotHideTheModelCheck(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-15T09:00:00Z'));
        $catalog = new class () implements ModelCatalog {
            public function probe(): array
            {
                return ['x-api-key' => 200];
            }

            public function modelIds(): array
            {
                return ['m-mid'];
            }
        };
        $config = $this->config(['providers' => ['triage' => 'openai'], 'api_keys' => ['gemini' => 'sk-gemini']]);

        $r = (new Doctor($config, new FakeLogSource(['error' => 3]), new InMemoryStateStore($clock), $catalog, new FakeNotifier(), $clock))->run();

        $byName = [];
        foreach ($r->results as $c) {
            $byName[$c->name] = $c;
        }
        self::assertSame('warn', $byName['provider_auth']->status);
        self::assertSame('no key for openai: AI analysis off', $byName['provider_auth']->reason);
        self::assertNotSame('skip', $byName['models']->status, 'the models check still runs');
    }

    public function testMissingKeyLoadsAndCheckWarnsWithoutFailing(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-11-15T09:00:00Z'));
        $doctor = new Doctor($this->config(), new FakeLogSource(['error' => 3]), new InMemoryStateStore($clock), null, new FakeNotifier(), $clock);

        $r = $doctor->run();

        $byName = [];
        foreach ($r->results as $c) {
            $byName[$c->name] = $c;
        }
        self::assertSame('warn', $byName['provider_auth']->status);
        self::assertSame('no key for gemini: AI analysis off', $byName['provider_auth']->reason);
        self::assertTrue($r->ok());
        self::assertSame(0, $r->exitCode());
    }
}
