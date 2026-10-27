<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use PHPUnit\Framework\TestCase;
use Watcher\State\Keys;

/** The state store holds no spend counter and no reservation (plan 017). Red until US1. */
final class NoSpendCountersTest extends TestCase
{
    public function testTheKeysClassHasNoSpendKey(): void
    {
        self::assertFalse((new \ReflectionClass(Keys::class))->hasMethod('spendUsd'));
        self::assertFalse((new \ReflectionClass(Keys::class))->hasMethod('spendTokens'));
    }

    public function testTheReservableCountersInterfaceIsGone(): void
    {
        self::assertFalse(interface_exists('Watcher\\State\\ReservableCounters'));
    }
}
