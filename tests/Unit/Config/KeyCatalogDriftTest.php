<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\KeyCatalog;
use Watcher\Mcp\DocsIndex;

final class KeyCatalogDriftTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @return list<string> */
    private static function flatten(mixed $v, string $prefix = ''): array
    {
        if (!is_array($v) || $v === [] || array_is_list($v)) {
            return $prefix === '' ? [] : [$prefix];
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out = [...$out, ...self::flatten($x, $prefix === '' ? (string) $k : $prefix . '.' . $k)];
        }

        return $out;
    }

    /** @return array<string, array{string}> */
    public static function configs(): array
    {
        $out = [];
        foreach (['config/watcher.example.php', 'docs/examples/orders-api.loki.config.php', 'docs/examples/smoke.config.php'] as $f) {
            $out[$f] = [$f];
        }

        return $out;
    }

    /** @dataProvider configs */
    public function testEveryKeyAnExampleConfigSetsIsInTheCatalog(string $file): void
    {
        $values = (static fn (string $p): mixed => require $p)(self::ROOT . '/' . $file);
        $leaves = self::flatten($values);
        self::assertNotSame([], $leaves);
        foreach ($leaves as $path) {
            self::assertNotNull(KeyCatalog::find($path), "$file sets $path, which the catalog does not know");
        }
    }

    /** @return array<string, string> backticked key of a reference-table row => section anchor */
    private static function docRows(): array
    {
        $rows = [];
        $anchor = '';
        foreach (explode("\n", (string) file_get_contents(self::ROOT . '/docs/configuration.md')) as $l) {
            if (preg_match('/^#{2,3} (.+)/', $l, $m) === 1) {
                $anchor = DocsIndex::slug($m[1]);
            } elseif ($anchor !== '' && str_starts_with($l, '| `') && !str_starts_with($l, '| `--')) {
                preg_match_all('/`([^`]+)`/', (string) explode('|', $l)[1], $t);
                foreach ($t[1] as $k) {
                    $keys = preg_match('/^(.*)\{([a-z_,]+)\}$/', $k, $b) === 1 ? array_map(static fn (string $x): string => $b[1] . $x, explode(',', $b[2])) : [$k];
                    foreach ($keys as $key) {
                        if (preg_match('/^[a-z][a-z0-9_.<>*-]*$/', $key) === 1) {
                            $rows[$key] = $anchor;
                        }
                    }
                }
            }
        }

        return $rows;
    }

    public function testCatalogAndConfigurationDocAgreeBothWays(): void
    {
        $rows = self::docRows();
        foreach (array_keys($rows) as $k) {
            self::assertContains($k, KeyCatalog::paths(), "docs/configuration.md documents $k, the catalog does not");
        }
        foreach (KeyCatalog::paths() as $p) {
            $documented = false;
            for ($q = $p; $q !== ''; $q = (string) substr($q, 0, (int) strrpos('.' . $q, '.') - 1)) {
                $documented = $documented || isset($rows[$q]);
            }
            self::assertTrue($documented, "the catalog has $p, docs/configuration.md has no row for it or a parent");
        }
    }

    public function testDocsAnchorsResolve(): void
    {
        $anchors = [];
        foreach (explode("\n", (string) file_get_contents(self::ROOT . '/docs/configuration.md')) as $l) {
            if (preg_match('/^#{1,4} (.+)/', $l, $m) === 1) {
                $anchors[] = DocsIndex::slug($m[1]);
            }
        }
        foreach (KeyCatalog::paths() as $p) {
            self::assertContains(KeyCatalog::find($p)['docs']['anchor'] ?? null, $anchors, $p);
        }
    }

    public function testLookupRulesAndSecretFlags(): void
    {
        self::assertSame('float', KeyCatalog::find('selection.growth_factor')['type'] ?? null);
        self::assertSame('map', KeyCatalog::find('source.stream_tags.app')['type'] ?? null);
        self::assertTrue(KeyCatalog::find('analysis.api_key')['secret'] ?? false);
        self::assertNull(KeyCatalog::find('selection.min_levl'));
        $s = KeyCatalog::suggest('selection.min_levl');
        self::assertContains('selection.min_level', $s);
        self::assertLessThanOrEqual(3, count($s));
        self::assertNotContains('selection.min_levl', $s);
    }
}
