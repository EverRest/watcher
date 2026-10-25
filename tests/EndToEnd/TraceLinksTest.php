<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Operate\JsonSummary;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/** Spec 009 US4 (T040): the trace id of a group exists in memory and inside one link URL, nowhere else. */
final class TraceLinksTest extends TestCase
{
    private const ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const LATER = '00000000000000000000000000000abc';

    /** @return list<LogEvent> */
    private static function events(): array
    {
        $at = static fn (string $t): DateTimeImmutable => new DateTimeImmutable($t, new \DateTimeZone('UTC'));

        return [
            new LogEvent($at('2026-10-07T12:00:00'), Level::Error, 'Alpha exploded', 'AlphaException', [], ['trace_id' => self::LATER], []),
            new LogEvent($at('2026-10-07T09:00:00'), Level::Error, 'Alpha exploded', 'AlphaException', [], ['trace_id' => self::ID], []),
            new LogEvent($at('2026-10-07T13:00:00'), Level::Error, 'Alpha exploded', 'AlphaException', [], [], []),
        ];
    }

    /** @param list<LogEvent>|null $events */
    private static function twoDays(Harness $h, ?array $events = null): string
    {
        $events ??= self::events();
        $source = new FakeLogSource(Harness::totalsOf($events), $events);
        (new Watcher(WatchConfig::fromArray($h->config()), $source, $h->state, $h->analyzer, $h->notifier, $h->clock, null))->run();
        $h->nextWindow();
        $result = (new Watcher(WatchConfig::fromArray($h->config()), $source, $h->state, $h->analyzer, $h->notifier, $h->clock, null))->run();

        return (new JsonSummary())->render($result->summaryData ?? throw new \LogicException());
    }

    public function testTheEarliestIdIsInTheLinkAndNowhereElse(): void
    {
        $h = new Harness(['links' => ['tempo' => ['base_url' => 'https://grafana.example', 'datasource_uid' => 'tempo']]]);
        $json = self::twoDays($h);

        $digest = implode("\n", $h->sentTexts());
        self::assertStringContainsString(rawurlencode(self::ID), $digest);
        self::assertStringContainsString('|trace>', $digest);
        self::assertStringNotContainsString(self::LATER, $digest); // the earliest event's id wins
        $outside = (string) preg_replace('~<[^>|]+\|trace>~', '', $digest); // the link URL is the one place
        self::assertStringNotContainsString(self::ID, $outside);
        self::assertStringNotContainsString(self::ID, $json);
        foreach ($h->analyzer->allRequests() as $req) {
            self::assertStringNotContainsString(self::ID, \Watcher\Analyze\CompactPayload::encode($req->groups, [], $req->extras));
        }
        self::assertStringNotContainsString(self::ID, (string) json_encode($h->state));
    }

    public function testNoTracingConfigMeansNoLinkAndNoCollector(): void
    {
        $h = new Harness();
        self::twoDays($h);

        self::assertStringNotContainsString('|trace>', implode("\n", $h->sentTexts()));
    }

    public function testAGroupWithoutAnIdGetsTheServiceSearchLink(): void
    {
        $h = new Harness(['links' => ['tempo' => ['base_url' => 'https://grafana.example', 'datasource_uid' => 'tempo']]]);
        $plain = [new LogEvent(new DateTimeImmutable('2026-10-07T09:00:00', new \DateTimeZone('UTC')), Level::Error, 'Alpha exploded', 'AlphaException', [], [], [])];
        self::twoDays($h, $plain);

        $digest = implode("\n", $h->sentTexts());
        self::assertStringContainsString('|trace>', $digest);
        self::assertStringContainsString(rawurlencode('resource.service.name'), $digest);
    }
}
