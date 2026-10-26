<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Context\TraceIdCollector;
use Watcher\Level;
use Watcher\Source\LogEvent;

final class TraceIdCollectorTest extends TestCase
{
    private const ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    /** @param array<string, scalar|null> $fields */
    private function event(array $fields, string $time = '2026-10-22T10:00:00Z'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable($time), Level::Error, 'm', null, [], $fields, []);
    }

    public function testAcceptsHexCaseInsensitivelyAndStoresLowerCase(): void
    {
        $c = new TraceIdCollector();
        $c->observe($this->event(['trace_id' => strtoupper(self::ID)]), 'fp');

        self::assertSame(self::ID, $c->idFor('fp'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalid(): array
    {
        return ['15 chars' => [str_repeat('a', 15)], '33 chars' => [str_repeat('a', 33)], 'non-hex' => [str_repeat('g', 16)], 'int' => [1234567890123456], 'array' => [['a']], 'null' => [null], 'url' => ['https://x/' . self::ID]];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidIds(mixed $value): void
    {
        $c = new TraceIdCollector();
        $c->observe($this->event(['trace_id' => $value]), 'fp');

        self::assertNull($c->idFor('fp'));
    }

    public function testKeepsTheEarliestEventsIdPerFingerprint(): void
    {
        $c = new TraceIdCollector();
        $early = str_repeat('a', 16);
        $c->observe($this->event(['trace_id' => str_repeat('b', 16)], '2026-10-22T12:00:00Z'), 'fp');
        $c->observe($this->event(['trace_id' => $early], '2026-10-22T09:00:00Z'), 'fp');
        $c->observe($this->event(['trace_id' => str_repeat('c', 16)], '2026-10-22T11:00:00Z'), 'fp');
        $c->observe($this->event(['trace_id' => str_repeat('d', 16)]), 'other');

        self::assertSame($early, $c->idFor('fp'));
        self::assertSame(str_repeat('d', 16), $c->idFor('other'));
    }

    public function testAnEventWithoutAValidIdDoesNotEvictAnEarlierValidOne(): void
    {
        $c = new TraceIdCollector();
        $c->observe($this->event(['trace_id' => self::ID], '2026-10-22T10:00:00Z'), 'fp');
        $c->observe($this->event(['trace_id' => 'nope'], '2026-10-22T08:00:00Z'), 'fp');

        self::assertSame(self::ID, $c->idFor('fp'));
    }

    public function testGroupWithoutAnyIdYieldsNull(): void
    {
        self::assertNull((new TraceIdCollector())->idFor('unknown'));
    }

    public function testReadsACustomFieldName(): void
    {
        $c = new TraceIdCollector('dd.trace_id');
        $c->observe($this->event(['trace_id' => self::ID, 'dd.trace_id' => str_repeat('e', 20)]), 'fp');

        self::assertSame(str_repeat('e', 20), $c->idFor('fp'));
    }
}
