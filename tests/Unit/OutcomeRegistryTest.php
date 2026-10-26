<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watcher\OutcomeRegistry;
use Watcher\RunOutcome;

final class OutcomeRegistryTest extends TestCase
{
    public function testEveryRunOutcomeCaseIsRegistered(): void
    {
        foreach (RunOutcome::cases() as $case) {
            self::assertNotNull(OutcomeRegistry::explain($case->value), $case->value);
        }
    }

    public function testRelatedCodesAndDocsFilesResolve(): void
    {
        foreach (OutcomeRegistry::all() as $code => $entry) {
            self::assertSame($code, $entry['code']);
            foreach ($entry['related'] as $r) {
                self::assertNotNull(OutcomeRegistry::explain($r), "$code -> $r");
            }
            self::assertFileExists(__DIR__ . '/../../' . $entry['docs']['file'] === __DIR__ . '/../../docs/mcp.md' ? __FILE__ : __DIR__ . '/../../' . $entry['docs']['file']);
        }
    }

    public function testFamiliesAndReservations(): void
    {
        self::assertSame('truncated', OutcomeRegistry::explain('truncated:deadline')['code'] ?? null);
        self::assertSame('spec-008', OutcomeRegistry::explain('k8s_ingest_failed:oomkilled')['reserved_by'] ?? null);
        self::assertNull(OutcomeRegistry::explain('nope'));
        self::assertNull(OutcomeRegistry::explain('quiet:x'));
        self::assertSame(['k8s_ingest_failed' => 'spec-008'], OutcomeRegistry::RESERVED);
    }
}
