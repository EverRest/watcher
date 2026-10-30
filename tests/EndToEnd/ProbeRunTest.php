<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Operate\RunOptions;
use Watcher\Tests\Fakes\FakeLogSource;

/**
 * The probes hook in Watcher::run(): an alert on a run, none in a dry run, none with the notice flag off, and no model call.
 * The harness clock is 2026-10-08, so a secret expiring 2026-10-13 is five days away (fail at the default 7).
 */
final class ProbeRunTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function probes(): array
    {
        return ['probes' => ['enabled' => true, 'secrets' => [['name' => 'PAYMENT_KEY', 'expires' => '2026-10-13', 'owner' => 'team-payments']]]];
    }

    private static function quiet(): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf([]), []);
    }

    /** @return list<string> */
    private static function probeTexts(Harness $h): array
    {
        return array_values(array_filter($h->sentTexts(), static fn (string $t): bool => str_contains($t, 'probe')));
    }

    public function testAFailingSecretAlertsOnTheRunAndNoModelCallIsMade(): void
    {
        $h = new Harness(self::probes());

        $h->watcher(self::quiet())->run();

        $texts = self::probeTexts($h);
        self::assertCount(1, $texts);
        self::assertStringContainsString('FAIL', $texts[0]);
        self::assertStringContainsString('PAYMENT_KEY', $texts[0]);
        self::assertSame([], $h->analyzer->allRequests());
    }

    public function testADryRunAlertsNothing(): void
    {
        $h = new Harness(self::probes());

        $h->watcher(self::quiet(), RunOptions::dryRun())->run();

        self::assertSame([], self::probeTexts($h));
    }

    public function testTheNoticeFlagOffSilencesProbes(): void
    {
        $h = new Harness(self::probes() + ['notify' => ['notice' => ['enabled' => false]]]);

        $h->watcher(self::quiet())->run();

        self::assertSame([], self::probeTexts($h));
    }
}
