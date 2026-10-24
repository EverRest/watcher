<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Fleet\FleetConfig;

final class FleetConfigTest extends TestCase
{
    private const SECRET = 'sk-ant-api03-SECRETSECRETSECRET0123';

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed> a complete single-service config
     */
    public static function service(string $name, array $patch = []): array
    {
        return array_replace_recursive([
            'service' => $name,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'http://loki.invalid'], 'stream_tags' => ['app' => $name]],
            'analysis' => [
                'api_key' => self::SECRET,
                'models' => ['triage' => 'm-t', 'analyze' => 'm-a', 'escalate' => 'm-e'],
                'prices' => ['m-t' => [1, 5, 0.1, 1.25], 'm-a' => [3, 15, 0.3, 3.75], 'm-e' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ], $patch);
    }

    /**
     * @param list<array<string, mixed>> $services
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function fleet(array $services, array $patch = []): array
    {
        return array_replace(['fleet' => 'platform', 'services' => $services], $patch);
    }

    /** @param array<string, mixed> $config */
    private static function message(array $config): string
    {
        try {
            FleetConfig::fromArray($config);
        } catch (InvalidConfig $e) {
            return $e->getMessage();
        }
        self::fail('expected InvalidConfig');
    }

    public function testFleetOfOneIsValidWithDefaults(): void
    {
        $f = FleetConfig::fromArray(self::fleet([self::service('api')]));

        self::assertSame('platform', $f->name);
        self::assertCount(1, $f->services);
        self::assertSame('api', $f->services[0]->config->service);
        self::assertSame(0, $f->services[0]->priority);
        self::assertSame(['api'], $f->services[0]->provides);
        self::assertSame('per_service', $f->digest);
        self::assertNull($f->timeBudgetSeconds);
        self::assertTrue($f->correlationEnabled);
        self::assertSame('UTC', $f->timezone);
    }

    public function testFleetNameIsRequiredAndSimple(): void
    {
        self::assertSame('fleet is required', self::message(['services' => [self::service('a')]]));
        self::assertStringContainsString('fleet', self::message(self::fleet([self::service('a')], ['fleet' => 'bad name'])));
    }

    public function testDefaultsAreMergedUnderEveryEntry(): void
    {
        $f = FleetConfig::fromArray(self::fleet(
            [['service' => 'api', 'selection' => ['top_n' => 12]], ['service' => 'bff']],
            ['defaults' => ['selection' => ['min_level' => 'warning', 'top_n' => 20]] + self::service('x')],
        ));

        self::assertSame(12, $f->services[0]->config->selection['top_n']);
        self::assertSame(20, $f->services[1]->config->selection['top_n']);
        self::assertSame('bff', $f->services[1]->config->service);
    }

    public function testPriorityAndProvidesAreStrippedAndKept(): void
    {
        $f = FleetConfig::fromArray(self::fleet([self::service('api', ['priority' => 10, 'provides' => ['core', 'api']])]));

        self::assertSame(10, $f->services[0]->priority);
        self::assertSame(['core', 'api'], $f->services[0]->provides);
    }

    public function testInvalidEntryNamesIndexServiceAndKeyPath(): void
    {
        $msg = self::message(self::fleet([self::service('api'), self::service('bff-a', ['selection' => ['growth_factor' => 1]])]));

        self::assertSame('services[1] (bff-a): selection.growth_factor must be greater than 1', $msg);
    }

    public function testEveryEntryIsValidatedBeforeAnythingRuns(): void
    {
        // the first entry is valid, the second is not: the whole fleet is rejected
        $this->expectException(InvalidConfig::class);
        FleetConfig::fromArray(self::fleet([self::service('api'), ['service' => 'broken']]));
    }

    public function testEntryWithoutServiceIsNamedByIndex(): void
    {
        self::assertStringStartsWith('services[0]: ', self::message(self::fleet([['schedule' => ['run_at' => '06:00']]])));
    }

    public function testBadPriorityAndProvides(): void
    {
        self::assertSame('services[0] (a): priority must be an integer', self::message(self::fleet([self::service('a', ['priority' => 'high'])])));
        self::assertSame('services[0] (a): provides must be a list of non-empty strings', self::message(self::fleet([self::service('a', ['provides' => 'api'])])));
    }

    public function testDuplicateServiceNamesNameBothEntries(): void
    {
        $msg = self::message(self::fleet([self::service('api'), self::service('bff'), self::service('api')]));

        self::assertStringContainsString('services[0] (api)', $msg);
        self::assertStringContainsString('services[2] (api)', $msg);
        self::assertStringContainsString('duplicate', $msg);
    }

    public function testSlashInServiceNameIsRejected(): void
    {
        self::assertSame("services[0] (fleet/x): service must not contain '/'", self::message(self::fleet([self::service('fleet/x')])));
    }

    public function testDifferingStateIsRejected(): void
    {
        $msg = self::message(self::fleet([self::service('a'), self::service('b', ['state' => ['file' => ['path' => '/other']]])]));

        self::assertSame('services[1] (b): state must be identical in all services (differs from services[0] (a))', $msg);
    }

    public function testDifferingApiKeyIsRejectedWithoutEchoingIt(): void
    {
        $msg = self::message(self::fleet([self::service('a'), self::service('b', ['analysis' => ['api_key' => 'another-secret-key-value']])]));

        self::assertSame('services[1] (b): analysis.api_key must be identical in all services (differs from services[0] (a))', $msg);
        self::assertStringNotContainsString('another-secret', $msg);
        self::assertStringNotContainsString(self::SECRET, $msg);
    }

    public function testUnknownFleetKeyIsRejected(): void
    {
        self::assertSame('Unknown configuration key: fleet.colour', self::message(self::fleet([self::service('a')], ['colour' => 'red'])));
    }

    public function testServicesMustBeANonEmptyList(): void
    {
        self::assertSame('services must be a non-empty list', self::message(self::fleet([])));
    }

    public function testBudgetDigestTimeBudgetAndCorrelationAreParsed(): void
    {
        $f = FleetConfig::fromArray(self::fleet([self::service('a')], [
            'digest' => 'combined',
            'timezone' => 'Europe/Kyiv',
            'time_budget' => 'PT50M',
            'budget' => ['daily_budget_usd' => 3.0, 'daily_token_limit' => 2000000],
            'notify' => ['digest' => ['webhook' => 'https://hooks.invalid/fleet']],
            'correlation' => ['enabled' => false, 'tolerance' => 'PT5M'],
        ]));

        self::assertSame('combined', $f->digest);
        self::assertSame('Europe/Kyiv', $f->timezone);
        self::assertSame(3000, $f->timeBudgetSeconds);
        self::assertSame(3.0, $f->budget['daily_budget_usd']);
        self::assertSame(2000000, $f->budget['daily_token_limit']);
        self::assertSame([25, 50, 75, 90], $f->budget['alert_percents']);
        self::assertFalse($f->correlationEnabled);
        self::assertSame(300, $f->correlationToleranceSeconds);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidFleetKeys(): iterable
    {
        yield 'digest' => [['digest' => 'both'], 'digest must be per_service or combined'];
        yield 'time_budget' => [['time_budget' => 'soon'], 'time_budget must be an ISO-8601 duration'];
        yield 'time_budget zero' => [['time_budget' => 'PT0S'], 'time_budget must be longer than zero'];
        yield 'tolerance' => [['correlation' => ['tolerance' => 'x']], 'correlation.tolerance must be an ISO-8601 duration'];
        yield 'timezone' => [['timezone' => 'Mars/Base'], 'timezone must be an IANA timezone'];
        yield 'budget usd' => [['budget' => ['daily_budget_usd' => 0]], 'budget.daily_budget_usd must be a number greater than zero'];
        yield 'budget tokens' => [['budget' => ['daily_token_limit' => -1]], 'budget.daily_token_limit must be null or a positive integer'];
        yield 'budget percents' => [['budget' => ['alert_percents' => [90, 50]]], 'budget.alert_percents must be ascending integers from 1 to 99'];
        yield 'budget key' => [['budget' => ['cap' => 1]], 'Unknown configuration key: fleet.budget.cap'];
        yield 'correlation key' => [['correlation' => ['x' => 1]], 'Unknown configuration key: fleet.correlation.x'];
        yield 'combined needs a destination' => [['digest' => 'combined'], 'digest combined needs notify.digest'];
    }

    /**
     * @param array<string, mixed> $patch
     * @dataProvider invalidFleetKeys
     */
    public function testInvalidFleetLevelKeys(array $patch, string $expected): void
    {
        self::assertSame($expected, self::message(self::fleet([self::service('a')], $patch)));
    }

    public function testNoErrorTextContainsAConfiguredSecret(): void
    {
        $msgs = [
            self::message(self::fleet([self::service('a', ['selection' => ['top_n' => 'x']])])),
            self::message(self::fleet([self::service('a'), self::service('a')])),
            self::message(self::fleet([self::service('a', ['source' => ['driver' => 'nope']])])),
        ];
        foreach ($msgs as $m) {
            self::assertStringNotContainsString(self::SECRET, $m);
            self::assertStringNotContainsString('hooks.invalid', $m);
        }
    }

    public function testDirectoryFormLoadsSortedFilesAndFleetFile(): void
    {
        $dir = sys_get_temp_dir() . '/watcher-fleet-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $write = static fn (string $file, array $v) => file_put_contents("$dir/$file", '<?php return ' . var_export($v, true) . ';');
        $write('_fleet.php', ['fleet' => 'dirfleet']);
        $write('20-bff.php', self::service('bff'));
        $write('10-api.php', self::service('api'));
        file_put_contents("$dir/notes.txt", 'ignored');

        try {
            $f = FleetConfig::fromPath($dir);
            self::assertSame('dirfleet', $f->name);
            self::assertSame(['api', 'bff'], array_map(static fn ($e) => $e->config->service, $f->services));

            unlink("$dir/_fleet.php");
            $this->expectException(InvalidConfig::class);
            $this->expectExceptionMessage('_fleet.php');
            FleetConfig::fromPath($dir);
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testFileFormAndMissingPath(): void
    {
        $file = sys_get_temp_dir() . '/watcher-fleet-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, '<?php return ' . var_export(self::fleet([self::service('api')]), true) . ';');
        try {
            self::assertSame('platform', FleetConfig::fromPath($file)->name);
        } finally {
            unlink($file);
        }
        $this->expectException(InvalidConfig::class);
        FleetConfig::fromPath('/nonexistent/fleet.php');
    }

    private const TOKEN = '123456:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789';

    /**
     * @param array<string, mixed> $notify
     * @return array<string, mixed> a service whose notify is exactly $notify
     */
    private static function serviceNotify(string $name, array $notify): array
    {
        $s = self::service($name);
        $s['notify'] = $notify;

        return $s;
    }

    public function testServiceDestinationReplacesFleetDefault(): void
    {
        $tg = ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123'];
        $f = FleetConfig::fromArray(self::fleet(
            [self::serviceNotify('a', ['digest' => $tg]), ['service' => 'b'] + self::serviceNotify('b', [])],
            ['notify' => ['digest' => ['webhook' => 'https://hooks.invalid/fleet']]],
        ));

        $a = $f->services[0]->config;
        self::assertSame('telegram', $a->destination('digest')->driver());
        self::assertSame('telegram', $a->destination('notice')->driver());
        self::assertSame(['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123'], $a->notify['digest']);
        $b = $f->services[1]->config;
        self::assertSame('slack', $b->destination('digest')->driver());
        self::assertSame('https://hooks.invalid/fleet', $b->destination('digest')->webhook());
    }

    public function testServiceNoticeReplacesFleetNoticeWholesale(): void
    {
        $f = FleetConfig::fromArray(self::fleet(
            [self::serviceNotify('a', ['notice' => ['driver' => 'discord', 'webhook' => 'https://discord.com/api/webhooks/1/abc']])],
            ['notify' => ['digest' => ['webhook' => 'https://hooks.invalid/d'], 'notice' => ['webhook' => 'https://hooks.invalid/n']]],
        ));

        $a = $f->services[0]->config;
        self::assertSame('slack', $a->destination('digest')->driver());
        self::assertSame('discord', $a->destination('notice')->driver());
    }

    public function testCombinedNeedsDigestDestinationOfAnyDriver(): void
    {
        $tg = ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123'];
        $f = FleetConfig::fromArray(self::fleet([self::service('a')], ['digest' => 'combined', 'notify' => ['digest' => $tg]]));

        self::assertSame('telegram', $f->destination('digest')?->driver());
        self::assertSame('digest combined needs notify.digest', self::message(self::fleet([self::service('a')], ['digest' => 'combined'])));
    }

    public function testFleetLevelNotifyIsValidatedWithTheSameRules(): void
    {
        self::assertSame(
            'notify.digest.chat_id is required',
            self::message(self::fleet([self::service('a')], ['notify' => ['digest' => ['driver' => 'telegram', 'bot_token' => self::TOKEN]]])),
        );
        self::assertSame(
            'services[0] (a): notify.digest.bot_token is required',
            self::message(self::fleet([self::serviceNotify('a', ['digest' => ['driver' => 'telegram', 'chat_id' => '1']])])),
        );
    }
}
