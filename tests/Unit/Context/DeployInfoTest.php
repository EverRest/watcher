<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Context\DeployInfo;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\Tests\Fakes\FakeLogSource;

final class DeployInfoTest extends TestCase
{
    private function window(): Window
    {
        return new Window(new DateTimeImmutable('2026-03-01T00:00:00Z'), new DateTimeImmutable('2026-03-02T00:00:00Z'));
    }

    /**
     * @param array<string, mixed>      $deploy
     * @param array<string, string|null>|null $previous
     */
    private function resolve(array $deploy, ?array $previous, ?FakeLogSource $source = null): DeployInfo
    {
        return DeployInfo::resolve($deploy, $previous, $source ?? new FakeLogSource(), $this->window(), new StreamSelector(['app' => 'x']));
    }

    public function testReleaseAndCommitComeFromConfig(): void
    {
        $d = $this->resolve(['release' => 'v1.4.0', 'commit' => 'abc123', 'version_label' => null], null);

        self::assertSame('v1.4.0', $d->release);
        self::assertSame('abc123', $d->commit);
        self::assertNull($d->deployedAt);
        self::assertFalse($d->changedSincePrevious);
        self::assertSame(['release' => 'v1.4.0', 'commit' => 'abc123'], $d->toSnapshot());
    }

    public function testEmptyConfigGivesNulls(): void
    {
        $d = $this->resolve([], null);

        self::assertNull($d->release);
        self::assertNull($d->commit);
        self::assertSame(['release' => null, 'commit' => null], $d->toSnapshot());
    }

    public function testReleaseChangeIsDetected(): void
    {
        $d = $this->resolve(['release' => 'v2'], ['release' => 'v1', 'commit' => 'a']);

        self::assertTrue($d->changedSincePrevious);
    }

    public function testSameOrUnknownReleaseIsNotAChange(): void
    {
        self::assertFalse($this->resolve(['release' => 'v1'], ['release' => 'v1', 'commit' => null])->changedSincePrevious);
        self::assertFalse($this->resolve(['release' => 'v1'], ['release' => null, 'commit' => null])->changedSincePrevious);
        self::assertFalse($this->resolve(['release' => null], ['release' => 'v1', 'commit' => null])->changedSincePrevious);
        self::assertFalse($this->resolve(['release' => 'v2'], null)->changedSincePrevious);
    }

    public function testFirstSeenAfterLabelOnlyForNewGroupsAfterAChange(): void
    {
        $changed = $this->resolve(['release' => 'v2'], ['release' => 'v1', 'commit' => null]);
        $same = $this->resolve(['release' => 'v2'], ['release' => 'v2', 'commit' => null]);

        self::assertSame('v2', $changed->firstSeenAfterLabel(GroupStatusLabel::New));
        self::assertSame('v2', $changed->firstSeenAfterLabel('new'));
        self::assertNull($changed->firstSeenAfterLabel(GroupStatusLabel::Grown));
        self::assertNull($changed->firstSeenAfterLabel('unchanged'));
        self::assertNull($same->firstSeenAfterLabel(GroupStatusLabel::New));
    }

    public function testDeployMomentComesFromVersionLabel(): void
    {
        $seen = new DateTimeImmutable('2026-03-01T13:05:00Z');
        $source = new FakeLogSource([], [], ['v1' => new DateTimeImmutable('2026-03-01T00:00:01Z'), 'v2' => $seen]);

        $d = $this->resolve(['release' => 'v2', 'version_label' => 'image_tag'], null, $source);

        self::assertEquals($seen, $d->deployedAt);
        $calls = $source->callsTo('versions');
        self::assertCount(1, $calls);
        self::assertSame('image_tag', $calls[0][2]);
    }

    public function testUnknownReleaseInVersionsLeavesDeployedAtNull(): void
    {
        $source = new FakeLogSource([], [], ['v1' => new DateTimeImmutable('2026-03-01T00:00:01Z')]);

        self::assertNull($this->resolve(['release' => 'v9', 'version_label' => 'image_tag'], null, $source)->deployedAt);
    }

    public function testNoLabelOrNoReleaseMeansNoSourceCall(): void
    {
        $source = new FakeLogSource();
        $this->resolve(['release' => 'v1'], null, $source);
        $this->resolve(['release' => null, 'version_label' => 'image_tag'], null, $source);

        self::assertSame([], $source->callsTo('versions'));
    }

    public function testSourceUnavailableDoesNotCrash(): void
    {
        $source = new FakeLogSource();
        $source->failWith(new SourceUnavailable('down'));

        $d = $this->resolve(['release' => 'v2', 'version_label' => 'image_tag'], ['release' => 'v1', 'commit' => null], $source);

        self::assertNull($d->deployedAt);
        self::assertTrue($d->changedSincePrevious);
        self::assertSame('v2', $d->release);
    }
}
