<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use PHPUnit\Framework\TestCase;
use Watcher\Tests\EndToEnd\FleetHarness;

/**
 * A fleet-level delivery failure that echoes a fleet credential is masked, even when no service config carries it: the fleet's own
 * destination is held by SecretValue, which the host-log masking has to read back. The bot token is bare text here, so only the
 * configured-value mask can catch it (the URL and key-shape rules would not).
 */
final class FleetDescribeSecretTest extends TestCase
{
    public function testFleetBotTokenIsMaskedWhenNoServiceHoldsIt(): void
    {
        $token = implode('-', ['fixture', 'fleet', 'hook']);
        $harness = new FleetHarness(['api'], ['notify' => ['notice' => ['driver' => 'telegram', 'bot_token' => '123456:' . $token, 'chat_id' => '42']]], ['api' => ['observe' => ['log' => ['enabled' => true]], 'notify' => ['notice' => ['driver' => 'none']]]]);
        $runner = $harness->runner();
        $describe = new \ReflectionMethod($runner, 'describe');

        $text = $describe->invoke($runner, new \RuntimeException('delivery failed for 123456:' . $token));

        self::assertStringNotContainsString($token, $text);
    }
}
