<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use InvalidArgumentException;
use JsonException;

/**
 * The canonical schema (packaged copy of specs/.../analyzer-output.schema.json) and the stripped
 * variant sent to the provider. Stripped keywords are enforced after parsing by OutputValidator.
 */
final class ProviderSchema
{
    private const UNSUPPORTED = ['maxLength', 'minLength', 'minimum', 'maximum', 'minItems', 'maxItems',
        '$schema', '$id', '$defs', 'title', 'description'];

    /** @return array<string, mixed> */
    public static function canonical(): array
    {
        try {
            $schema = json_decode((string) file_get_contents(__DIR__ . '/analyzer-output.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \LogicException('Packaged analyzer schema is corrupt', 0, $e);
        }
        assert(is_array($schema));

        return $schema;
    }

    /**
     * @param 'triage'|'analysis'|string $def
     * @param bool $advice false leaves out how_to_check / related_checks (analysis.advice.enabled)
     *
     * @return array<string, mixed>
     */
    public static function for(string $def, bool $advice = true): array
    {
        $defs = self::canonical()['$defs'] ?? [];
        if (!is_array($defs) || !isset($defs[$def]) || !is_array($defs[$def])) {
            throw new InvalidArgumentException("Unknown schema definition: $def");
        }

        $schema = self::strip($defs[$def]);
        if (!$advice) {
            unset($schema['properties']['findings']['items']['properties']['how_to_check'], $schema['properties']['findings']['items']['properties']['related_checks']);
        }

        return $schema;
    }

    /**
     * @param array<mixed> $node a schema node
     *
     * @return array<mixed>
     */
    private static function strip(array $node): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if (in_array($k, self::UNSUPPORTED, true)) {
                continue;
            }
            if ($k === 'properties' && is_array($v)) {
                // keys here are property names, not keywords: keep them, strip only each sub-schema
                $out[$k] = array_map(static fn ($p) => is_array($p) ? self::strip($p) : $p, $v);
            } elseif ($k === 'items' && is_array($v)) {
                $out[$k] = self::strip($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
