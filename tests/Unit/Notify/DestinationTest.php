<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\Destination;

final class DestinationTest extends TestCase
{
    private const HOOK = 'https://hooks.example/T000/SECRET-TOKEN';

    public function testWebhookIsAvailableToTheNotifier(): void
    {
        self::assertSame(self::HOOK, (new Destination(self::HOOK))->webhook());
    }

    public function testNoDumpFormatContainsTheWebhook(): void
    {
        $d = new Destination(self::HOOK);

        $dumps = [
            print_r($d, true),
            var_export($d, true),
            (string) json_encode($d),
            (string) json_encode([$d]),
            print_r([$d], true),
        ];
        ob_start();
        var_dump($d);
        $dumps[] = (string) ob_get_clean();

        foreach ($dumps as $dump) {
            self::assertStringNotContainsString('SECRET-TOKEN', $dump);
            self::assertStringNotContainsString(self::HOOK, $dump);
        }
    }

    public function testCannotBeSerialised(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new Destination(self::HOOK));
    }

    public function testHasNoStringCast(): void
    {
        self::assertNotInstanceOf(\Stringable::class, new Destination(self::HOOK));
    }
}
