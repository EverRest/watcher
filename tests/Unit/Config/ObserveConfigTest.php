<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class ObserveConfigTest extends TestCase
{
    private const URL = 'https://ingest.example.com/path-SECRETPATH';
    private const TOKEN = 'tok-observe-SECRET1';

    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.com']],
            'analysis' => [
                'api_key' => 'sk-secret-key',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
        ];
    }

    /**
     * @param array<string, mixed> $observe
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function with(array $observe, array $patch = []): array
    {
        return array_replace_recursive(self::base(), ['observe' => $observe], $patch);
    }

    public function testAllSinksOffByDefault(): void
    {
        $o = WatchConfig::fromArray(self::base())->observe;

        self::assertFalse($o->anyEnabled());
        self::assertSame(20, $o->topGroups);
        foreach ([$o->log, $o->file, $o->http, $o->prometheus] as $sink) {
            self::assertFalse($sink['enabled']);
        }
    }

    public function testIncludeTextDefaults(): void
    {
        $o = WatchConfig::fromArray(self::with(['log' => ['enabled' => true], 'file' => ['enabled' => true, 'path' => '/var/log/w/e.jsonl'], 'http' => ['enabled' => true, 'url' => self::URL], 'prometheus' => ['enabled' => true, 'dir' => '/var/lib/te']]))->observe;

        self::assertFalse($o->log['include_text'], 'owner decision: no sink carries text unless the operator opts in');
        self::assertFalse($o->file['include_text']);
        self::assertFalse($o->http['include_text']);
        self::assertSame('stderr', $o->log['stream']);
        self::assertSame(['size', 10485760, 10, 30], [$o->file['rotate'], $o->file['max_bytes'], $o->file['max_files'], $o->file['retention_days']]);
        self::assertSame([100, 5, 'Authorization', 'Bearer'], [$o->http['batch_size'], $o->http['timeout'], $o->http['auth_header'], $o->http['auth_scheme']]);
        self::assertSame(['watcher_svc', 0644], [$o->prometheus['name'], $o->prometheus['file_mode']]);
    }

    public function testPrometheusNameIncludesTheEnvironment(): void
    {
        $o = WatchConfig::fromArray(self::with(['prometheus' => ['enabled' => true, 'dir' => '/var/lib/te']], ['service' => 'my-api', 'environment' => 'prod.eu']))->observe;

        self::assertSame('watcher_my_api_prod_eu', $o->prometheus['name']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'observe not a map' => [['__raw' => 'x'], 'observe must be a map'],
            'unknown sink' => [['syslog' => ['enabled' => true]], 'Unknown configuration key: observe.syslog'],
            'unknown key' => [['log' => ['colour' => 'red']], 'Unknown configuration key: observe.log.colour'],
            'string flag' => [['log' => ['enabled' => 'false']], 'observe.log.enabled must be true or false'],
            'otlp' => [['otlp' => ['enabled' => true]], 'observe.otlp is not available yet'],
            'top_groups range' => [['top_groups' => 101], 'observe.top_groups must be an integer between 0 and 100'],
            'stream' => [['log' => ['stream' => 'file']], 'observe.log.stream must be stderr or stdout'],
            'file path missing' => [['file' => ['enabled' => true]], 'observe.file.path is required'],
            'file path relative' => [['file' => ['enabled' => true, 'path' => 'rel/e.jsonl']], 'observe.file.path must be an absolute path without ..'],
            'file path dotdot' => [['file' => ['enabled' => true, 'path' => '/var/../etc/e.jsonl']], 'observe.file.path must be an absolute path without ..'],
            'file rotate' => [['file' => ['rotate' => 'weekly']], 'observe.file.rotate must be size or daily'],
            'file max_bytes' => [['file' => ['max_bytes' => 10]], 'observe.file.max_bytes must be an integer between 1048576 and 1073741824'],
            'file max_files' => [['file' => ['max_files' => 0]], 'observe.file.max_files must be an integer between 1 and 100'],
            'file retention' => [['file' => ['retention_days' => 366]], 'observe.file.retention_days must be an integer between 1 and 365'],
            'http url missing' => [['http' => ['enabled' => true]], 'observe.http.url is required'],
            'http url http' => [['http' => ['enabled' => true, 'url' => 'http://ingest.example.com/x']], 'observe.http.url must be an https URL with a host'],
            'http url creds' => [['http' => ['enabled' => true, 'url' => 'https://u:p@ingest.example.com/x']], 'observe.http.url must not contain credentials'],
            'http url metadata' => [['http' => ['enabled' => true, 'url' => 'https://169.254.169.254/x']], 'observe.http.url host is not allowed'],
            'http url private' => [['http' => ['enabled' => true, 'url' => 'https://10.0.0.5/x']], 'observe.http.url host is not allowed'],
            'http url allowed_hosts' => [['http' => ['enabled' => true, 'url' => self::URL, 'allowed_hosts' => ['other.example.com']]], 'observe.http.url host is not in observe.http.allowed_hosts'],
            'http url is the log source' => [['http' => ['enabled' => true, 'url' => 'https://loki.example.com/push']], 'observe.http.url must not point at the log source'],
            'http auth_header' => [['http' => ['auth_header' => 'Bad Header']], 'observe.http.auth_header must be a header name'],
            'http token short' => [['http' => ['auth_token' => 'short']], 'observe.http.auth_token must be at least 8 characters'],
            'http batch' => [['http' => ['batch_size' => 1001]], 'observe.http.batch_size must be an integer between 1 and 1000'],
            'http timeout' => [['http' => ['timeout' => 31]], 'observe.http.timeout must be an integer between 1 and 30'],
            'http include_text' => [['http' => ['include_text' => 'yes']], 'observe.http.include_text must be true or false'],
            'prometheus dir missing' => [['prometheus' => ['enabled' => true]], 'observe.prometheus.dir is required'],
            'prometheus dir relative' => [['prometheus' => ['dir' => 'rel']], 'observe.prometheus.dir is invalid'],
            'prometheus name' => [['prometheus' => ['name' => 'Bad-Name']], 'observe.prometheus.name is invalid'],
            'prometheus mode' => [['prometheus' => ['file_mode' => '0777']], 'observe.prometheus.file_mode is invalid'],
            'prometheus mode not octal' => [['prometheus' => ['file_mode' => 'rw']], 'observe.prometheus.file_mode is invalid'],
        ];
    }

    /**
     * @param array<string, mixed> $observe
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesNameTheKeyPathOnly(array $observe, string $message): void
    {
        $config = self::base();
        $config['observe'] = $observe['__raw'] ?? $observe;
        try {
            WatchConfig::fromArray($config);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame($message, $e->getMessage());
            self::assertStringNotContainsString('SECRETPATH', $e->getMessage());
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badNames(): array
    {
        return [
            'space in service' => [['service' => 'orders api'], 'service must match [A-Za-z0-9][A-Za-z0-9._@-]{0,63} when an observe sink is enabled'],
            'service of 65 characters' => [['service' => str_repeat('a', 65)], 'service must match [A-Za-z0-9][A-Za-z0-9._@-]{0,63} when an observe sink is enabled'],
            'unicode service' => [['service' => 'ordérs'], 'service must match [A-Za-z0-9][A-Za-z0-9._@-]{0,63} when an observe sink is enabled'],
            'environment of 33 characters' => [['environment' => str_repeat('e', 33)], 'environment must match [A-Za-z0-9][A-Za-z0-9._-]{0,31} when an observe sink is enabled'],
        ];
    }

    /**
     * @param array<string, mixed> $patch
     */
    #[DataProvider('badNames')]
    public function testNamesTheSinksWouldNullAreRejectedAtLoadNotSilentlyDropped(array $patch, string $message): void
    {
        $config = array_replace(self::with(['log' => ['enabled' => true]]), $patch);
        try {
            WatchConfig::fromArray($config);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testTheSameNamesStayValidWhenNoSinkIsEnabled(): void
    {
        $c = WatchConfig::fromArray(array_replace(self::base(), ['service' => 'orders api', 'environment' => str_repeat('e', 40)]));

        self::assertSame('orders api', $c->service);
    }

    public function testNamesAtTheLimitAreAccepted(): void
    {
        $c = WatchConfig::fromArray(array_replace(self::with(['log' => ['enabled' => true]]), ['service' => 'a' . str_repeat('b', 63), 'environment' => 'e' . str_repeat('f', 31)]));

        self::assertSame(64, strlen($c->service));
    }

    public function testASubKeyOfADisabledSinkIsStillValidated(): void
    {
        $this->expectException(InvalidConfig::class);
        WatchConfig::fromArray(self::with(['file' => ['enabled' => false, 'rotate' => 'weekly']]));
    }

    public function testInsecureHttpNeedsTheFlagAndATrustedNetworkHost(): void
    {
        $ok = WatchConfig::fromArray(self::with(['http' => ['enabled' => true, 'url' => 'http://collector.observability.svc/ingest', 'allow_insecure' => true, 'allow_private_network' => true]]));
        self::assertTrue($ok->observe->http['enabled']);

        $this->expectExceptionMessage('observe.http.url must be an https URL with a host');
        WatchConfig::fromArray(self::with(['http' => ['enabled' => true, 'url' => 'http://ingest.example.com/x', 'allow_insecure' => true]]));
    }

    public function testNoneNeedsASink(): void
    {
        $config = array_replace(self::base(), ['notify' => ['digest' => ['driver' => 'none']]]);
        try {
            WatchConfig::fromArray($config);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame('notify.digest.driver none needs at least one enabled observe sink', $e->getMessage());
        }

        $config['observe'] = ['log' => ['enabled' => true]];
        $c = WatchConfig::fromArray($config);
        self::assertSame('none', $c->destination('digest')->driver());
        self::assertSame('none', $c->destination('notice')->driver(), 'notice inherits the digest destination');
    }

    public function testNoneRejectsOtherNotifyKeys(): void
    {
        $config = array_replace(self::base(), ['notify' => ['digest' => ['driver' => 'none', 'webhook' => 'https://hooks.example/x']], 'observe' => ['log' => ['enabled' => true]]]);

        $this->expectExceptionMessage('notify.digest.webhook is not valid for driver none');
        WatchConfig::fromArray($config);
    }

    public function testNoneIgnoresUnsetKeysAConfigFileListsForOtherDrivers(): void
    {
        // Shipped configs always list `webhook => env(...)`: unset (null, false from getenv, '') must not make `none` unusable.
        foreach ([null, false, ''] as $unset) {
            $config = array_replace(self::base(), ['notify' => ['digest' => ['driver' => 'none', 'webhook' => $unset]], 'observe' => ['log' => ['enabled' => true]]]);

            self::assertSame('none', WatchConfig::fromArray($config)->destination('digest')->driver());
        }
    }

    public function testSecretsCoverTheSinkCredentialsLongestFirst(): void
    {
        $c = WatchConfig::fromArray(self::with(['http' => ['enabled' => true, 'url' => self::URL, 'auth_token' => self::TOKEN]]));
        $secrets = $c->secrets();

        self::assertContains(self::URL, $secrets);
        self::assertContains(self::TOKEN, $secrets);
        $lengths = array_map('strlen', $secrets);
        $sorted = $lengths;
        rsort($sorted);
        self::assertSame($sorted, $lengths, 'longest secret first, so a secret that contains another is masked whole');
    }

    public function testTheDumpOfAConfigHoldsNoSinkCredential(): void
    {
        $c = WatchConfig::fromArray(self::with(['http' => ['enabled' => true, 'url' => self::URL, 'auth_token' => self::TOKEN]]));
        $dumps = [print_r($c, true), var_export($c, true), (string) json_encode($c), print_r($c->observe, true), (string) json_encode($c->observe)];

        foreach ($dumps as $dump) {
            self::assertStringNotContainsString('SECRETPATH', $dump);
            self::assertStringNotContainsString(self::TOKEN, $dump);
        }
    }
}
