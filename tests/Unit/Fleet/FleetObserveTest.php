<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Fleet\FleetConfig;

final class FleetObserveTest extends TestCase
{
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

    public function testSharedObserveInDefaultsReachesEveryService(): void
    {
        $f = FleetConfig::fromArray(self::fleet(
            [FleetConfigTest::service('api'), FleetConfigTest::service('bff', ['environment' => 'prod'])],
            ['defaults' => ['observe' => ['log' => ['enabled' => true], 'prometheus' => ['enabled' => true, 'dir' => '/var/lib/te']]]],
        ));

        foreach ($f->services as $e) {
            self::assertTrue($e->config->observe->log['enabled']);
            self::assertTrue($e->config->observe->prometheus['enabled']);
        }
        self::assertSame('watcher_api', $f->services[0]->config->observe->prometheus['name']);
        self::assertSame('watcher_bff_prod', $f->services[1]->config->observe->prometheus['name'], 'one file per service');
    }

    public function testFleetLevelObserveIsSharedToo(): void
    {
        $f = FleetConfig::fromArray(self::fleet([FleetConfigTest::service('api')], ['observe' => ['log' => ['enabled' => true]]]));

        self::assertTrue($f->services[0]->config->observe->log['enabled']);
    }

    public function testAServicesOwnObserveReplacesTheSharedOneAsAWhole(): void
    {
        $f = FleetConfig::fromArray(self::fleet(
            [FleetConfigTest::service('api', ['observe' => ['file' => ['enabled' => true, 'path' => '/var/log/w/api.jsonl']]]), FleetConfigTest::service('bff')],
            ['defaults' => ['observe' => ['log' => ['enabled' => true]]]],
        ));

        self::assertFalse($f->services[0]->config->observe->log['enabled'], 'not merged with the shared log sink');
        self::assertTrue($f->services[0]->config->observe->file['enabled']);
        self::assertTrue($f->services[1]->config->observe->log['enabled']);
    }

    public function testAFixedPrometheusNameIsRejectedInAFleet(): void
    {
        $msg = self::message(self::fleet(
            [FleetConfigTest::service('api'), FleetConfigTest::service('bff')],
            ['defaults' => ['observe' => ['prometheus' => ['enabled' => true, 'dir' => '/var/lib/te', 'name' => 'watcher_all']]]],
        ));

        self::assertSame('services[0] (api): observe.prometheus.name must be unset in a fleet', $msg);
    }

    public function testTwoServicesThatWouldWriteOneFileAreRejected(): void
    {
        $msg = self::message(self::fleet(
            [FleetConfigTest::service('a-b'), FleetConfigTest::service('a_b')],
            ['defaults' => ['observe' => ['prometheus' => ['enabled' => true, 'dir' => '/var/lib/te']]]],
        ));

        self::assertSame('services[1] (a_b): observe.prometheus would write the same file as services[0] (a-b)', $msg);
    }

    public function testErrorsKeepTheEntryPrefix(): void
    {
        $msg = self::message(self::fleet([FleetConfigTest::service('api'), FleetConfigTest::service('bff', ['observe' => ['http' => ['enabled' => true]]])]));

        self::assertSame('services[1] (bff): observe.http.url is required', $msg);
    }

    public function testCombinedDigestWorksWithDriverNoneOnlyWhenASinkIsOn(): void
    {
        $services = [FleetConfigTest::service('api'), FleetConfigTest::service('bff')];
        $none = ['driver' => 'none'];

        self::assertSame(
            'notify.digest.driver none needs at least one enabled observe sink',
            self::message(self::fleet($services, ['digest' => 'combined', 'notify' => ['digest' => $none]])),
        );

        $f = FleetConfig::fromArray(self::fleet($services, ['digest' => 'combined', 'notify' => ['digest' => $none], 'defaults' => ['observe' => ['log' => ['enabled' => true]]]]));
        self::assertSame('none', $f->destination('digest')?->driver());
    }

    public function testAServiceEventCarriesItsOwnNames(): void
    {
        $f = FleetConfig::fromArray(self::fleet([FleetConfigTest::service('api', ['environment' => 'prod'])], ['defaults' => ['observe' => ['log' => ['enabled' => true]]]]));

        self::assertSame('api@prod', $f->services[0]->name());
        self::assertSame('prod', $f->services[0]->config->environment);
    }

    public function testInvalidNamesNameTheEntryInAFleet(): void
    {
        $msg = self::message(self::fleet([FleetConfigTest::service('api'), FleetConfigTest::service('billing api')], ['defaults' => ['observe' => ['prometheus' => ['enabled' => true, 'dir' => '/var/lib/te']]]]));

        self::assertSame('services[1] (billing api): service must match [A-Za-z0-9][A-Za-z0-9._@-]{0,63} when an observe sink is enabled', $msg);
    }

    public function testDuplicateServiceNamesAreRejectedSoNoTwoServicesShareSeries(): void
    {
        $msg = self::message(self::fleet([FleetConfigTest::service('api'), FleetConfigTest::service('api')], ['defaults' => ['observe' => ['log' => ['enabled' => true]]]]));

        self::assertStringStartsWith('duplicate service: services[0] (api) and services[1] (api)', $msg);
    }

    public function testTheSameServiceInTwoEnvironmentsHasDistinctSeriesAndFiles(): void
    {
        $f = FleetConfig::fromArray(self::fleet(
            [FleetConfigTest::service('api', ['environment' => 'int']), FleetConfigTest::service('api', ['environment' => 'prod'])],
            ['defaults' => ['observe' => ['prometheus' => ['enabled' => true, 'dir' => '/var/lib/te']]]],
        ));

        self::assertSame(['watcher_api_int', 'watcher_api_prod'], [$f->services[0]->config->observe->prometheus['name'], $f->services[1]->config->observe->prometheus['name']]);
    }
}
