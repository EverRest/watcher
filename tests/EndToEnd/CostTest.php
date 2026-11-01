<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Budget;
use Watcher\Analyze\CompactPayload;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

/**
 * Spec 009 SC-004 and SC-005: a group that came from Sentry costs about the same input tokens as a log group, and
 * with nothing configured the payload is byte-identical to the previous release. Measured on the payload the analyzer
 * sends (estimated with the same conservative estimator the budget uses).
 */
final class CostTest extends TestCase
{
    private function group(string $fingerprint, string $template): RedactedGroup
    {
        $t = new DateTimeImmutable('2026-10-23T01:00:00Z');

        return new RedactedGroup($fingerprint, $template, Level::Error, 'Order not found', ['orders'], 42, null, GroupStatusLabel::Unchanged, [], $t, $t);
    }

    private const TEMPLATE = 'OrderService.find failed for order <num>: row not found in <table>';

    public function testSentryGroupCostsWithinFivePercentOfALogGroup(): void
    {
        $log = Budget::estimateTokens(CompactPayload::encode([$this->group('8f3a0c21b7e4d9a1', self::TEMPLATE)]));
        $sentry = Budget::estimateTokens(CompactPayload::encode([$this->group('sentry:4815162342', self::TEMPLATE)]));

        self::assertGreaterThan(0, $log);
        self::assertLessThanOrEqual(0.05, abs($sentry - $log) / $log, "log $log tokens, sentry $sentry tokens");
    }

    public function testNothingConfiguredKeepsThePayloadByteIdentical(): void
    {
        $groups = [$this->group('8f3a0c21b7e4d9a1', self::TEMPLATE)];

        self::assertSame(CompactPayload::encode($groups), CompactPayload::encode($groups, [], null, []));
    }
}
