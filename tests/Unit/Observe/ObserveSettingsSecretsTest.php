<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Observe\HttpSink;
use Watcher\Observe\HttpTarget;
use Watcher\Observe\ObserveSettings;

/** The sink credentials must not reach a stack trace, even with zend.exception_ignore_args off (it is in tests and many hosts). */
final class ObserveSettingsSecretsTest extends TestCase
{
    private const TOKEN = 'CANARY-token-9f3a7c';
    private const URL = 'https://ingest.example.com/p/CANARY-path-5d2b';

    private string $ignoreArgs;

    protected function setUp(): void
    {
        $this->ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
    }

    /** @return array<string, mixed> */
    private static function badHttp(): array
    {
        return ['http' => ['enabled' => true, 'url' => self::URL, 'auth_token' => self::TOKEN, 'batch_size' => 0]];
    }

    private static function everything(\Throwable $e): string
    {
        $all = $e->getMessage() . "\n" . $e->getTraceAsString();
        foreach ($e->getTrace() as $frame) {
            $all .= "\n" . var_export($frame['args'] ?? [], true);
        }

        return $all;
    }

    private function assertClean(string $text): void
    {
        self::assertStringNotContainsString('CANARY', $text);
    }

    public function testAThrowInsideSettingsParsingCarriesNoCredential(): void
    {
        try {
            ObserveSettings::fromArray(self::badHttp(), 'svc', null);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame('observe.http.batch_size must be an integer between 1 and 1000', $e->getMessage());
            $this->assertClean(self::everything($e));
        }
    }

    public function testAThrowFromTheUrlRulesCarriesNoCredential(): void
    {
        try {
            ObserveSettings::fromArray(['http' => ['enabled' => true, 'url' => 'http://CANARY-host.example.com/p/CANARY', 'auth_token' => self::TOKEN]], 'svc', null);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            $this->assertClean(self::everything($e));
        }
    }

    public function testAThrowThroughWatchConfigCarriesNoSinkCredential(): void
    {
        $config = [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.com']],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], 'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]]],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            'observe' => self::badHttp(),
        ];
        try {
            WatchConfig::fromArray($config);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            $this->assertClean(self::everything($e));
        }
    }

    public function testNoDumpFormatOfTheSettingsOrTheTargetHoldsACredential(): void
    {
        $s = ObserveSettings::fromArray(['http' => ['enabled' => true, 'url' => self::URL, 'auth_token' => self::TOKEN]], 'svc', null);
        $t = $s->httpTarget();
        self::assertInstanceOf(HttpTarget::class, $t);
        $dumps = [];
        foreach ([$s, $t] as $o) {
            $dumps[] = print_r($o, true);
            $dumps[] = var_export($o, true);
            $dumps[] = (string) json_encode($o);
            ob_start();
            var_dump($o);
            $dumps[] = (string) ob_get_clean();
            try {
                $dumps[] = serialize($o);
                self::fail('serialize must refuse');
            } catch (\LogicException $e) {
                $dumps[] = self::everything($e);
            }
        }
        foreach ($dumps as $dump) {
            $this->assertClean($dump);
        }
        self::assertSame(self::URL, $t->url());
        self::assertSame(self::TOKEN, $s->httpToken());
    }

    public function testSecretBearingParametersAreMarkedSensitive(): void
    {
        $sensitive = static fn (\ReflectionParameter $p): bool => $p->getAttributes(\SensitiveParameter::class) !== [];
        self::assertTrue($sensitive(new \ReflectionParameter([ObserveSettings::class, 'fromArray'], 'raw')));
        $ctor = new \ReflectionMethod(ObserveSettings::class, '__construct');
        foreach ($ctor->getParameters() as $p) {
            if (in_array($p->getName(), ['target', 'token'], true)) {
                self::assertTrue($sensitive($p), $p->getName());
            }
        }
        $sink = new \ReflectionMethod(HttpSink::class, '__construct');
        foreach ($sink->getParameters() as $p) {
            if ($p->getName() === 'authToken') {
                self::assertTrue($sensitive($p), 'HttpSink authToken');
            }
        }
        self::assertTrue($sensitive(new \ReflectionParameter([\Watcher\Config\SecureUrl::class, 'problem'], 'url')));
    }
}
