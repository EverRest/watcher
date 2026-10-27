<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use DateTimeImmutable;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\KeyCatalog;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\FleetConfig;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Watcher;

/**
 * No dump of the configuration, of the objects built from it, or of a fleet holds a credential. Every secret leaf of the catalog gets
 * its own fake value, built from parts so no token-shaped literal sits in this file. A new secret key fails here until the config
 * carries it (the presence check) and the dump shows none of it (the dump checks).
 */
final class SecretDumpTest extends TestCase
{
    /** @return iterable<string, array{string}> one row per secret leaf of the catalog; a map-typed secret is proven by its leaves */
    public static function secretPaths(): iterable
    {
        foreach (KeyCatalog::paths() as $path) {
            $entry = KeyCatalog::find($path);
            if ($entry !== null && $entry['secret'] && $entry['type'] !== 'map') {
                yield $path => [$path];
            }
        }
    }

    /** The fake credential of one catalog key: only the leaf of that key carries it, every other secret leaf a placeholder. */
    private static function marker(string $path): string
    {
        return implode('-', ['fixture', 'secret', str_replace('.', '-', $path)]);
    }

    /** @return array<string, mixed> a valid configuration in which the marker of $path sits at that key */
    private static function configWith(string $path): array
    {
        $own = static fn (string $key): string => $key === $path ? self::marker($path) : 'placeholder';
        $c = [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid', 'auth' => ['type' => 'bearer', 'token' => $own('source.loki.auth.token')]]],
            'analysis' => ['api_key' => $own('analysis.api_key'), 'api_keys' => ['anthropic' => $own('analysis.api_keys.anthropic'), 'openai' => $own('analysis.api_keys.openai'), 'gemini' => $own('analysis.api_keys.gemini')], 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/watcher-secret-dump']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/' . $own('notify.digest.webhook')]],
        ];
        $m = self::marker($path);

        return match (true) {
            $path === 'source.loki.auth.password' => self::with($c, ['source', 'loki', 'auth'], ['type' => 'basic', 'user' => 'u', 'password' => $m]),
            str_starts_with($path, 'source.datadog.') => self::with($c, ['source'], ['driver' => 'datadog', 'datadog' => ['site' => 'datadoghq.com', 'api_key' => $own('source.datadog.api_key'), 'app_key' => $own('source.datadog.app_key')]]),
            $path === 'notify.digest.bot_token' => self::with($c, ['notify', 'digest'], ['driver' => 'telegram', 'bot_token' => '123456:' . $m, 'chat_id' => '42']),
            str_starts_with($path, 'state.redis.') => self::with($c, ['state'], ['driver' => 'redis', 'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => $m]]),
            str_starts_with($path, 'state.pdo.') => self::with($c, ['state'], ['driver' => 'pdo', 'pdo' => ['dsn' => 'pgsql:host=db.invalid;password=' . $own('state.pdo.dsn'), 'username' => $own('state.pdo.username'), 'password' => $own('state.pdo.password')]]),
            $path === 'observe.http.auth_token' => self::with($c, ['observe'], ['http' => ['enabled' => true, 'url' => 'https://obs.invalid/ingest', 'auth_token' => $m]]),
            $path === 'sentry.auth_token' => self::with($c, ['sentry'], ['enabled' => true, 'base_url' => 'https://sentry.invalid', 'organization' => 'acme', 'project' => 'api', 'auth_token' => $m]),
            str_starts_with($path, 'api_context.') => self::with($c, ['api_context'], ['enabled' => true, 'source' => 'https://docs.invalid/openapi.json?k=' . $own('api_context.source'), 'auth_token' => $own('api_context.auth_token')]),
            default => $c,
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $at
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function with(array $config, array $at, array $value): array
    {
        $key = array_shift($at);
        if ($at === []) {
            $config[$key] = $value;

            return $config;
        }
        $config[$key] = self::with(is_array($config[$key] ?? null) ? $config[$key] : [], $at, $value);

        return $config;
    }

    /** @return list<string> what var_dump, print_r, var_export, json_encode and serialize show of the object */
    private static function dumps(object $o): array
    {
        ob_start();
        var_dump($o);
        $dumps = [(string) ob_get_clean(), print_r($o, true), var_export($o, true), (string) json_encode($o)];
        try {
            $dumps[] = serialize($o);
        } catch (\Throwable $e) {
            $dumps[] = $e->getMessage(); // a holder that refuses to serialize shows nothing either
        }

        return $dumps;
    }

    #[DataProvider('secretPaths')]
    public function testConfigDumpsHoldNoSecretOfThatKey(string $path): void
    {
        self::assertStringContainsString(self::marker($path), serialize(self::configWith($path)), 'the config carries the key: add it to configWith()');

        foreach (self::dumps(WatchConfig::fromArray(self::configWith($path))) as $dump) {
            self::assertStringNotContainsString(self::marker($path), $dump, $path);
        }
    }

    #[DataProvider('secretPaths')]
    public function testWatcherAndFactoryResultsHoldNoSecretOfThatKey(string $path): void
    {
        $config = WatchConfig::fromArray(self::configWith($path));
        $client = new Client();
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-26T06:00:00Z'));
        $built = [
            'watcher' => Watcher::fromConfig(self::configWith($path)),
            'source' => Factory::source($config, $client, $clock),
            'state' => Factory::state($config, $clock),
            'analyzer' => Factory::analyzer($config),
            'model catalog' => Factory::modelCatalog($config, $client),
            'metric source' => Factory::metricSource($config, $client),
            'issue source' => Factory::issueSource($config, $client, $clock),
            'state inspector' => Factory::stateInspector(self::configWith($path), $clock),
        ];
        foreach ($built as $name => $object) {
            foreach ($object === null ? [] : self::dumps($object) as $dump) {
                self::assertStringNotContainsString(self::marker($path), $dump, $name . ' ' . $path);
            }
        }
    }

    #[DataProvider('secretPaths')]
    public function testFleetDumpsHoldNoSecretOfThatKey(string $path): void
    {
        $service = ['service' => 'a'] + self::configWith($path);
        unset($service['notify']);
        $fleet = FleetConfig::fromArray(['fleet' => 'platform', 'notify' => self::configWith($path)['notify'], 'services' => [$service]]);

        foreach (self::dumps($fleet) as $dump) {
            self::assertStringNotContainsString(self::marker($path), $dump, $path);
        }
    }
}
