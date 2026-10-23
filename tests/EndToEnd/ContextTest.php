<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RoutedLogSource;
use Watcher\Watcher;

/** US6 scenarios 15-19 (quickstart): findings come with their context. */
final class ContextTest extends TestCase
{
    private const DEP = ['core-api' => ['stream_tags' => ['app' => 'core-api', 'env' => 'stg']]];
    private const GRAFANA = ['grafana' => ['base_url' => 'https://grafana.test', 'datasource_uid' => 'loki-uid']];

    /**
     * @param list<LogEvent> $own
     * @param list<LogEvent> $dep
     *
     * @return array{0: RoutedLogSource, 1: FakeLogSource}
     */
    private static function source(array $own, array $dep = []): array
    {
        $depSource = new FakeLogSource(Harness::totalsOf($dep), $dep);

        return [new RoutedLogSource(['core-api' => $depSource], new FakeLogSource(Harness::totalsOf($own), $own)), $depSource];
    }

    /** @param array<string, mixed> $patch */
    private static function go(Harness $h, \Watcher\Source\LogSource $source, array $patch = []): \Watcher\RunResult
    {
        return (new Watcher(WatchConfig::fromArray(array_replace_recursive($h->config(), $patch)), $source, $h->state, $h->analyzer, $h->notifier, $h->clock))->run();
    }

    private static function digest(Harness $h): string
    {
        return str_replace("\u{200B}", '', html_entity_decode(implode("\n", $h->sentTexts())));
    }

    public function testScenario15DependencyWithANewErrorGroupIsNamedAndQuietDayCostsOneTotalsQuery(): void
    {
        $h = new Harness(['dependencies' => self::DEP]);
        [$s1] = self::source([], []);
        self::assertSame(RunOutcome::Baselined, self::go($h, $s1)->outcome);

        $h->nextWindow();
        $own = Harness::events(Level::Error, 'GET http://core-api/api/items returned 500', 5);
        [$s2] = self::source($own, Harness::events(Level::Error, 'Pool exhausted', 6));
        self::assertSame(RunOutcome::Reported, self::go($h, $s2)->outcome);

        $req = $h->analyzer->requests[0];
        self::assertCount(1, $req->dependencyContext);
        $dep = $req->dependencyContext[0];
        self::assertSame('core-api', $dep->name);
        self::assertSame(6, $dep->errors());
        self::assertSame('Pool exhausted', $dep->topErrors[0]->template);
        self::assertSame([0], $dep->referencedByTemplateIndexes);
        self::assertStringContainsString('dependency core-api shows 6 errors', self::digest($h));

        // Quiet day: nothing new for the service; the dependency costs one totals query and no line read.
        $h->nextWindow();
        [$s3, $depSource] = self::source($own, Harness::events(Level::Error, 'Pool exhausted', 6));
        self::assertSame(RunOutcome::Quiet, self::go($h, $s3)->outcome);
        self::assertCount(1, $depSource->callsTo('totals'));
        self::assertSame([], $depSource->callsTo('lines'));
    }

    public function testScenario15DependencyFailureDoesNotFailTheRunAndIsListed(): void
    {
        $h = new Harness(['dependencies' => self::DEP]);
        [$s1] = self::source([], []);
        self::go($h, $s1);
        $h->nextWindow();
        [$s2, $depSource] = self::source(Harness::events(Level::Error, 'GET http://core-api/api returned 500', 5));
        $depSource->failWith(new \Watcher\Source\SourceUnavailable('down'));

        self::assertSame(RunOutcome::Reported, self::go($h, $s2)->outcome);
        self::assertSame([], $h->analyzer->requests[0]->dependencyContext);
        self::assertStringContainsString('dependency unavailable: core-api', self::digest($h));
    }

    public function testScenario16UndeclaredHostIsListedOnceAndNowhereElse(): void
    {
        $h = new Harness(['dependencies' => self::DEP]);
        [$s1] = self::source([], []);
        self::go($h, $s1);
        $h->nextWindow();
        [$s2] = self::source(Harness::events(Level::Error, 'POST http://reporting-api/export timed out', 5));
        self::assertSame(RunOutcome::Reported, self::go($h, $s2)->outcome);

        $digest = self::digest($h);
        self::assertSame(1, substr_count($digest, 'reporting-api'));
        self::assertStringContainsString('undeclared dependency: reporting-api', $digest);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertStringNotContainsString('reporting-api', json_encode($h->state->all(), JSON_THROW_ON_ERROR));
    }

    public function testScenario17FirstSeenAfterReleaseOnlyWhenTheReleaseChanged(): void
    {
        $h = new Harness();
        [$s1] = self::source(Harness::events(Level::Error, 'Old failure', 5));
        self::go($h, $s1, ['deploy' => ['release' => 'v1']]);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertStringContainsString('"release":"v1"', json_encode($h->state->all(), JSON_THROW_ON_ERROR));

        $h->nextWindow();
        [$s2] = self::source([...Harness::events(Level::Error, 'Old failure', 5), ...Harness::events(Level::Error, 'Brand new failure', 5)]);
        self::go($h, $s2, ['deploy' => ['release' => 'v2']]);
        $digest = self::digest($h);
        self::assertStringContainsString('first seen after v2', $digest);
        self::assertSame(1, substr_count($digest, 'first seen after'));
        self::assertStringContainsString('· v2', $digest); // header

        // Same release next window, another new group: no mark.
        $h->nextWindow();
        [$s3] = self::source([...Harness::events(Level::Error, 'Old failure', 5), ...Harness::events(Level::Error, 'Brand new failure', 5), ...Harness::events(Level::Error, 'Yet another failure', 5)]);
        self::go($h, $s3, ['deploy' => ['release' => 'v2']]);
        self::assertSame(1, substr_count(self::digest($h), 'first seen after'));
    }

    public function testScenario18EveryFindingHasALogLinkWithoutPersonalData(): void
    {
        $h = new Harness();
        [$s1] = self::source([]);
        self::go($h, $s1, ['links' => self::GRAFANA]);
        $h->nextWindow();
        [$s2] = self::source([
            ...Harness::events(Level::Error, 'Cannot notify anna.kowalski@example.org', 5),
            ...Harness::events(Level::Error, 'Disk quota exceeded on data volume', 5),
        ]);
        self::go($h, $s2, ['links' => self::GRAFANA]);

        $findings = array_filter($h->notifier->sent[array_key_last($h->notifier->sent)]['message']->sections, static fn (array $s): bool => $s['kind'] === 'finding');
        self::assertCount(2, $findings);
        foreach ($findings as $f) {
            self::assertMatchesRegularExpression('~<https://grafana\.test/explore\?schemaVersion=1&panes=[^|>\s]+\|log>~', $f['text']);
        }
        $links = rawurldecode(rawurldecode(implode("\n", array_column($findings, 'text'))));
        self::assertStringNotContainsString('anna.kowalski', $links);
        self::assertStringContainsString('Disk quota exceeded on data volume', $links);
    }

    public function testScenario19AppFramesLinkToTheDeployedCommitAndVendorFramesDoNot(): void
    {
        $h = new Harness();
        $frames = ['/var/www/html/app/Pay.php:42', '/var/www/html/vendor/guzzle/Client.php:10'];
        $at = new \DateTimeImmutable('2026-10-07T12:00:00', new \DateTimeZone('UTC'));
        $events = array_map(static fn (): LogEvent => new LogEvent($at, Level::Error, 'Charge failed', 'PayException', $frames, [], []), range(1, 5));
        [$s1] = self::source([]);
        $patch = ['deploy' => ['commit' => 'abc123'], 'repo' => ['provider' => 'gitlab', 'web_url' => 'https://git.test/acme/svc', 'path_map' => ['/var/www/html/' => '']]];
        self::go($h, $s1, $patch);
        $h->nextWindow();
        [$s2] = self::source($events);
        self::go($h, $s2, $patch);

        $digest = self::digest($h);
        self::assertStringContainsString('<https://git.test/acme/svc/-/blob/abc123/app/Pay.php#L42|code 1>', $digest);
        self::assertStringNotContainsString('vendor', $digest);
        self::assertStringNotContainsString('|code 2>', $digest);
    }

    public function testFailedRunLeavesNoDependencySnapshot(): void
    {
        $h = new Harness(['dependencies' => self::DEP]);
        [$s1] = self::source([]);
        self::go($h, $s1);
        $h->nextWindow();
        [$s2] = self::source(Harness::events(Level::Error, 'GET http://core-api/api returned 500', 5), Harness::events(Level::Error, 'Pool exhausted', 6));
        $h->notifier->failWith(new \Watcher\Notify\DeliveryFailed('slack down'));
        self::assertSame(RunOutcome::Failed, self::go($h, $s2)->outcome);

        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $key = \Watcher\State\Keys::dependencySnapshot('svc', 'core-api', Watcher::windowKey($h->windowEnd()));
        self::assertNull($h->state->get($key));
    }
}
