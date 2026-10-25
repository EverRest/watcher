<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use PHPUnit\Framework\TestCase;
use Watcher\Reduce\Fingerprinter;

final class FingerprinterSnapshotVersionTest extends TestCase
{
    public function testMissingFpVersionIsZero(): void
    {
        self::assertSame(0, Fingerprinter::snapshotVersion(['groups' => []]));
    }

    public function testCurrentFpVersionIsUsable(): void
    {
        self::assertTrue(Fingerprinter::snapshotUsable(['fpv' => Fingerprinter::FPV, 'groups' => []]));
    }

    public function testStaleFpVersionIsNotUsable(): void
    {
        self::assertFalse(Fingerprinter::snapshotUsable(['fpv' => Fingerprinter::FPV - 1, 'groups' => []]));
    }
}
