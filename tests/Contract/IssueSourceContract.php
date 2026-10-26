<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Issues\IssueSource;
use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;

/** Behaviours every IssueSource must have. */
abstract class IssueSourceContract extends TestCase
{
    protected const TOKENS = ['unreachable', 'unauthorized', 'timeout', 'bad_response', 'rate_limited', 'too_large', 'window_too_old'];

    protected function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
    }

    /** @param int $n unresolved issues, each with a windowed count of 5 */
    abstract protected function makeSourceWithIssues(int $n): IssueSource;

    abstract protected function makeSourceFailing(int $httpStatus): IssueSource;

    abstract protected function secret(): string;

    public function testEmptyListIsEmpty(): void
    {
        self::assertSame([], $this->makeSourceWithIssues(0)->issues($this->window(), 10));
    }

    public function testReturnsAtMostLimitRecordsWithAllowlistedFields(): void
    {
        $records = $this->makeSourceWithIssues(3)->issues($this->window(), 2);

        self::assertCount(2, $records);
        self::assertMatchesRegularExpression('/^\d+$/', $records[0]->id);
        self::assertNotSame('', $records[0]->title);
        self::assertGreaterThanOrEqual(0, $records[0]->countInWindow);
    }

    /** @return array<int|string, array{int}> */
    public static function failures(): array
    {
        return ['network' => [0], '401' => [401], '403' => [403], '429' => [429], '500' => [500], '503' => [503]];
    }

    #[DataProvider('failures')]
    public function testFailuresBecomeSourceUnavailableWithAFixedToken(int $status): void
    {
        try {
            $this->makeSourceFailing($status)->issues($this->window(), 10);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertContains($e->getMessage(), self::TOKENS);
            self::assertStringNotContainsString($this->secret(), $e->getMessage());
        }
    }
}
