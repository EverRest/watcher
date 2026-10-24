<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use PHPUnit\Framework\TestCase;

/** In CI the service-backed tests must run, not skip: a missing service variable is a red build, not a silent green. */
final class CiServicesTest extends TestCase
{
    public function testTheRedisServiceIsConfiguredInCi(): void
    {
        if (getenv('CI') === false || getenv('CI') === '') {
            self::markTestSkipped('not a CI run');
        }
        self::assertNotEmpty(getenv('WATCHER_TEST_REDIS'), 'WATCHER_TEST_REDIS must be set in CI so the Redis store tests run');
    }
}
