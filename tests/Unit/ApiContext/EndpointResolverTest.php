<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\ApiContext\EndpointResolver;
use Watcher\ApiContext\OperationSummary;
use Watcher\ApiContext\ReducedSpec;
use Watcher\Level;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;

final class EndpointResolverTest extends TestCase
{
    private function group(string $fp, ?string $template): RedactedGroup
    {
        $t = new DateTimeImmutable('2026-10-25T06:00:00Z');

        return new RedactedGroup($fp, $template, Level::Error, null, [], 3, null, GroupStatusLabel::New, [], $t, $t);
    }

    private function spec(OperationSummary ...$ops): ReducedSpec
    {
        return new ReducedSpec('', array_values($ops), 0);
    }

    private function resolver(int $max = 5): EndpointResolver
    {
        return new EndpointResolver(new Redactor(new Normalizer()), $max);
    }

    public function testLineHasMethodTemplateSummaryTagAndDeprecation(): void
    {
        $spec = $this->spec(new OperationSummary('GET', '/orders/{id}', 'Get an order', 'orders', true, ''));
        $r = $this->resolver()->resolve([$this->group('a', 'GET /orders/<N> failed')], $spec);

        self::assertSame(['a' => 'GET /orders/{id} — Get an order [orders] (deprecated)'], $r->lines);
        self::assertSame(['a' => 'GET /orders/{id} — Get an order'], $r->model);
    }

    public function testOperationIdOnlyStandsInForAMissingSummaryAndNothingIsInvented(): void
    {
        $spec = $this->spec(
            new OperationSummary('POST', '/refunds', '', '', false, 'createRefund'),
            new OperationSummary('GET', '/health', '', '', false, ''),
        );
        $r = $this->resolver()->resolve([$this->group('a', 'POST /refunds'), $this->group('b', 'GET /health')], $spec);

        self::assertSame(['a' => 'POST /refunds — createRefund'], $r->lines, 'no summary, tag or operationId: no line');
    }

    public function testUnmatchedRouteAttachesNothingAndIsNoError(): void
    {
        $r = $this->resolver()->resolve([$this->group('a', 'GET /nope/<N>'), $this->group('b', null), $this->group('c', 'no route here')], $this->spec(new OperationSummary('GET', '/orders/{id}', 'x', '', false, '')));

        self::assertSame([], $r->lines);
        self::assertSame(0, $r->skipped);
        self::assertNull($r->unavailable);
    }

    public function testCapCountsDistinctEndpointsInPriorityOrderAndASecondGroupIsFree(): void
    {
        $ops = [];
        $groups = [];
        for ($i = 1; $i <= 8; ++$i) {
            $ops[] = new OperationSummary('GET', '/r' . $i, 'Route ' . $i, '', false, '');
            $groups[] = $this->group('g' . $i, 'GET /r' . $i);
        }
        $groups[] = $this->group('again', 'GET /r1'); // same endpoint as g1: free
        $groups[] = $this->group('late', 'GET /r8');  // already skipped: stays skipped, counted once
        $r = $this->resolver(5)->resolve($groups, $this->spec(...$ops));

        self::assertSame(['g1', 'g2', 'g3', 'g4', 'g5', 'again'], array_keys($r->lines));
        self::assertSame(3, $r->skipped, 'r6, r7, r8 once each');
    }

    public function testEveryPartIsMaskedAgainAtUse(): void
    {
        $spec = $this->spec(new OperationSummary('GET', '/users', 'Mail bob@corp.example about it', 'a@b.example', false, ''));
        $r = $this->resolver()->resolve([$this->group('a', 'GET /users')], $spec);

        self::assertStringNotContainsString('corp.example', $r->lines['a']);
        self::assertStringNotContainsString('b.example', $r->lines['a']);
        self::assertStringNotContainsString('corp.example', $r->model['a']);
    }

    public function testModelFieldIsAtMostSeventyFiveBytesAndNeverCutsMethodOrTemplate(): void
    {
        $spec = $this->spec(
            new OperationSummary('GET', '/short', str_repeat('é', 80), '', false, ''),
            new OperationSummary('GET', '/' . str_repeat('x', 80), 'Has a summary', '', false, ''),
        );
        $r = $this->resolver()->resolve([$this->group('a', 'GET /short'), $this->group('b', 'GET /' . str_repeat('x', 80))], $spec);

        self::assertLessThanOrEqual(EndpointResolver::MODEL_BYTES, strlen($r->model['a']));
        self::assertStringStartsWith('GET /short — ', $r->model['a']);
        self::assertStringEndsWith('…', $r->model['a']);
        self::assertTrue(mb_check_encoding($r->model['a'], 'UTF-8'));
        self::assertArrayNotHasKey('b', $r->model, 'method and template alone do not fit: the digest line stays, the model field is omitted');
    }

    public function testAnyRouteSaysWhetherTheSpecIsWorthReading(): void
    {
        self::assertFalse($this->resolver()->anyRoute([$this->group('a', 'timeout 3 / 5'), $this->group('b', null)]));
        self::assertTrue($this->resolver()->anyRoute([$this->group('a', 'x'), $this->group('b', 'POST /refunds/<N> failed')]));
    }

    public function testDroppedOperationsAreCarriedForTheFooter(): void
    {
        $r = $this->resolver()->resolve([], new ReducedSpec('', [], 7));

        self::assertSame(7, $r->droppedOps);
    }
}
