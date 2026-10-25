<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Context\TraceLinkBuilder;
use Watcher\Schedule\Window;

/** Golden URLs: the shapes are UNVERIFIED against live instances, so these strings are the pin (SC-007). */
final class TraceLinkBuilderTest extends TestCase
{
    private const ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const TEMPO = ['tempo' => ['base_url' => 'https://grafana.example/', 'datasource_uid' => 'tempo']];
    private const DD = ['datadog' => ['app_url' => 'https://app.datadoghq.eu'], 'datadog_apm' => []];

    private function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
    }

    /** @param array<string, mixed> $links */
    private function link(array $links, ?string $id, string $service = 'orders-api'): ?string
    {
        return (new TraceLinkBuilder($links, ['env' => 'stg'], $service))->forGroup($id, $this->window());
    }

    public function testTempoWithId(): void
    {
        self::assertSame('https://grafana.example/explore?schemaVersion=1&panes=%7B%22a%22%3A%7B%22datasource%22%3A%22tempo%22%2C%22queries%22%3A%5B%7B%22refId%22%3A%22A%22%2C%22queryType%22%3A%22traceql%22%2C%22query%22%3A%224bf92f3577b34da6a3ce929d0e0e4736%22%2C%22datasource%22%3A%7B%22type%22%3A%22tempo%22%2C%22uid%22%3A%22tempo%22%7D%7D%5D%2C%22range%22%3A%7B%22from%22%3A%221792627200000%22%2C%22to%22%3A%221792713600000%22%7D%7D%7D&orgId=1', $this->link(self::TEMPO, self::ID));
    }

    public function testTempoServiceSearchWithoutId(): void
    {
        self::assertSame('https://grafana.example/explore?schemaVersion=1&panes=%7B%22a%22%3A%7B%22datasource%22%3A%22tempo%22%2C%22queries%22%3A%5B%7B%22refId%22%3A%22A%22%2C%22queryType%22%3A%22traceql%22%2C%22query%22%3A%22%7Bresource.service.name%3D%5C%22orders-api%5C%22%7D%22%2C%22datasource%22%3A%7B%22type%22%3A%22tempo%22%2C%22uid%22%3A%22tempo%22%7D%7D%5D%2C%22range%22%3A%7B%22from%22%3A%221792627200000%22%2C%22to%22%3A%221792713600000%22%7D%7D%7D&orgId=1', $this->link(self::TEMPO, null));
    }

    public function testDatadogWithId(): void
    {
        self::assertSame('https://app.datadoghq.eu/apm/traces?query=trace_id%3A4bf92f3577b34da6a3ce929d0e0e4736&start=1792627200000&end=1792713600000', $this->link(self::DD, self::ID));
    }

    public function testDatadogServiceSearchCarriesEnvFromStreamTags(): void
    {
        self::assertSame('https://app.datadoghq.eu/apm/traces?query=service%3Aorders-api%20env%3Astg&start=1792627200000&end=1792713600000', $this->link(self::DD, null));
    }

    public function testTempoWinsWhenBothAreSet(): void
    {
        self::assertStringStartsWith('https://grafana.example/explore', (string) $this->link(self::TEMPO + self::DD, self::ID));
    }

    public function testNoConfigMeansNoLink(): void
    {
        self::assertNull($this->link([], self::ID));
        self::assertNull($this->link(['datadog_apm' => []], self::ID), 'datadog_apm needs links.datadog.app_url');
    }

    public function testQuotesAndBackslashesInTheServiceNameAreEscaped(): void
    {
        parse_str((string) parse_url((string) $this->link(self::TEMPO, null, 'a"b\\c'), PHP_URL_QUERY), $q);
        $panes = json_decode(is_string($q['panes']) ? $q['panes'] : '', true);

        self::assertSame('{resource.service.name="a\\"b\\\\c"}', $panes['a']['queries'][0]['query']);
    }

    public function testServiceNameOverrideFromConfig(): void
    {
        $links = ['tempo' => self::TEMPO['tempo'] + ['service_name' => 'billing']];

        self::assertStringContainsString(rawurlencode('billing'), (string) $this->link($links, null));
    }

    public function testAnInvalidIdIsNeverPutInTheUrl(): void
    {
        foreach (['xyz', strtoupper(self::ID), self::ID . 'ff', 'a b'] as $bad) {
            $url = (string) $this->link(self::DD, $bad);
            self::assertStringNotContainsString(rawurlencode($bad), $url);
            self::assertStringContainsString('service%3Aorders-api', $url);
        }
    }
}
