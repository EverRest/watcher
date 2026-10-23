<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use PHPUnit\Framework\TestCase;
use Watcher\State\Keys;

final class KeysTest extends TestCase
{
    public function testExactFormats(): void
    {
        self::assertSame('snap:api:2026-01-01T00%3A00%3A00Z', Keys::snapshot('api', '2026-01-01T00:00:00Z'));
        self::assertSame('snap:api:dep:db:W', Keys::dependencySnapshot('api', 'db', 'W'));
        self::assertSame('verdict:api:abc', Keys::verdict('api', 'abc'));
        self::assertSame('reported:api:abc', Keys::reported('api', 'abc'));
        self::assertSame('run:api:W', Keys::run('api', 'W'));
        self::assertSame('lock:api:W', Keys::lock('api', 'W'));
        self::assertSame('spend:api:2026-01-01', Keys::spend('api', '2026-01-01'));
        self::assertSame('spend:api:2026-01-01:usd', Keys::spendUsd('api', '2026-01-01'));
        self::assertSame('spend:api:2026-01-01:tokens', Keys::spendTokens('api', '2026-01-01'));
        self::assertSame('alert:api:2026-01-01:cost:75', Keys::alert('api', '2026-01-01', 'cost', 75));
    }

    public function testColonsInServiceCannotCollide(): void
    {
        self::assertNotSame(Keys::verdict('a:b', 'c'), Keys::verdict('a', 'b:c'));
        self::assertSame('verdict:a%3Ab:c', Keys::verdict('a:b', 'c'));
        // a service named "x:dep:y" must not look like a dependency snapshot of x
        self::assertNotSame(Keys::snapshot('x:dep:y', 'W'), Keys::dependencySnapshot('x', 'y', 'W'));
    }

    public function testSpendAndAlertKeysCannotCollideAcrossServices(): void
    {
        self::assertNotSame(Keys::spendUsd('a:2026-01-01', 'x'), Keys::spendTokens('a', '2026-01-01'));
        self::assertNotSame(Keys::alert('a', 'd', 'cost', 50), Keys::alert('a:d', 'cost', '50', 1));
    }

    public function testTtlSelection(): void
    {
        self::assertSame(2592000, Keys::ttl(Keys::SNAP, 2592000, 900));
        self::assertSame(2592000, Keys::ttl(Keys::DEP, 2592000, 900));
        self::assertSame(2592000, Keys::ttl(Keys::VERDICT, 2592000, 900));
        self::assertSame(2592000, Keys::ttl(Keys::REPORTED, 2592000, 900));
        self::assertSame(2592000, Keys::ttl(Keys::RUN, 2592000, 900));
        self::assertSame(172800, Keys::ttl(Keys::SPEND, 2592000, 900));
        self::assertSame(172800, Keys::ttl(Keys::ALERT, 2592000, 900));
        self::assertSame(900, Keys::ttl(Keys::LOCK, 2592000, 900));
    }
}
