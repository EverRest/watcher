<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\ProviderSchema;

final class ProviderSchemaTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../specs/001-scheduled-log-watch/contracts/analyzer-output.schema.json';

    public function testPackagedCopyEqualsSpecFile(): void
    {
        self::assertSame(
            json_decode((string) file_get_contents(self::SPEC), true),
            ProviderSchema::canonical(),
        );
    }

    /** @param mixed $node */
    private static function keys($node, string $key): bool
    {
        if (!is_array($node)) {
            return false;
        }
        foreach ($node as $k => $v) {
            if ($k === $key) {
                return true;
            }
            if (self::keys($v, $key)) {
                return true;
            }
        }

        return false;
    }

    public function testStripsUnsupportedKeywords(): void
    {
        foreach (['triage', 'analysis'] as $def) {
            $s = ProviderSchema::for($def);
            foreach (['maxLength', 'minimum', 'maximum', 'minItems', '$schema', '$id', 'description', '$defs'] as $k) {
                self::assertFalse(self::keys($s, $k), "$def still has $k");
            }
        }
    }

    public function testKeepsStructure(): void
    {
        $s = ProviderSchema::for('analysis');
        self::assertSame('object', $s['type']);
        self::assertFalse($s['additionalProperties']);
        self::assertSame(['findings'], $s['required']);
        $item = $s['properties']['findings']['items'];
        self::assertFalse($item['additionalProperties']);
        self::assertCount(8, $item['required']);
        self::assertSame(['bug', 'regression', 'anomaly', 'noise'], $item['properties']['kind']['enum']);
        self::assertSame('number', $item['properties']['confidence']['type']);
        self::assertSame('string', $item['properties']['fingerprints']['items']['type']);
        $t = ProviderSchema::for('triage')['properties']['verdicts']['items'];
        self::assertSame(['noise', 'known', 'interesting'], $t['properties']['v']['enum']);
        self::assertSame(['g', 'v'], $t['required']);
    }

    public function testAdviceFieldsAreOptionalStringsInTheProviderSchema(): void
    {
        $item = ProviderSchema::for('analysis')['properties']['findings']['items'];
        self::assertSame('string', $item['properties']['how_to_check']['type']);
        self::assertSame('array', $item['properties']['related_checks']['type']);
        self::assertSame('string', $item['properties']['related_checks']['items']['type']);
        self::assertNotContains('how_to_check', $item['required']);
        self::assertNotContains('related_checks', $item['required']);
        self::assertFalse(self::keys($item['properties']['how_to_check'], 'maxLength'));
    }

    public function testAdviceOffOmitsTheFieldsAndEqualsThePreFeatureSchema(): void
    {
        $item = ProviderSchema::for('analysis', false)['properties']['findings']['items'];
        self::assertSame(['fingerprints', 'kind', 'severity', 'title', 'evidence', 'suspected_cause', 'suggested_action', 'confidence'], array_keys($item['properties']));
    }

    public function testUnknownDefThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderSchema::for('nope');
    }
}
