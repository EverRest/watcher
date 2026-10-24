<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class NotifyConfigTest extends TestCase
{
    private const SENTINEL = 'SENTINEL-secret-value-9f3a';
    private const TOKEN = '123456:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789';
    private const HOOK = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-123';

    /**
     * @param array<string, mixed> $notify
     * @return array<string, mixed>
     */
    private static function config(array $notify): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'http://loki']],
            'analysis' => [
                'api_key' => 'sk-secret-key',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => $notify,
        ];
    }

    public function testSlackIsTheDefaultDriver(): void
    {
        $c = WatchConfig::fromArray(self::config(['digest' => ['webhook' => 'https://hooks.example/x']]));

        self::assertSame('slack', $c->notify['digest']['driver']);
        self::assertSame('slack', $c->destination('digest')->driver());
        self::assertSame('https://hooks.example/x', $c->destination('digest')->webhook());
    }

    public function testTelegramAndDiscordDestinations(): void
    {
        $c = WatchConfig::fromArray(self::config([
            'digest' => ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => -1001234567890, 'thread_id' => 7],
            'notice' => ['driver' => 'discord', 'webhook' => self::HOOK],
        ]));

        $d = $c->destination('digest');
        self::assertSame('telegram', $d->driver());
        self::assertSame(self::TOKEN, $d->botToken());
        self::assertSame('-1001234567890', $d->chatId());
        self::assertSame(7, $d->threadId());
        self::assertSame('discord', $c->destination('notice')->driver());
        self::assertSame(self::HOOK, $c->destination('notice')->webhook());
    }

    public function testSecretsCoverEveryCredentialInTheConfig(): void
    {
        $raw = self::config(['digest' => ['webhook' => self::HOOK]]);
        $raw['source'] = ['driver' => 'loki', 'loki' => ['url' => 'http://loki', 'auth' => ['type' => 'basic', 'user' => 'bob', 'password' => 'loki-pass-1']]];
        $raw['state'] = ['driver' => 'pdo', 'pdo' => ['dsn' => 'pgsql:host=db', 'username' => 'u', 'password' => 'pdo-pass-1']];
        $c = WatchConfig::fromArray($raw);

        self::assertEqualsCanonicalizing(['sk-secret-key', self::HOOK, 'loki-pass-1', 'pdo-pass-1', 'pgsql:host=db'], $c->secrets());

        $raw['source'] = ['driver' => 'datadog', 'datadog' => ['api_key' => 'dd-api-1', 'app_key' => 'dd-app-1']];
        $raw['state'] = ['driver' => 'redis', 'redis' => ['password' => 'redis-pass-1']];
        self::assertEqualsCanonicalizing(['sk-secret-key', self::HOOK, 'dd-api-1', 'dd-app-1', 'redis-pass-1'], WatchConfig::fromArray($raw)->secrets());
    }

    public function testNoticeFallsBackToDigest(): void
    {
        $c = WatchConfig::fromArray(self::config(['digest' => ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '@my_channel']]));

        self::assertTrue($c->destination('notice')->sameAs($c->destination('digest')));
        self::assertNull($c->destination('notice')->threadId());
    }

    public function testNotifySecretsListsEverySecretDigestFirst(): void
    {
        $c = WatchConfig::fromArray(self::config([
            'digest' => ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123'],
            'notice' => ['driver' => 'discord', 'webhook' => self::HOOK],
        ]));

        self::assertSame([self::TOKEN, '-100123', self::HOOK], $c->notifySecrets());
    }

    public function testDestinationFromBuildsAValidatedEntry(): void
    {
        $d = WatchConfig::destinationFrom(['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123']);

        self::assertSame('telegram', $d->driver());
        self::assertSame('slack', WatchConfig::destinationFrom(['driver' => 'slack', 'webhook' => 'https://h.example/x'])->driver());
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalid(): array
    {
        $t = ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123'];

        return [
            'not a map' => [null, 'notify.digest.webhook is required'],
            'driver not string' => [['driver' => 5, 'webhook' => 'https://h.example/x'], 'notify.digest.driver must be a string'],
            'driver unknown' => [['driver' => 'teams', 'webhook' => 'https://h.example/x'], 'notify.digest.driver must be one of slack, telegram, discord'],
            'slack no webhook' => [['driver' => 'slack'], 'notify.digest.webhook is required'],
            'discord no webhook' => [['driver' => 'discord'], 'notify.digest.webhook is required'],
            'telegram no token' => [['driver' => 'telegram', 'chat_id' => '1'], 'notify.digest.bot_token is required'],
            'telegram empty token' => [['bot_token' => ''] + $t, 'notify.digest.bot_token is required'],
            'telegram no chat' => [['driver' => 'telegram', 'bot_token' => self::TOKEN], 'notify.digest.chat_id is required'],
            'chat id wrong type' => [['chat_id' => ['x']] + $t, 'notify.digest.chat_id must be an integer or a string'],
            'thread zero' => [['thread_id' => 0] + $t, 'notify.digest.thread_id must be a positive integer'],
            'thread string' => [['thread_id' => '7'] + $t, 'notify.digest.thread_id must be a positive integer'],
            'webhook on telegram' => [$t + ['webhook' => self::SENTINEL], 'notify.digest.webhook is not valid for driver telegram'],
            'token on discord' => [['driver' => 'discord', 'webhook' => self::HOOK, 'bot_token' => self::SENTINEL], 'notify.digest.bot_token is not valid for driver discord'],
            'token on default slack' => [['webhook' => 'https://h.example/x', 'thread_id' => 3], 'notify.digest.thread_id is not valid for driver slack'],
            'unknown key' => [$t + ['bot_tokn' => self::SENTINEL], 'Unknown configuration key: notify.digest.bot_tokn'],
        ];
    }

    /** @param mixed $entry */
    #[DataProvider('invalid')]
    public function testValidationMessageIsExactAndNeverCarriesAValue(mixed $entry, string $message): void
    {
        foreach (['digest', 'notice'] as $kind) {
            if ($entry === null && $kind === 'notice') {
                continue; // null notice means "same as digest"
            }
            $notify = $kind === 'digest' ? ['digest' => $entry] : ['digest' => ['webhook' => 'https://h.example/x'], 'notice' => $entry];
            try {
                WatchConfig::fromArray(self::config($notify));
                self::fail('expected InvalidConfig');
            } catch (InvalidConfig $e) {
                self::assertSame(str_replace('notify.digest', 'notify.' . $kind, $message), $e->getMessage());
                self::assertStringNotContainsString(self::SENTINEL, $e->getMessage());
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            }
        }
    }
}
