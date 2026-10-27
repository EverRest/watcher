<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class ApiContextConfigTest extends TestCase
{
    private const TOKEN = 'tok-openapi-SECRET9';
    private const URL = 'https://docs.example.com/openapi.json?key=URLSECRET7';

    /**
     * @param array<string, mixed> $api
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    private static function config(array $api, array $patch = []): array
    {
        return array_replace_recursive([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.com']],
            'analysis' => [
                'api_key' => 'sk-secret-key',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w'], 'retention' => 604800],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
            'api_context' => $api,
        ], $patch);
    }

    public function testAbsentSectionIsNull(): void
    {
        $c = self::config([]);
        unset($c['api_context']);

        self::assertNull(WatchConfig::fromArray($c)->apiContext);
    }

    public function testDefaults(): void
    {
        $a = WatchConfig::plain(WatchConfig::fromArray(self::config(['source' => self::URL]))->apiContext);

        self::assertSame([
            'enabled' => true, 'source' => self::URL, 'auth_token' => null, 'ttl' => 86400, 'max_endpoints' => 5, 'summary_cap' => 80,
            'max_bytes' => 1048576, 'base_path' => null, 'ignore_prefixes' => [], 'allow_insecure' => false, 'allow_private_network' => false, 'allowed_roots' => [],
        ], $a);
    }

    public function testDefaultTtlNeverExceedsTheRetention(): void
    {
        $a = WatchConfig::plain(WatchConfig::fromArray(self::config(['source' => self::URL], ['state' => ['retention' => 3600], 'baseline' => ['max_age' => 'PT1H']]))->apiContext);

        self::assertSame(3600, $a['ttl'] ?? null);
    }

    public function testDisabledStillValidatesAndNeedsNoSource(): void
    {
        self::assertSame(false, WatchConfig::fromArray(self::config(['enabled' => false]))->apiContext['enabled'] ?? null);
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('api_context.max_endpoints');
        WatchConfig::fromArray(self::config(['enabled' => false, 'max_endpoints' => 99]));
    }

    public function testApiContextIsNotAFeatureFlag(): void
    {
        self::assertNotContains('api_context', WatchConfig::FLAGS);
        $this->expectException(\LogicException::class);
        WatchConfig::fromArray(self::config(['source' => self::URL]))->on('api_context');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function bad(): array
    {
        $ok = ['source' => self::URL];

        return [
            'not a map' => [['__raw' => 'x'], 'api_context must be a map'],
            'missing source' => [['enabled' => true], 'api_context.source is required'],
            'empty source' => [['source' => ''], 'api_context.source is required'],
            'enabled not bool' => [$ok + ['enabled' => 'false'], 'api_context.enabled must be true or false'],
            'ftp scheme' => [['source' => 'ftp://x/y.json'], 'api_context.source must be an https URL or an absolute file path'],
            'php wrapper' => [['source' => 'php://filter/x'], 'api_context.source must be an https URL or an absolute file path'],
            'relative path' => [['source' => 'specs/openapi.json'], 'api_context.source must be an https URL or an absolute file path'],
            'yaml' => [['source' => '/srv/openapi.yaml'], 'api_context.source must be a JSON document (YAML is not supported yet)'],
            'yml url' => [['source' => 'https://docs.example.com/o.yml'], 'api_context.source must be a JSON document (YAML is not supported yet)'],
            'plain http' => [['source' => 'http://docs.example.com/o.json'], 'api_context.source'],
            'http loopback with only allow_insecure' => [['source' => 'http://127.0.0.1/o.json', 'allow_insecure' => true], 'api_context.source'],
            'http loopback with only allow_private_network' => [['source' => 'http://127.0.0.1/o.json', 'allow_private_network' => true], 'api_context.source'],
            'metadata even with both' => [['source' => 'http://169.254.169.254/o.json', 'allow_insecure' => true, 'allow_private_network' => true], 'api_context.source'],
            'private host over https without the flag' => [['source' => 'https://10.0.0.5/o.json'], 'api_context.source'],
            'userinfo' => [['source' => 'https://u:p@docs.example.com/o.json'], 'api_context.source'],
            'token not string' => [$ok + ['auth_token' => 12], 'api_context.auth_token must be a non-empty string'],
            'ttl format' => [$ok + ['ttl' => '24h'], 'api_context.ttl must be a duration between 1 second and state.retention'],
            'ttl zero' => [$ok + ['ttl' => 'PT0S'], 'api_context.ttl must be a duration between 1 second and state.retention'],
            'ttl over retention' => [$ok + ['ttl' => 'P30D'], 'api_context.ttl must be a duration between 1 second and state.retention'],
            'endpoints 0' => [$ok + ['max_endpoints' => 0], 'api_context.max_endpoints must be an integer between 1 and 20'],
            'endpoints 21' => [$ok + ['max_endpoints' => 21], 'api_context.max_endpoints must be an integer between 1 and 20'],
            'endpoints string' => [$ok + ['max_endpoints' => '5'], 'api_context.max_endpoints must be an integer between 1 and 20'],
            'cap 19' => [$ok + ['summary_cap' => 19], 'api_context.summary_cap must be an integer between 20 and 200'],
            'cap 201' => [$ok + ['summary_cap' => 201], 'api_context.summary_cap must be an integer between 20 and 200'],
            'bytes small' => [$ok + ['max_bytes' => 65535], 'api_context.max_bytes must be an integer between 65536 and 2097152'],
            'bytes big' => [$ok + ['max_bytes' => 2097153], 'api_context.max_bytes must be an integer between 65536 and 2097152'],
            'base path' => [$ok + ['base_path' => 'api'], 'api_context.base_path must be a path starting with /'],
            'base path dots' => [$ok + ['base_path' => '/a/../b'], 'api_context.base_path must be a path starting with /'],
            'prefixes not list' => [$ok + ['ignore_prefixes' => ['a' => '/x']], 'api_context.ignore_prefixes must be a list of at most 10 paths starting with /'],
            'prefix no slash' => [$ok + ['ignore_prefixes' => ['v1']], 'api_context.ignore_prefixes must be a list of at most 10 paths starting with /'],
            'eleven prefixes' => [$ok + ['ignore_prefixes' => array_fill(0, 11, '/v1')], 'api_context.ignore_prefixes must be a list of at most 10 paths starting with /'],
            'insecure not bool' => [$ok + ['allow_insecure' => 1], 'api_context.allow_insecure must be true or false'],
            'private not bool' => [$ok + ['allow_private_network' => 'yes'], 'api_context.allow_private_network must be true or false'],
            'roots relative' => [$ok + ['allowed_roots' => ['specs']], 'api_context.allowed_roots must be a list of at most 10 absolute paths'],
            'roots dotdot' => [$ok + ['allowed_roots' => ['/srv/../etc']], 'api_context.allowed_roots must be a list of at most 10 absolute paths'],
            'unknown key' => [$ok + ['timeout' => 5], 'Unknown configuration key: api_context.timeout'],
        ];
    }

    /** @param array<string, mixed> $api */
    #[DataProvider('bad')]
    public function testEveryRuleFailsEarlyNamingTheKeyPathAndNoValue(array $api, string $message): void
    {
        $cfg = self::config($api);
        if (isset($api['__raw'])) {
            $cfg['api_context'] = 'x';
        }
        try {
            WatchConfig::fromArray($cfg);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($message, $e->getMessage());
            foreach ([self::URL, 'URLSECRET7', self::TOKEN, 'u:p', '/srv/openapi', '169.254'] as $value) {
                self::assertStringNotContainsString($value, $e->getMessage());
            }
        }
    }

    public function testAcceptedSources(): void
    {
        foreach ([
            ['source' => '/srv/specs/openapi.json'],
            ['source' => 'file:/srv/specs/openapi.json'],
            ['source' => 'https://docs.example.com/o.json'],
            ['source' => 'https://10.0.0.5/o.json', 'allow_private_network' => true],
            ['source' => 'http://127.0.0.1:3000/o.json', 'allow_insecure' => true, 'allow_private_network' => true],
            ['source' => 'http://orders.default.svc:8080/o.json', 'allow_insecure' => true, 'allow_private_network' => true],
        ] as $api) {
            self::assertSame($api['source'], WatchConfig::reveal(WatchConfig::fromArray(self::config($api))->apiContext['source'] ?? null));
        }
    }

    public function testTokenAndSourceAreSecretsOnTheOneLongestFirstList(): void
    {
        $c = WatchConfig::fromArray(self::config(['source' => self::URL, 'auth_token' => self::TOKEN]));
        $secrets = $c->secrets();

        self::assertContains(self::TOKEN, $secrets);
        self::assertContains(self::URL, $secrets);
        $lengths = array_map('strlen', $secrets);
        $sorted = $lengths;
        rsort($sorted);
        self::assertSame($sorted, $lengths);
        self::assertSame($secrets, array_values(array_unique($secrets)));
    }

    public function testAFilePathIsNotASecret(): void
    {
        self::assertNotContains('/srv/openapi.json', WatchConfig::fromArray(self::config(['source' => '/srv/openapi.json']))->secrets());
    }

    public function testDumpsOfTheConfigShowNeitherTokenNorUrl(): void
    {
        $c = WatchConfig::fromArray(self::config(['source' => self::URL, 'auth_token' => self::TOKEN]));
        ob_start();
        var_dump($c);
        print_r($c);
        $dump = (string) ob_get_clean() . json_encode($c);

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString('URLSECRET7', $dump);
    }
}
