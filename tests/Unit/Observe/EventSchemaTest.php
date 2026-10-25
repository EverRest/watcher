<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Observe\EventSchema;

final class EventSchemaTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../docs/schema/watcher.event.v1.schema.json';
    private const FIXTURE = __DIR__ . '/../../Fixtures/observe/events.json';

    public function testThePublishedSchemaIsTheContractCopy(): void
    {
        self::assertFileEquals(__DIR__ . '/../../../specs/013-observability-sink/contracts/watcher.event.v1.schema.json', self::SCHEMA);
    }

    #[DataProvider('types')]
    public function testSchemaPropertiesEqualTheCodeAllowlist(string $type): void
    {
        $defs = $this->defs();
        $def = $defs[$type];
        $envelope = $defs['envelope'];

        $inSchema = array_merge(array_keys($envelope['properties']), array_keys($def['properties']));
        self::assertEqualsCanonicalizing($inSchema, EventSchema::fields($type, true), $type);

        $required = array_values(array_unique(array_merge($envelope['required'], $def['required'])));
        self::assertEqualsCanonicalizing($required, EventSchema::fields($type, false), $type . ' required');
    }

    public function testEveryTypeOfTheSchemaIsKnownToTheCode(): void
    {
        $defs = array_keys($this->defs());
        foreach (EventSchema::TYPES as $type) {
            self::assertContains($type, $defs);
        }
        self::assertCount(7, EventSchema::TYPES);
    }

    /** @param array<string, mixed> $event */
    #[DataProvider('fixtures')]
    public function testFixtureEventsValidate(string $type, array $event): void
    {
        self::assertTrue($this->valid($event), $type);
        self::assertSame(EventSchema::fields($type, true), array_keys($event), 'key order');
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalid')]
    public function testInvalidEventsFail(string $why, array $change): void
    {
        $event = $this->fixture('run');
        foreach ($change as $key => $value) {
            $event[$key] = $value;
        }
        self::assertFalse($this->valid($event), $why);
    }

    public function testAnUnknownPropertyOnEveryTypeFails(): void
    {
        foreach (self::fixtures() as $type => [, $event]) {
            $event['status'] = 'x';
            self::assertFalse($this->valid($event), $type);
        }
    }

    /** @return array<string, array{string}> */
    public static function types(): array
    {
        return array_combine(EventSchema::TYPES, array_map(static fn (string $t): array => [$t], EventSchema::TYPES));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function fixtures(): array
    {
        $out = [];
        foreach (json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) as $event) {
            $out[$event['event_type']] = [$event['event_type'], $event];
        }

        return $out;
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function invalid(): array
    {
        return [
            'offset timestamp' => ['ts', ['ts' => '2026-10-24T06:05:00+00:00']],
            'run_id too long' => ['run_id', ['run_id' => '0123456789abcdef0']],
            'status field' => ['status', ['status' => 'ok']],
            'bad outcome' => ['outcome', ['outcome' => 'invalid_config']],
            'unknown type' => ['type', ['event_type' => 'metric']],
        ];
    }

    public function testAThirteenCharacterFingerprintFails(): void
    {
        $event = $this->fixture('finding');
        $event['fingerprint'] = '0123456789abc';
        self::assertFalse($this->valid($event));
        $event = $this->fixture('group');
        $event['fingerprint'] = '0123456789abc';
        self::assertFalse($this->valid($event));
    }

    /** @return array<string, mixed> */
    private function fixture(string $type): array
    {
        return self::fixtures()[$type][1];
    }

    /** @param array<string, mixed> $event */
    private function valid(array $event): bool
    {
        $data = json_decode((string) json_encode($event), false, 512, JSON_THROW_ON_ERROR);

        return (new Validator())->validate($data, $this->schema())->isValid();
    }

    /** @return array<string, array<string, mixed>> */
    private function defs(): array
    {
        return json_decode((string) file_get_contents(self::SCHEMA), true, 512, JSON_THROW_ON_ERROR)['$defs'];
    }

    private function schema(): object
    {
        return json_decode((string) file_get_contents(self::SCHEMA), false, 512, JSON_THROW_ON_ERROR);
    }
}
